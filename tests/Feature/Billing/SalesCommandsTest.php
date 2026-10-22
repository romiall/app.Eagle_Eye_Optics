<?php

namespace Tests\Feature\Billing;

use App\Console\Commands\CreateDemoAccounts;
use App\Console\Commands\SalesDemoCommand;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Mise en service des ventes (`eeo:sales:bootstrap`) et jeu de démonstration FICTIF (`eeo:demo:sales`) : idempotents,
 * sans prix, taxe ni stock réels, démonstration refusée en production et hors du cabinet de démonstration.
 */
final class SalesCommandsTest extends TestCase
{
    use AccessFixtures;
    use MigratedDatabase;

    public function test_la_mise_en_service_exige_un_cabinet_installe(): void
    {
        $this->artisan('eeo:sales:bootstrap')->expectsOutputToContain('Aucun cabinet')->assertFailed();

        $this->assertSame(0, DB::table('cash_registers')->count());
    }

    public function test_la_mise_en_service_cree_la_caisse_et_l_emplacement_et_ne_cree_rien_d_autre(): void
    {
        $clinicId = $this->makeClinic();

        $this->artisan('eeo:sales:bootstrap')->expectsOutputToContain('Ventes prêtes')->assertSuccessful();

        $register = DB::table('cash_registers')->where('clinic_id', $clinicId)->first();
        $location = DB::table('stock_locations')->where('clinic_id', $clinicId)->first();
        $this->assertNotNull($register);
        $this->assertNotNull($location);
        $this->assertSame(['CAISSE-PRINCIPALE', 1], [$register->code, (int) $register->active]);
        $this->assertSame(['PRINCIPAL', 1], [$location->code, (int) $location->active]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'cash_register.created')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'stock_location.created')->count());
        foreach (['catalog_items', 'tax_rules', 'stock_positions', 'stock_movements', 'invoices', 'payments'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' : la mise en service ne crée ni prix, ni taxe, ni stock.');
        }
    }

    public function test_la_mise_en_service_est_idempotente(): void
    {
        $this->makeClinic();
        $this->artisan('eeo:sales:bootstrap')->assertSuccessful();

        $this->artisan('eeo:sales:bootstrap')->expectsOutputToContain('(existante)')->assertSuccessful();

        $this->assertSame(1, DB::table('cash_registers')->count());
        $this->assertSame(1, DB::table('stock_locations')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'cash_register.created')->count());
    }

    public function test_une_caisse_desactivee_n_est_pas_recreee_en_silence(): void
    {
        $this->makeClinic();
        $this->artisan('eeo:sales:bootstrap')->assertSuccessful();
        DB::table('cash_registers')->update(['active' => 0]);

        $this->artisan('eeo:sales:bootstrap')->expectsOutputToContain('désactivée')->assertFailed();

        $this->assertSame(1, DB::table('cash_registers')->count());
    }

    public function test_la_demonstration_est_refusee_en_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('eeo:demo:sales')->expectsOutputToContain('Jamais en production')->assertFailed();

        $this->assertSame(0, DB::table('catalog_items')->count());
    }

    public function test_la_demonstration_n_existe_que_dans_le_cabinet_de_demonstration(): void
    {
        $this->makeClinic('TEST CABINET REEL');

        $this->artisan('eeo:demo:sales')->expectsOutputToContain('cabinet')->assertFailed();

        $this->assertSame(0, DB::table('catalog_items')->count());
        $this->assertSame(0, DB::table('cash_registers')->count());
    }

    public function test_la_demonstration_cree_un_catalogue_fictif_sa_taxe_de_test_et_son_stock_initial(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'eeo-demo-');
        $this->assertIsString($file);

        try {
            $this->artisan('eeo:demo:accounts', ['--output' => $file])->assertSuccessful();

            $this->artisan('eeo:demo:sales')->expectsOutputToContain('DEMO-EXAMEN')->assertSuccessful();

            $this->assertStringContainsString('FICTIVES', (string) DB::table('clinics')->value('name'));
            $this->assertSame(4, DB::table('catalog_items')->count());
            $this->assertSame(
                // Ordre alphabétique des références (requête triée) : COLLYRE, EXAMEN, MONTURE, VERRES.
                [SalesDemoCommand::EYE_DROPS_REFERENCE, SalesDemoCommand::EXAM_REFERENCE, SalesDemoCommand::FRAME_REFERENCE, SalesDemoCommand::LENS_REFERENCE],
                DB::table('catalog_items')->orderBy('reference')->pluck('reference')->all(),
            );
            $this->assertSame(0, DB::table('catalog_items')->where('name', 'not like', '%fictif%')->where('name', 'not like', '%fictive%')->count(), 'Chaque libellé dit « fictif ».');

            $tax = DB::table('tax_rules')->where('code', SalesDemoCommand::TAX_CODE)->first();
            $this->assertNotNull($tax);
            $this->assertSame([1, '10.0000'], [(int) $tax->active, $tax->rate_percent]);
            $this->assertNotNull($tax->validated_by);

            $frame = DB::table('stock_positions')->where('item_id', DB::table('catalog_items')->where('reference', SalesDemoCommand::FRAME_REFERENCE)->value('id'))->first();
            $this->assertNotNull($frame);
            $this->assertSame('10.000', $frame->on_hand);
            $lot = DB::table('stock_lots')->where('batch_number', 'LOT-DEMO-1')->first();
            $this->assertNotNull($lot);
            $this->assertSame('20.000', $lot->on_hand);
            $this->assertSame(2, DB::table('stock_movements')->where('kind', 'OPENING')->count());
            $this->assertSame(1, DB::table('cash_registers')->count());
            $this->assertSame(0, DB::table('invoices')->count(), 'Aucune facture ni aucun paiement de démonstration.');
            $this->assertSame(CreateDemoAccounts::DEMO_CLINIC_NAME, DB::table('clinics')->value('name'));
        } finally {
            @unlink($file);
        }
    }

    public function test_la_demonstration_est_relancable_sans_doublon(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'eeo-demo-');
        $this->assertIsString($file);

        try {
            $this->artisan('eeo:demo:accounts', ['--output' => $file])->assertSuccessful();
            $this->artisan('eeo:demo:sales')->assertSuccessful();
            $counts = [DB::table('catalog_items')->count(), DB::table('tax_rules')->count(), DB::table('stock_movements')->count(), DB::table('stock_positions')->count(), DB::table('cash_registers')->count()];

            $this->artisan('eeo:demo:sales')->assertSuccessful();

            $this->assertSame($counts, [DB::table('catalog_items')->count(), DB::table('tax_rules')->count(), DB::table('stock_movements')->count(), DB::table('stock_positions')->count(), DB::table('cash_registers')->count()]);
        } finally {
            @unlink($file);
        }
    }

    public function test_sans_les_comptes_de_demonstration_la_demonstration_des_ventes_est_refusee(): void
    {
        $this->makeClinic(CreateDemoAccounts::DEMO_CLINIC_NAME);

        $this->artisan('eeo:demo:sales')->expectsOutputToContain('demo.gerante')->assertFailed();

        $this->assertSame(0, DB::table('catalog_items')->count());
    }
}
