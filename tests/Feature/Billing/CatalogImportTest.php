<?php

namespace Tests\Feature\Billing;

use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\BillingFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Import contrôlé du catalogue (X01 : « prévisualiser / rejeter les lignes invalides sans écriture partielle ») :
 * POST /api/v1/imports/catalog sur MySQL réel. Aperçu sans aucune écriture, mêmes règles qu'un article saisi à
 * l'écran, référence en double dans le fichier ou déjà au catalogue (casse et accents ignorés, ponctuation gardée),
 * import tout ou rien, rejeu de la même clé, réauthentification, droits et module. Données fictives.
 */
final class CatalogImportTest extends TestCase
{
    use BillingFixtures;
    use MigratedDatabase;

    private const REPORT = 'schemas/resources/billing.json#/$defs/ImportReportResponse';

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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(int $line, string $reference, array $overrides = []): array
    {
        return array_merge([
            'line' => $line, 'reference' => $reference, 'category_code' => 'FRAME', 'name' => 'Monture importée fictive', 'brand' => null,
            'model' => null, 'unit_code' => 'UNIT', 'quantity_integer_only' => true, 'sale_price' => 30000, 'purchase_cost' => null,
            'tax_rule_id' => null, 'stock_mode' => 'STOCKED', 'requires_expiry' => false, 'low_stock_threshold' => null,
            'expiry_alert_days' => null, 'active' => true, 'public_visibility' => false,
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function import(array $rows, bool $dryRun, bool $reauthenticated = true, ?string $key = null): TestResponse
    {
        $client = $reauthenticated ? $this->actingAsReauthenticated($this->manager) : $this->actingAsFresh($this->manager);

        return $client->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])
            ->postJson('/api/v1/imports/catalog', ['dry_run' => $dryRun, 'rows' => $rows]);
    }

    private function items(): int
    {
        return DB::table('catalog_items')->where('clinic_id', $this->clinicId)->count();
    }

    public function test_l_apercu_rend_le_rapport_sans_aucune_ecriture(): void
    {
        $before = $this->items();
        $audits = DB::table('audit_events')->count();

        $response = $this->import([$this->row(2, 'IMP-001'), $this->row(3, 'IMP-002', ['category_code' => 'INCONNUE'])], true)->assertOk();

        $this->assertMatchesContract($response, self::REPORT);
        $response->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.rows', 2)
            ->assertJsonPath('data.accepted', 1)
            ->assertJsonPath('data.rejected.0.line', 3)
            ->assertJsonPath('data.rejected.0.field', 'category_code')
            ->assertJsonPath('data.created_ids', []);
        $this->assertSame($before, $this->items());
        $this->assertSame($audits, DB::table('audit_events')->count(), 'un aperçu ne trace aucune écriture');
    }

    public function test_une_reference_en_double_dans_le_fichier_ou_deja_au_catalogue_est_refusee(): void
    {
        $existing = (string) DB::table('catalog_items')->where('clinic_id', $this->clinicId)->value('reference');
        $this->assertNotSame('', $existing, 'le jeu de facturation fictif a un catalogue');

        $response = $this->import([
            $this->row(2, 'IMP-ÉTÉ'),
            $this->row(3, 'imp-ete'),
            $this->row(4, mb_strtolower($existing)),
            $this->row(5, 'IMP ETE'),
        ], true)->assertOk();

        $rejected = $response->json('data.rejected');
        $this->assertSame([3, 4], array_column($rejected, 'line'), 'casse et accents ignorés, ponctuation gardée (« IMP ETE » est une autre référence)');
        $this->assertStringContainsString('ligne 2', (string) $rejected[0]['message']);
        $this->assertSame(2, $response->json('data.accepted'));
    }

    public function test_l_import_cree_toutes_les_lignes_ou_aucune(): void
    {
        $before = $this->items();

        $refused = $this->import([$this->row(2, 'IMP-A'), $this->row(3, 'IMP-B', ['sale_price' => '30000'])], false);
        $refused->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('rows.1.sale_price', $refused->json('error.fields'));
        $this->assertSame($before, $this->items(), 'une ligne refusée : rien n\'est importé');

        $created = $this->import([$this->row(2, 'IMP-A'), $this->row(3, 'IMP-B', ['category_code' => 'SERVICE', 'unit_code' => 'SERVICE',
            'quantity_integer_only' => false, 'stock_mode' => 'NONE'])], false)->assertCreated();
        $this->assertMatchesContract($created, self::REPORT);
        $created->assertJsonPath('data.dry_run', false)->assertJsonPath('data.accepted', 2);
        $this->assertSame($before + 2, $this->items());

        $ids = $created->json('data.created_ids');
        $this->assertCount(2, $ids);
        $this->assertSame(2, DB::table('audit_events')->where('action', 'catalog.item_created')->whereIn('target_id', $ids)->count());
        $this->assertSame(2, DB::table('sync_change_log')->where('entity_type', 'catalog_items')->whereIn('entity_id', $ids)->count());
    }

    public function test_la_meme_cle_rejoue_le_meme_import_sans_doublon(): void
    {
        $key = Ulid::generate();
        $first = $this->import([$this->row(2, 'IMP-REJEU')], false, key: $key)->assertCreated();
        $again = $this->import([$this->row(2, 'IMP-REJEU')], false, key: $key)->assertCreated();

        $this->assertSame($first->json('data.created_ids'), $again->json('data.created_ids'));
        $this->assertSame(1, DB::table('catalog_items')->where('clinic_id', $this->clinicId)->where('reference', 'IMP-REJEU')->count());
    }

    public function test_sans_reauthentification_recente_rien_n_est_lu_ni_ecrit(): void
    {
        $this->import([$this->row(2, 'IMP-X')], true, false)->assertStatus(423)->assertJsonPath('error.code', 'REAUTHENTICATION_REQUIRED');
        $this->import([$this->row(2, 'IMP-X')], false, false)->assertStatus(423);
        $this->assertSame(0, DB::table('catalog_items')->where('clinic_id', $this->clinicId)->where('reference', 'IMP-X')->count());
    }

    public function test_seule_la_gerante_importe_et_un_module_ferme_repond_404(): void
    {
        foreach ([$this->cashier] as $user) {
            $this->actingAsReauthenticated($user)->withHeaders(['Idempotency-Key' => Ulid::generate()])
                ->postJson('/api/v1/imports/catalog', ['dry_run' => true, 'rows' => [$this->row(2, 'IMP-Y')]])
                ->assertForbidden();
        }

        config(['eeo.enabled_modules' => ['stock']]);
        $this->import([$this->row(2, 'IMP-Y')], true)->assertNotFound();
    }

    public function test_une_requete_mal_formee_est_refusee_avant_tout_controle(): void
    {
        $this->import([], true)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['rows']]]);
        $this->actingAsReauthenticated($this->manager)->withHeaders(['Idempotency-Key' => Ulid::generate()])
            ->postJson('/api/v1/imports/catalog', ['rows' => [$this->row(2, 'IMP-Z')]])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['dry_run']]]);
    }
}
