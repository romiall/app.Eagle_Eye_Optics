<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\Approvals\ApprovalSubject;
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
 * Casse et ajustement hors vente (I02, EE028) sur MySQL réel : motif et approbation exacte obligatoires (exécution
 * directe de la gérante ou demande accordée) ; un retrait ne prend jamais la marchandise réservée pour une vente ni
 * celle confiée à un poste ; un article suivi par lots s'ajuste lot par lot ; mouvement append-only. Données fictives.
 */
final class StockMovementTest extends TestCase
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
     * @param  array<string, mixed>  $body
     */
    private function move(array $body, ?User $as = null): TestResponse
    {
        return $this->writeReauthenticated('POST', '/api/v1/stock/movements', $body, $as ?? $this->manager);
    }

    public function test_la_gerante_enregistre_une_casse_et_un_ajustement_motives(): void
    {
        $position = $this->seedPosition($this->item['frame'], '5');

        $breakage = $this->move($this->movementBody($this->item['frame'], $position, '-1'));

        $breakage->assertCreated()
            ->assertJsonPath('data.kind', 'BREAKAGE')
            ->assertJsonPath('data.signed_quantity', '-1.000');
        $this->assertMatchesContract($breakage, 'schemas/resources/stock.json#/$defs/StockMovementResponse');
        $this->assertSame('4.000', $this->onHand('stock_positions', $position));
        $approval = DB::table('approvals')->where('id', $breakage->json('data.approval_id'))->first();
        $this->assertNotNull($approval);
        $this->assertSame(['stock.adjust', 'stock_positions', $position, 'CONSUMED'], [(string) $approval->action_code, (string) $approval->target_type, (string) $approval->target_id, (string) $approval->status]);

        $this->move($this->movementBody($this->item['frame'], $position, '2', 'ADJUSTMENT'))->assertCreated();
        $this->assertSame('6.000', $this->onHand('stock_positions', $position));
        $this->assertSame(2, DB::table('stock_movements')->whereIn('kind', ['BREAKAGE', 'ADJUSTMENT'])->whereNotNull('approval_id')->count());
    }

    public function test_un_retrait_ne_prend_jamais_la_marchandise_reservee(): void
    {
        $position = $this->seedPosition($this->item['frame'], '3');
        $this->issuedInvoice([$this->line($this->item['frame'], '2', 1, 40000)], $this->manager);

        $this->move($this->movementBody($this->item['frame'], $position, '-2'))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STOCK_UNAVAILABLE')
            ->assertJsonPath('error.details.available', '1.000');
        $this->move($this->movementBody($this->item['frame'], $position, '-1', 'ADJUSTMENT'))->assertCreated();
        $this->assertSame(['2.000', '2.000'], [$this->onHand('stock_positions', $position), (string) DB::table('stock_positions')->where('id', $position)->value('reserved')]);
    }

    public function test_les_refus_de_saisie(): void
    {
        $position = $this->seedPosition($this->item['frame'], '3');
        $dropsPosition = $this->seedPosition($this->item['drops'], '2');
        $lot = $this->seedLot($dropsPosition, $this->item['drops'], 'LOT-FICTIF-1', '2027-03-31', '2');

        $this->move($this->movementBody($this->item['frame'], $position, '1'))->assertStatus(422); // casse positive
        $this->move($this->movementBody($this->item['frame'], $position, '0', 'ADJUSTMENT'))->assertStatus(422);
        $this->move($this->movementBody($this->item['frame'], $position, '-1', 'BREAKAGE', ['reason' => '']))->assertStatus(422);
        $this->move($this->movementBody($this->item['frame'], $position, '-0.5'))->assertStatus(422); // vendu à l'unité
        $this->move($this->movementBody($this->item['lens'], $position, '-1'))->assertStatus(422); // article sans stock
        $this->move($this->movementBody($this->item['drops'], $dropsPosition, '-1'))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['lot_id']]]);
        $this->move($this->movementBody($this->item['frame'], $position, '-1', 'OPENING'))->assertStatus(422);
        $this->move($this->movementBody($this->item['drops'], $dropsPosition, '-1', 'BREAKAGE', ['lot_id' => $lot]))->assertCreated();

        $this->assertSame(['1.000', '1.000'], [$this->onHand('stock_lots', $lot), $this->onHand('stock_positions', $dropsPosition)], 'La position et le lot bougent ensemble.');
        $this->assertSame('3.000', $this->onHand('stock_positions', $position));
    }

    public function test_un_compte_autorise_par_derogation_agit_sur_approbation_exacte(): void
    {
        $position = $this->seedPosition($this->item['frame'], '5');
        $this->allowPermission($this->cashier, 'stock.adjust');
        $body = $this->movementBody($this->item['frame'], $position, '-1');

        $this->move($body, $this->cashier)->assertStatus(422)->assertJsonPath('error.code', 'APPROVAL_REQUIRED_OR_EXPIRED');

        $approval = $this->approved(ApprovalSubject::stockMovement($position, $this->item['frame'], null, 'BREAKAGE', '-1')->toArray(), $this->cashier);
        $this->move(array_merge($body, ['approval_id' => $approval, 'signed_quantity' => '-2']), $this->cashier)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'APPROVAL_REQUIRED_OR_EXPIRED');
        $this->move(array_merge($body, ['approval_id' => $approval]), $this->cashier)->assertCreated();

        $this->assertSame('CONSUMED', DB::table('approvals')->where('id', $approval)->value('status'));
        $this->assertSame('4.000', $this->onHand('stock_positions', $position));
    }

    public function test_droits_reauthentification_et_immuabilite(): void
    {
        $position = $this->seedPosition($this->item['frame'], '5');

        $this->move($this->movementBody($this->item['frame'], $position, '-1'), $this->cashier)->assertForbidden();
        $this->write('POST', '/api/v1/stock/movements', $this->movementBody($this->item['frame'], $position, '-1'), $this->manager)->assertStatus(423);
        $id = (string) $this->move($this->movementBody($this->item['frame'], $position, '-1'))->assertCreated()->json('data.id');

        $this->expectException(QueryException::class);
        DB::table('stock_movements')->where('id', $id)->update(['signed_quantity' => '-2']);
    }
}
