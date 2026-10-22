<?php

namespace Tests\Feature\Billing;

use App\Application\Checkpoint;
use App\Application\CommandBusProbe;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BillingFixtures;
use Tests\Support\Commands\RecordingProbe;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Stock initial validé (I00, EE025) : un mouvement OPENING par ligne, positions et lots créés dans la même transaction,
 * approbation directe de la gérante, coût inconnu conservé inconnu, un seul stock initial par article et emplacement.
 * Réservé à la gérante réauthentifiée, en ligne seulement. Comptage fictif.
 */
final class OpeningStockTest extends TestCase
{
    use BillingFixtures;
    use MigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBilling();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>|null  $lot
     * @return array<string, mixed>
     */
    private function openingLine(string $itemId, string $quantity, ?array $lot = null, ?int $cost = null, ?string $locationId = null): array
    {
        return ['movement_id' => Ulid::generate(), 'item_id' => $itemId, 'location_id' => $locationId ?? $this->locationId, 'lot' => $lot, 'quantity' => $quantity, 'unit_cost' => $cost];
    }

    /**
     * @return array{lot_id: string, batch_number: string, expires_on: ?string}
     */
    private function lotOf(string $batch, ?string $expires): array
    {
        return ['lot_id' => Ulid::generate(), 'batch_number' => $batch, 'expires_on' => $expires];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function openingBody(array $lines, array $overrides = []): array
    {
        return array_merge(['counted_at' => '2026-10-22T08:00:00.000Z', 'reason' => 'Comptage initial fictif', 'approval_id' => null, 'lines' => $lines], $overrides);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function opening(array $body, bool $reauthenticated = true, ?string $key = null, ?\App\Models\User $as = null): TestResponse
    {
        $user = $as ?? $this->manager;
        $client = $reauthenticated ? $this->actingAsReauthenticated($user) : $this->actingAsFresh($user);

        return $client->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])->postJson('/api/v1/stock/opening', $body);
    }

    /**
     * Empreinte de ce que le stock initial écrit : pour prouver un retour arrière.
     *
     * @return array<string, int>
     */
    private function stockEffects(): array
    {
        return [
            'positions' => DB::table('stock_positions')->count(),
            'lots' => DB::table('stock_lots')->count(),
            'movements' => DB::table('stock_movements')->count(),
            'approvals' => DB::table('approvals')->count(),
            'audit' => DB::table('audit_events')->count(),
            'changes' => DB::table('sync_change_log')->count(),
            'idempotency' => DB::table('idempotency_requests')->count(),
        ];
    }

    public function test_le_stock_initial_cree_positions_lots_mouvements_et_approbation_directe(): void
    {
        $body = $this->openingBody([
            $this->openingLine($this->item['frame'], '5', null, 18000),
            $this->openingLine($this->item['drops'], '10', $this->lotOf('LOT-1', '2027-03-01'), null),
        ]);

        $response = $this->opening($body);

        $response->assertCreated()->assertJsonCount(2, 'data.movements')
            ->assertJsonPath('data.movements.0.kind', 'OPENING')
            ->assertJsonPath('data.movements.0.signed_quantity', '5.000')
            ->assertJsonPath('data.movements.0.reason', 'Comptage initial fictif')
            ->assertJsonPath('data.movements.1.signed_quantity', '10.000');
        $this->assertMatchesContract($response, 'schemas/resources/stock.json#/$defs/OpeningStockResponse');

        $frame = DB::table('stock_positions')->where('item_id', $this->item['frame'])->first();
        $drops = DB::table('stock_positions')->where('item_id', $this->item['drops'])->first();
        $this->assertNotNull($frame);
        $this->assertNotNull($drops);
        $this->assertSame(['5.000', '0.000', '0.000', '5.000', 2], [$frame->on_hand, $frame->reserved, $frame->offline_allocated, $frame->central_available, (int) $frame->version]);
        $this->assertSame('10.000', $drops->on_hand);
        $lot = DB::table('stock_lots')->where('item_id', $this->item['drops'])->first();
        $this->assertNotNull($lot);
        $this->assertSame(['LOT-1', '2027-03-01', 'SELLABLE', '10.000', $drops->id], [$lot->batch_number, $lot->expires_on, $lot->status, $lot->on_hand, $lot->stock_position_id]);

        $movements = DB::table('stock_movements')->orderBy('movement_position')->get();
        $this->assertCount(2, $movements);
        $this->assertSame([18000, null], [$movements[0]->unit_cost === null ? null : (int) $movements[0]->unit_cost, $movements[1]->unit_cost === null ? null : (int) $movements[1]->unit_cost], 'Un coût inconnu reste inconnu, jamais zéro.');
        $this->assertSame([$lot->id], array_values(array_filter([$movements[0]->lot_id, $movements[1]->lot_id])));

        $approval = DB::table('approvals')->where('id', $movements[0]->approval_id)->first();
        $this->assertNotNull($approval);
        $this->assertSame(['stock.adjust', 'CONSUMED', $this->manager->id, $this->manager->id, 'Comptage initial fictif'], [$approval->action_code, $approval->status, $approval->requested_by, $approval->decided_by, $approval->reason]);
        $this->assertSame($movements[0]->approval_id, $movements[1]->approval_id, 'Une seule autorisation couvre le comptage entier.');

        $this->assertSame(1, DB::table('audit_events')->where('action', 'stock.opening_registered')->count());
        $this->assertSame(2, DB::table('sync_change_log')->where('entity_type', 'stock_positions')->count());
        $dump = $this->sideEffectsDump();
        $this->assertStringNotContainsString('unit_cost', $dump, 'Aucun coût d\'achat dans l\'audit, l\'outbox ni le journal des changements.');
        $this->assertStringNotContainsString('purchase_cost', $dump);
    }

