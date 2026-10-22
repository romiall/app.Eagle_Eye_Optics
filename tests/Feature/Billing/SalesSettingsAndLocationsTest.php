<?php

namespace Tests\Feature\Billing;

use Illuminate\Support\Facades\DB;
use Tests\Support\BillingFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Lectures d'affichage pour les écrans de vente et de stock : règles de vente du compte connecté (autorité de prix,
 * plafond de remise effectif, modes à référence obligatoire) et emplacements de stock actifs. Le serveur reste seul
 * juge à chaque commande. Données fictives.
 */
final class SalesSettingsAndLocationsTest extends TestCase
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

    public function test_la_caissiere_voit_son_plafond_de_remise_et_les_modes_a_reference(): void
    {
        $response = $this->read('/api/v1/sales/settings', $this->cashier)->assertOk();

        $this->assertMatchesContract($response, 'schemas/resources/billing.json#/$defs/SalesSettingsResponse');
        $response->assertJsonPath('data.price_authority', false)
            ->assertJsonPath('data.discount_cap_percent', '0.0000')
            ->assertJsonPath('data.payment_reference_required', ['MTN_MOMO', 'ORANGE_MONEY', 'BANK_TRANSFER'])
            ->assertJsonPath('data.exam_fee_item_reference', 'EX-001');
        $this->assertContains('CASH', $response->json('data.payment_methods'));
    }

    public function test_la_gerante_a_l_autorite_de_prix(): void
    {
        $this->read('/api/v1/sales/settings', $this->manager)
            ->assertOk()
            ->assertJsonPath('data.price_authority', true)
            ->assertJsonPath('data.discount_cap_percent', '100.0000');
    }

    public function test_le_plafond_configure_s_applique_et_une_valeur_illisible_vaut_zero(): void
    {
        config(['eeo.billing.discount_cap_percent' => '10']);
        $this->read('/api/v1/sales/settings', $this->cashier)->assertOk()->assertJsonPath('data.discount_cap_percent', '10.0000');

        config(['eeo.billing.discount_cap_percent' => 'dix']);
        $this->read('/api/v1/sales/settings', $this->cashier)->assertOk()->assertJsonPath('data.discount_cap_percent', '0.0000');

        config(['eeo.billing.payment_reference_required' => ['CHEQUE', 'INCONNU']]);
        $this->read('/api/v1/sales/settings', $this->cashier)->assertOk()->assertJsonPath('data.payment_reference_required', ['CHEQUE']);
    }

    public function test_sans_droit_de_vente_les_regles_ne_sont_pas_lues(): void
    {
        $this->read('/api/v1/sales/settings', $this->clinician)->assertForbidden();
        $this->read('/api/v1/sales/settings', $this->reception)->assertForbidden();

        config(['eeo.enabled_modules' => ['stock']]);
        $this->read('/api/v1/sales/settings', $this->cashier)->assertNotFound();
    }

    public function test_les_emplacements_actifs_du_cabinet_sont_listes(): void
    {
        DB::table('stock_locations')->insert(['id' => '01J9ZXK4M2Q8R7S6T5V4W3004B', 'clinic_id' => $this->clinicId, 'code' => 'ANCIEN', 'name' => 'Emplacement désactivé', 'active' => 0]);
        $otherClinic = $this->makeClinic('TEST CABINET B');
        $this->seedLocation($otherClinic, 'AUTRE');

        $response = $this->read('/api/v1/stock/locations', $this->cashier)->assertOk();

        $this->assertMatchesContract($response, 'schemas/resources/stock.json#/$defs/StockLocationList');
        $this->assertSame([$this->locationId], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.code', 'PRINCIPAL')->assertJsonPath('data.0.active', true);
    }

    public function test_les_emplacements_exigent_la_lecture_du_stock_et_le_module(): void
    {
        $this->read('/api/v1/stock/locations', $this->reception)->assertForbidden();
        $this->read('/api/v1/stock/locations', $this->manager)->assertOk();

        config(['eeo.enabled_modules' => ['sales']]);
        $this->read('/api/v1/stock/locations', $this->manager)->assertNotFound();
    }
}
