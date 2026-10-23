<?php

namespace Tests\Feature\Billing;

use App\Domain\Shared\Ulid;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApprovalFixtures;
use Tests\Support\BillingFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Retour physique d'un article remis (I02, EE027) sur MySQL réel : cumul plafonné par la quantité remise ; seul un
 * article vendable accepté retourne dans le stock vendable (mouvement PATIENT_RETURN dans la position et le lot
 * d'origine) ; un article abîmé est tracé sans mouvement ; aucun avoir n'est créé ; contrôle par la personne qui
 * enregistre (gérante, réauthentifiée). Données fictives.
 */
final class PhysicalReturnTest extends TestCase
{
    use ApprovalFixtures;
    use BillingFixtures;
    use DeliveryFixtures;
    use MigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBilling(['sales', 'stock', 'settings']);
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    /**
     * Monture remise (2 facturées, 2 remises) : [remise, ligne de remise, position].
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function delivered(string $quantity = '2'): array
    {
        $position = $this->seedPosition($this->item['frame'], '5');
        [$invoice] = $this->settledInvoice([$this->line($this->item['frame'], $quantity, 1, 40000)]);
        $response = $this->deliver($invoice, [$this->deliveryLine($this->invoiceLine($invoice), $this->item['frame'], $quantity)])->assertCreated();

        return [(string) $response->json('data.id'), (string) $response->json('data.lines.0.id'), $position, $invoice];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function giveBack(string $deliveryId, array $body, ?User $as = null): TestResponse
    {
        return $this->writeReauthenticated('POST', '/api/v1/deliveries/'.$deliveryId.'/physical-returns', $body, $as ?? $this->manager);
    }

    public function test_un_article_vendable_retourne_en_stock_sans_creer_d_avoir(): void
    {
        [$delivery, $line, $position, $invoice] = $this->delivered();
        $this->assertSame('3.000', $this->onHand('stock_positions', $position));
        $ledgerBefore = $this->ledgerVersion($invoice);

        $response = $this->giveBack($delivery, $this->returnBody($line, $this->item['frame'], '1', $this->manager));

        $response->assertCreated()
            ->assertJsonPath('data.accepted_for_restock', true)
            ->assertJsonPath('data.condition_code', 'SELLABLE')
            ->assertJsonPath('data.credit_note_id', null);
        $this->assertMatchesContract($response, 'schemas/resources/stock.json#/$defs/PhysicalReturnResponse');
        $this->assertSame('4.000', $this->onHand('stock_positions', $position));
        $movement = DB::table('stock_movements')->where('kind', 'PATIENT_RETURN')->first();
        $this->assertNotNull($movement);
        $this->assertSame(['1.000', $response->json('data.id'), $position], [(string) $movement->signed_quantity, $movement->physical_return_id, $movement->position_id]);
        $this->assertSame(0, DB::table('credit_notes')->count(), 'Un retour physique ne crée jamais d\'avoir.');
        $this->assertSame($ledgerBefore, $this->ledgerVersion($invoice), 'Le solde de la facture ne change pas.');

        $view = $this->read('/api/v1/invoices/'.$invoice.'/deliveries', $this->manager)->assertOk();
        $this->assertMatchesContract($view, 'schemas/resources/billing.json#/$defs/InvoiceDeliveriesResponse');
        $view->assertJsonPath('data.lines.0.returned', '1.000')
            ->assertJsonPath('data.deliveries.0.lines.0.returnable', '1.000')
            ->assertJsonPath('data.returns.0.stock_movement_id', $movement->id);
    }

    public function test_un_article_abime_est_trace_sans_entrer_en_stock(): void
    {
        [$delivery, $line, $position] = $this->delivered();

        $this->giveBack($delivery, $this->returnBody($line, $this->item['frame'], '1', $this->manager, [
            'condition_code' => 'DAMAGED', 'accepted_for_restock' => false, 'stock_movement_id' => null,
        ]))->assertCreated()->assertJsonPath('data.stock_movement_id', null);

        $this->assertSame('3.000', $this->onHand('stock_positions', $position));
        $this->assertSame(0, DB::table('stock_movements')->where('kind', 'PATIENT_RETURN')->count());
        $this->assertSame(1, DB::table('physical_returns')->count());
    }

    public function test_le_cumul_des_retours_ne_depasse_jamais_la_quantite_remise(): void
    {
        [$delivery, $line] = $this->delivered();

        $this->giveBack($delivery, $this->returnBody($line, $this->item['frame'], '3', $this->manager))
            ->assertStatus(422)
            ->assertJsonPath('error.details.returnable', '2.000');
        $this->giveBack($delivery, $this->returnBody($line, $this->item['frame'], '2', $this->manager))->assertCreated();
        $this->giveBack($delivery, $this->returnBody($line, $this->item['frame'], '1', $this->manager))
            ->assertStatus(422)
            ->assertJsonPath('error.details.returnable', '0.000');
    }

    public function test_les_refus_de_saisie(): void
    {
        [$delivery, $line] = $this->delivered();

        // Un article abîmé ne retourne pas en stock vendable.
        $this->giveBack($delivery, $this->returnBody($line, $this->item['frame'], '1', $this->manager, ['condition_code' => 'DAMAGED']))->assertStatus(422);
        // Le contrôle est fait par la personne qui enregistre.
        $this->giveBack($delivery, $this->returnBody($line, $this->item['frame'], '1', $this->cashier))->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['approved_by']]]);
        // Aucun mouvement pour un retour non remis en stock.
        $this->giveBack($delivery, $this->returnBody($line, $this->item['frame'], '1', $this->manager, ['condition_code' => 'OTHER', 'accepted_for_restock' => false]))->assertStatus(422);
        // Ligne d'une autre remise que celle de l'adresse.
        $this->giveBack(Ulid::generate(), $this->returnBody($line, $this->item['frame'], '1', $this->manager))->assertStatus(422);
        // Motif obligatoire, article de la ligne.
        $this->giveBack($delivery, $this->returnBody($line, $this->item['frame'], '1', $this->manager, ['reason' => '']))->assertStatus(422);
        $this->giveBack($delivery, $this->returnBody($line, $this->item['drops'], '1', $this->manager))->assertStatus(422);
        // Avoir d'une autre facture : refusé.
        $this->giveBack($delivery, $this->returnBody($line, $this->item['frame'], '1', $this->manager, ['credit_note_id' => Ulid::generate()]))->assertStatus(422);

        $this->assertSame(0, DB::table('physical_returns')->count());
    }

    public function test_un_avoir_emis_de_la_meme_facture_peut_etre_relie(): void
    {
        [$delivery, $line, , $invoice] = $this->delivered();
        $note = (string) $this->writeReauthenticated('POST', '/api/v1/invoices/'.$invoice.'/credit-notes',
            $this->creditNoteBody($invoice, $this->ledgerVersion($invoice), [$this->creditLine($this->invoiceLine($invoice), 40000, '1')]), $this->manager)
            ->assertCreated()->json('data.credit_note_id');

        $this->giveBack($delivery, $this->returnBody($line, $this->item['frame'], '1', $this->manager, ['credit_note_id' => $note]))
            ->assertCreated()
            ->assertJsonPath('data.credit_note_id', $note);
        $this->assertSame(1, DB::table('credit_notes')->count(), 'Le retour relie l\'avoir sans en créer un autre.');
    }

    public function test_droits_et_reauthentification(): void
    {
        [$delivery, $line] = $this->delivered();

        $this->giveBack($delivery, $this->returnBody($line, $this->item['frame'], '1', $this->cashier), $this->cashier)->assertForbidden();
        $this->write('POST', '/api/v1/deliveries/'.$delivery.'/physical-returns', $this->returnBody($line, $this->item['frame'], '1', $this->manager), $this->manager)
            ->assertStatus(423);
    }

    public function test_un_retour_est_immuable(): void
    {
        [$delivery, $line] = $this->delivered();
        $id = (string) $this->giveBack($delivery, $this->returnBody($line, $this->item['frame'], '1', $this->manager))->assertCreated()->json('data.id');

        $this->expectException(QueryException::class);
        DB::table('physical_returns')->where('id', $id)->update(['quantity' => '2']);
    }
}