    public function test_seule_la_gerante_reauthentifiee_enregistre_le_stock_initial(): void
    {
        $body = $this->openingBody([$this->openingLine($this->item['frame'], '5')]);

        $this->opening($body, false)->assertStatus(423)->assertJsonPath('error.code', 'REAUTHENTICATION_REQUIRED');
        $this->opening($body, true, null, $this->cashier)->assertForbidden();
        $this->opening($body, true, null, $this->clinician)->assertForbidden();
        $this->opening($body, true, null, $this->reception)->assertForbidden();

        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('stock_positions')->count());
    }

    public function test_un_compte_qui_a_la_permission_sans_etre_gerante_est_refuse(): void
    {
        $this->allowPermission($this->cashier, 'stock.adjust');

        $this->opening($this->openingBody([$this->openingLine($this->item['frame'], '5')]), true, null, $this->cashier)
            ->assertForbidden();
        $this->assertSame(0, DB::table('approvals')->count());
    }

    public function test_un_article_perissable_exige_un_lot_date(): void
    {
        $this->opening($this->openingBody([$this->openingLine($this->item['drops'], '10')]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['lines.0.lot']]]);
        $this->opening($this->openingBody([$this->openingLine($this->item['drops'], '10', $this->lotOf('LOT-1', null))]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['lines.0.lot.expires_on']]]);

        $this->assertSame(0, DB::table('stock_lots')->count());
    }

    public function test_seuls_les_articles_actifs_geres_en_stock_recoivent_un_stock_initial(): void
    {
        $this->opening($this->openingBody([$this->openingLine($this->item['exam'], '1'), $this->openingLine($this->item['lens'], '1')]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['lines.0.item_id', 'lines.1.item_id']]]);

        DB::table('catalog_items')->where('id', $this->item['frame'])->update(['active' => 0]);
        $this->opening($this->openingBody([$this->openingLine($this->item['frame'], '1')]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['lines.0.item_id']]]);
        $this->opening($this->openingBody([$this->openingLine(Ulid::generate(), '1')]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['lines.0.item_id']]]);

        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_un_emplacement_inconnu_ou_desactive_est_refuse(): void
    {
        $this->opening($this->openingBody([$this->openingLine($this->item['frame'], '1', null, null, Ulid::generate())]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['lines.0.location_id']]]);

        DB::table('stock_locations')->where('id', $this->locationId)->update(['active' => 0]);
        $this->opening($this->openingBody([$this->openingLine($this->item['frame'], '1')]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['lines.0.location_id']]]);
    }

    public function test_une_quantite_nulle_decimale_sur_article_entier_ou_negative_est_refusee(): void
    {
        foreach (['0', '1.5', '-2'] as $quantity) {
            $this->opening($this->openingBody([$this->openingLine($this->item['frame'], $quantity)]))
                ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_le_stock_initial_d_un_article_ne_s_enregistre_qu_une_fois_par_emplacement(): void
    {
        $this->opening($this->openingBody([$this->openingLine($this->item['frame'], '5')]))->assertCreated();

        $this->opening($this->openingBody([$this->openingLine($this->item['frame'], '3')]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['lines.0.item_id']]]);

        $this->assertSame('5.000', DB::table('stock_positions')->where('item_id', $this->item['frame'])->value('on_hand'));
        $this->assertSame(1, DB::table('stock_movements')->count());
    }

    public function test_un_numero_de_lot_en_double_dans_le_comptage_est_refuse(): void
    {
        $this->opening($this->openingBody([
            $this->openingLine($this->item['drops'], '5', $this->lotOf('LOT-1', '2027-03-01')),
            $this->openingLine($this->item['drops'], '5', $this->lotOf('LOT-1', '2027-06-01')),
        ]))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['lines.1.lot.batch_number']]]);

        $this->assertSame(0, DB::table('stock_lots')->count());
    }

    public function test_deux_lots_d_un_meme_article_alimentent_une_seule_position(): void
    {
        $this->opening($this->openingBody([
            $this->openingLine($this->item['drops'], '5', $this->lotOf('LOT-1', '2027-03-01')),
            $this->openingLine($this->item['drops'], '7', $this->lotOf('LOT-2', '2027-06-01')),
        ]))->assertCreated();

        $this->assertSame(1, DB::table('stock_positions')->count());
        $this->assertSame('12.000', DB::table('stock_positions')->value('on_hand'));
        $this->assertSame(2, DB::table('stock_lots')->count());
        $this->assertSame([1, 2], DB::table('stock_movements')->orderBy('movement_position')->pluck('movement_position')->map(static fn ($value): int => (int) $value)->all());
    }

    public function test_une_approbation_a_distance_n_est_pas_encore_ouverte(): void
    {
        $this->opening($this->openingBody([$this->openingLine($this->item['frame'], '5')], ['approval_id' => Ulid::generate()]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['approval_id']]]);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_la_date_du_comptage_ne_peut_pas_etre_dans_le_futur(): void
    {
        $this->opening($this->openingBody([$this->openingLine($this->item['frame'], '5')], ['counted_at' => '2026-10-23T09:30:00.000Z']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['counted_at']]]);
    }

    public function test_un_rejeu_de_la_meme_cle_rend_les_memes_mouvements_sans_les_doubler(): void
    {
        $body = $this->openingBody([$this->openingLine($this->item['frame'], '5')]);
        $key = Ulid::generate();

        $first = $this->opening($body, true, $key)->assertCreated();
        $replay = $this->opening($body, true, $key)->assertCreated();

        $this->assertSame($first->json('data.movements.0.id'), $replay->json('data.movements.0.id'));
        $this->assertSame(1, DB::table('stock_movements')->count());
        $this->assertSame('5.000', DB::table('stock_positions')->value('on_hand'));
    }

    public function test_un_module_ferme_repond_404(): void
    {
        config(['eeo.enabled_modules' => ['sales']]);

        $this->opening($this->openingBody([$this->openingLine($this->item['frame'], '5')]))->assertNotFound();
    }

    /**
     * @return array<string, array{0: Checkpoint}>
     */
    public static function checkpoints(): array
    {
        $cases = [];
        foreach (Checkpoint::cases() as $checkpoint) {
            $cases[$checkpoint->value] = [$checkpoint];
        }

        return $cases;
    }

    #[DataProvider('checkpoints')]
    public function test_un_echec_a_n_importe_quel_point_annule_positions_lots_mouvements_et_approbation(Checkpoint $failAt): void
    {
        $body = $this->openingBody([
            $this->openingLine($this->item['frame'], '5'),
            $this->openingLine($this->item['drops'], '10', $this->lotOf('LOT-1', '2027-03-01')),
        ]);
        $before = $this->stockEffects();
        $this->app->instance(CommandBusProbe::class, new RecordingProbe($failAt));
        $this->withoutExceptionHandling();

        try {
            $this->opening($body);
            $this->fail('Une erreur injectée devait annuler la commande.');
        } catch (RuntimeException) {
            // Attendu : la transaction est annulée en entier.
        }

        $this->assertSame($before, $this->stockEffects());

        $this->app->instance(CommandBusProbe::class, new RecordingProbe);
        $this->opening($body)->assertCreated();
        $this->assertSame(2, DB::table('stock_movements')->count());
    }
}
