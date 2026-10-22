<?php

namespace Tests\Feature\Billing;

use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Disponibilités de stock (I00) : physique, réservé, quotas locaux et disponible central par position, lots par
 * expiration la plus proche. Lecture seule, limitée au cabinet, sans coût d'achat ni valorisation. Données fictives.
 */
final class StockAvailabilityTest extends TestCase
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

    public function test_la_disponibilite_distingue_physique_reserve_quota_et_disponible_central(): void
    {
        $position = $this->seedPosition($this->item['frame'], '10', '3', '2');

        $response = $this->read('/api/v1/stock/availability', $this->cashier)->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_id', $this->item['frame'])
            ->assertJsonPath('data.0.location_id', $this->locationId)
            ->assertJsonPath('data.0.stock_position_id', $position)
            ->assertJsonPath('data.0.on_hand', '10.000')
            ->assertJsonPath('data.0.reserved', '3.000')
            ->assertJsonPath('data.0.offline_allocated', '2.000')
            ->assertJsonPath('data.0.central_available', '5.000')
            ->assertJsonPath('data.0.lots', [])
            ->assertJsonPath('meta.total', 1);
        $this->assertMatchesContract($response, 'schemas/resources/stock.json#/$defs/StockAvailabilityPage');
    }

    public function test_les_lots_sont_listes_par_expiration_la_plus_proche_puis_sans_date(): void
    {
        $position = $this->seedPosition($this->item['drops'], '12');
        $far = $this->seedLot($position, $this->item['drops'], 'LOT-LOIN', '2027-06-01', '4');
        $near = $this->seedLot($position, $this->item['drops'], 'LOT-PROCHE', '2026-12-01', '4', 'SELLABLE', '1');
        $quarantine = $this->seedLot($position, $this->item['drops'], 'LOT-Q', '2027-01-01', '4', 'QUARANTINE');

        $response = $this->read('/api/v1/stock/availability?item_id='.$this->item['drops'], $this->clinician)->assertOk();

        $this->assertSame([$near, $quarantine, $far], array_column($response->json('data.0.lots'), 'lot_id'));
        $response->assertJsonPath('data.0.lots.0.reserved', '1.000')->assertJsonPath('data.0.lots.1.status', 'QUARANTINE')->assertJsonPath('data.0.lots.2.expires_on', '2027-06-01');
        $this->assertMatchesContract($response, 'schemas/resources/stock.json#/$defs/StockAvailabilityPage');
    }

    public function test_les_filtres_article_et_emplacement_restreignent_la_liste(): void
    {
        $this->seedPosition($this->item['frame'], '5');
        $this->seedPosition($this->item['drops'], '5');
        $other = $this->seedLocation($this->clinicId, 'SECONDAIRE');
        DB::table('stock_positions')->insert([
            'id' => Ulid::generate(), 'clinic_id' => $this->clinicId, 'item_id' => $this->item['frame'], 'location_id' => $other,
            'on_hand' => '1', 'reserved' => '0', 'offline_allocated' => '0', 'version' => 1,
        ]);

        $this->read('/api/v1/stock/availability', $this->cashier)->assertJsonPath('meta.total', 3);
        $this->read('/api/v1/stock/availability?item_id='.$this->item['frame'], $this->cashier)->assertJsonPath('meta.total', 2);
        $this->read('/api/v1/stock/availability?location_id='.$other, $this->cashier)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.on_hand', '1.000');
        $this->read('/api/v1/stock/availability?item_id='.$this->item['drops'].'&location_id='.$other, $this->cashier)->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
    }

    public function test_un_filtre_ou_une_page_invalide_est_un_refus_de_validation(): void
    {
        $this->read('/api/v1/stock/availability?item_id=pas-un-ulid', $this->cashier)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['item_id']]]);
        $this->read('/api/v1/stock/availability?page=0', $this->cashier)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['page']]]);
        $this->read('/api/v1/stock/availability?page=abc', $this->cashier)->assertStatus(422);
    }

    public function test_la_liste_est_paginee_par_vingt_cinq(): void
    {
        for ($index = 0; $index < 27; $index++) {
            $item = $this->seedItem($this->clinicId, ['stock_mode' => 'STOCKED']);
            $this->seedPosition($item, '1');
        }

        $first = $this->read('/api/v1/stock/availability', $this->cashier)->assertOk();
        $second = $this->read('/api/v1/stock/availability?page=2', $this->cashier)->assertOk();

        $first->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 27)->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.per_page', 25);
        $second->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2);
    }

    public function test_un_autre_cabinet_ne_voit_pas_ce_stock(): void
    {
        $this->seedPosition($this->item['frame'], '5');
        $other = $this->makeClinic('TEST CABINET B');
        $manager = $this->makeStaff($other, 'MANAGER', 'gerante.b');

        $this->read('/api/v1/stock/availability', $manager)->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
    }

    public function test_aucun_cout_d_achat_ni_valorisation_n_est_expose(): void
    {
        $position = $this->seedPosition($this->item['drops'], '4');
        $lot = $this->seedLot($position, $this->item['drops'], 'LOT-A', '2027-06-01', '4');
        DB::table('stock_lots')->where('id', $lot)->update(['unit_cost' => 18137]);
        DB::table('catalog_items')->where('id', $this->item['drops'])->update(['purchase_cost' => 12345]);

        $body = (string) $this->read('/api/v1/stock/availability', $this->cashier)->assertOk()->getContent();

        $this->assertStringNotContainsString('unit_cost', $body);
        $this->assertStringNotContainsString('purchase_cost', $body);
        $this->assertStringNotContainsString('18137', $body);
        $this->assertStringNotContainsString('12345', $body);
    }

    public function test_seuls_les_comptes_avec_la_lecture_du_stock_y_accedent(): void
    {
        $this->read('/api/v1/stock/availability', $this->reception)->assertForbidden();
        foreach ([$this->cashier, $this->clinician, $this->manager] as $user) {
            $this->read('/api/v1/stock/availability', $user)->assertOk();
        }
    }

    public function test_un_module_ferme_repond_404(): void
    {
        config(['eeo.enabled_modules' => ['sales']]);

        $this->read('/api/v1/stock/availability', $this->manager)->assertNotFound();
    }

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        $this->getJson('/api/v1/stock/availability')->assertUnauthorized();
    }
}
