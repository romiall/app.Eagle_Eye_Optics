<?php

namespace Tests\Feature\Billing;

use App\Application\Checkpoint;
use App\Application\CommandBusProbe;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BillingFixtures;
use Tests\Support\Commands\RecordingProbe;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Catalogue du cabinet (B01, EE020, EE027, AT07, AT15) sur MySQL réel : création et modification par la gérante, droits,
 * coût d'achat réservé, recherche à deux caractères, articles stockés / services / sur mesure distincts, unité
 * indivisible, version attendue, idempotence, retour arrière, module fermé. Aucun prix réel : données fictives.
 */
final class CatalogTest extends TestCase
{
    use BillingFixtures;
    use MigratedDatabase;

    private const COST = 7391842;

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
    private function itemBody(array $overrides = []): array
    {
        return array_merge([
            'expected_version' => null, 'reference' => 'FR-NEW', 'category_code' => 'FRAME', 'name' => 'Monture fictive neuve', 'brand' => null, 'model' => null,
            'unit_code' => 'UNIT', 'quantity_integer_only' => true, 'sale_price' => 40000, 'purchase_cost' => self::COST, 'tax_rule_id' => null,
            'stock_mode' => 'STOCKED', 'requires_expiry' => false, 'low_stock_threshold' => null, 'expiry_alert_days' => null,
            'active' => true, 'public_visibility' => false,
        ], $overrides);
    }

    public function test_la_gerante_cree_un_article_et_la_reponse_suit_le_contrat(): void
    {
        $body = $this->itemBody();

        $response = $this->write('POST', '/api/v1/catalog/items', $body, $this->manager);

        $response->assertCreated()
            ->assertJsonPath('data.reference', 'FR-NEW')
            ->assertJsonPath('data.sale_price', 40000)
            ->assertJsonPath('data.purchase_cost', self::COST)
            ->assertJsonPath('data.stock_mode', 'STOCKED')
            ->assertJsonPath('data.version', 1);
        $this->assertMatchesContract($response, 'schemas/resources/billing.json#/$defs/CatalogItemResponse');

        $row = DB::table('catalog_items')->where('clinic_id', $this->clinicId)->where('reference', 'FR-NEW')->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->version);
        $this->assertSame(self::COST, (int) $row->purchase_cost);

        $audit = DB::table('audit_events')->where('action', 'catalog.item_created')->where('target_id', $row->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->manager->id, $audit->actor_id);
        $this->assertStringContainsString('40000', (string) $audit->sanitized_diff, 'Le prix de vente est tracé.');
        $this->assertStringNotContainsString((string) self::COST, $this->sideEffectsDump(), 'Le coût d\'achat ne figure ni dans l\'audit, ni dans l\'outbox, ni dans le résultat idempotent.');
        $this->assertSame(1, DB::table('sync_change_log')->where('entity_type', 'catalog_items')->where('entity_id', $row->id)->count());
    }

    public function test_articles_stockes_services_et_sur_mesure_sont_trois_modes_distincts(): void
    {
        foreach ([
            ['SV-1', 'SERVICE', 'SERVICE', false, 'NONE'],
            ['ST-1', 'FRAME', 'UNIT', true, 'STOCKED'],
            ['MO-1', 'LENS', 'PAIR', true, 'MADE_TO_ORDER'],
        ] as [$reference, $category, $unit, $integer, $mode]) {
            $this->write('POST', '/api/v1/catalog/items', $this->itemBody([
                'reference' => $reference, 'category_code' => $category, 'unit_code' => $unit, 'quantity_integer_only' => $integer, 'stock_mode' => $mode,
            ]), $this->manager)->assertCreated()->assertJsonPath('data.stock_mode', $mode);
        }

        $this->assertSame(['MADE_TO_ORDER', 'NONE', 'STOCKED'], DB::table('catalog_items')->where('clinic_id', $this->clinicId)->whereIn('reference', ['SV-1', 'ST-1', 'MO-1'])->orderBy('stock_mode')->pluck('stock_mode')->all());
    }

    public function test_une_paire_n_est_pas_transformee_en_deux_unites(): void
    {
        $response = $this->write('POST', '/api/v1/catalog/items', $this->itemBody(['reference' => 'PA-1', 'unit_code' => 'PAIR', 'quantity_integer_only' => false]), $this->manager);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['fields' => ['quantity_integer_only']]]);
        $this->assertMatchesContract($response, 'schemas/error.json');
        $this->assertSame(0, DB::table('catalog_items')->where('reference', 'PA-1')->count());
    }

    public function test_un_collyre_sans_peremption_et_une_prestation_stockee_sont_refuses(): void
    {
        $this->write('POST', '/api/v1/catalog/items', $this->itemBody(['reference' => 'CO-2', 'category_code' => 'COLLYRE', 'requires_expiry' => false]), $this->manager)
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['requires_expiry']]]);
        $this->write('POST', '/api/v1/catalog/items', $this->itemBody(['reference' => 'SV-2', 'category_code' => 'SERVICE', 'unit_code' => 'SERVICE', 'quantity_integer_only' => false, 'stock_mode' => 'STOCKED']), $this->manager)
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['stock_mode']]]);

        $this->assertSame(0, DB::table('catalog_items')->whereIn('reference', ['CO-2', 'SV-2'])->count());
    }

    public function test_la_reference_est_unique_sans_tenir_compte_de_la_casse(): void
    {
        $this->write('POST', '/api/v1/catalog/items', $this->itemBody(['reference' => 'fr-001']), $this->manager)
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['reference']]]);

        $this->assertSame(1, DB::table('catalog_items')->where('clinic_id', $this->clinicId)->where('reference', 'FR-001')->count());
    }

    public function test_aucune_taxe_ni_prix_n_est_preconfigure(): void
    {
        $other = $this->makeClinic('TEST CABINET VIDE');
        $manager = $this->makeStaff($other, 'MANAGER', 'gerante.vide');

        $this->read('/api/v1/catalog', $manager)->assertOk()->assertJsonPath('data', []);
        $this->read('/api/v1/tax-rules', $manager)->assertOk()->assertJsonPath('data', []);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function writers(): array
    {
        return ['gérante' => ['MANAGER', 201], 'caisse' => ['CASHIER', 403], 'accueil' => ['RECEPTION', 403], 'professionnel' => ['CLINICIAN', 403]];
    }

    #[DataProvider('writers')]
    public function test_seule_la_gerante_gere_le_catalogue(string $role, int $status): void
    {
        $user = $this->makeStaff($this->clinicId, $role);

        $this->write('POST', '/api/v1/catalog/items', $this->itemBody(['reference' => 'DR-'.$role]), $user)->assertStatus($status);

        $this->assertSame($status === 201 ? 1 : 0, DB::table('catalog_items')->where('reference', 'DR-'.$role)->count());
    }

    public function test_un_module_ferme_repond_404_meme_pour_la_gerante(): void
    {
        config(['eeo.enabled_modules' => ['stock']]);

        $this->write('POST', '/api/v1/catalog/items', $this->itemBody(), $this->manager)->assertNotFound();
        $this->read('/api/v1/catalog', $this->manager)->assertNotFound();
        $this->read('/api/v1/tax-rules', $this->manager)->assertNotFound();
        $this->assertSame(0, DB::table('catalog_items')->where('reference', 'FR-NEW')->count());
    }

    public function test_le_cout_d_achat_n_est_rendu_qu_a_qui_detient_le_droit_de_le_lire(): void
    {
        DB::table('catalog_items')->where('id', $this->item['frame'])->update(['purchase_cost' => self::COST]);

        $cashier = $this->read('/api/v1/catalog', $this->cashier)->assertOk();
        $manager = $this->read('/api/v1/catalog', $this->manager)->assertOk();

        $cashier->assertJsonMissingPath('data.0.purchase_cost');
        $this->assertStringNotContainsString((string) self::COST, (string) $cashier->getContent());
        $this->assertContains(self::COST, array_column($manager->json('data'), 'purchase_cost'));
        $this->assertMatchesContract($cashier, 'schemas/resources/billing.json#/$defs/CatalogPage');
        $this->assertMatchesContract($manager, 'schemas/resources/billing.json#/$defs/CatalogPage');
    }

    public function test_l_accueil_sans_droit_de_lecture_du_catalogue_est_refuse(): void
    {
        $this->read('/api/v1/catalog', $this->reception)->assertForbidden();
        $this->read('/api/v1/catalog', $this->clinician)->assertOk();
    }

    public function test_la_recherche_exige_deux_caracteres_et_ignore_casse_accents_et_jokers(): void
    {
        $this->seedItem($this->clinicId, ['reference' => 'LS-ETE', 'name' => 'Lunettes solaires été', 'category_code' => 'OTHER']);

        $this->read('/api/v1/catalog?query=m', $this->cashier)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['query']]]);
        $this->assertSame(['FR-001'], array_column($this->read('/api/v1/catalog?query=MONTURE', $this->cashier)->assertOk()->json('data'), 'reference'));
        $this->assertSame(['LS-ETE'], array_column($this->read('/api/v1/catalog?query=ete', $this->cashier)->assertOk()->json('data'), 'reference'), 'Accents ignorés.');
        $this->assertSame([], $this->read('/api/v1/catalog?query='.urlencode('%%'), $this->cashier)->assertOk()->json('data'), 'Les jokers de LIKE sont échappés.');
        $this->assertSame([], $this->read('/api/v1/catalog?query=_'.urlencode('_'), $this->cashier)->assertOk()->json('data'));
        $this->assertCount(5, $this->read('/api/v1/catalog', $this->cashier)->assertOk()->json('data'), 'Sans texte : tous les articles actifs.');
    }

    public function test_les_articles_desactives_ne_se_listent_que_pour_qui_gere_le_catalogue(): void
    {
        DB::table('catalog_items')->where('id', $this->item['lens'])->update(['active' => 0]);

        $this->assertNotContains('LS-001', array_column($this->read('/api/v1/catalog?include_inactive=true', $this->cashier)->json('data'), 'reference'));
        $this->assertNotContains('LS-001', array_column($this->read('/api/v1/catalog', $this->manager)->json('data'), 'reference'));
        $this->assertContains('LS-001', array_column($this->read('/api/v1/catalog?include_inactive=true', $this->manager)->json('data'), 'reference'));
    }

    public function test_la_liste_est_paginee_par_vingt_cinq(): void
    {
        for ($index = 1; $index <= 30; $index++) {
            $this->seedItem($this->clinicId, ['reference' => sprintf('PG-%03d', $index), 'name' => sprintf('Article pagination %03d', $index)]);
        }

        $first = $this->read('/api/v1/catalog?query=pagination', $this->cashier)->assertOk();
        $second = $this->read('/api/v1/catalog?query=pagination&page=2', $this->cashier)->assertOk();

        $this->assertCount(25, $first->json('data'));
        $this->assertCount(5, $second->json('data'));
        $this->assertSame(30, $first->json('meta.total'));
        $this->assertSame(2, $first->json('meta.last_page'));
        $this->read('/api/v1/catalog?page=0', $this->cashier)->assertStatus(422);
    }

    public function test_une_modification_change_le_prix_trace_l_avant_apres_et_incremente_la_version(): void
    {
        $id = $this->item['frame'];
        $body = $this->itemBody(['expected_version' => 1, 'reference' => 'FR-001', 'name' => 'Monture fictive', 'sale_price' => 42000, 'purchase_cost' => self::COST]);

        $response = $this->write('PATCH', '/api/v1/catalog/items/'.$id, $body, $this->manager);

        $response->assertOk()->assertJsonPath('data.sale_price', 42000)->assertJsonPath('data.version', 2);
        $audit = DB::table('audit_events')->where('action', 'catalog.item_updated')->where('target_id', $id)->first();
        $this->assertNotNull($audit);
        $diff = json_decode((string) $audit->sanitized_diff, true);
        // Colonne JSON MySQL : l'ordre des clés n'est pas conservé (tri par longueur), seul leur contenu compte.
        $this->assertEqualsCanonicalizing(['before', 'after'], array_keys($diff['changes']['sale_price']));
        $this->assertSame(40000, $diff['changes']['sale_price']['before']);
        $this->assertSame(42000, $diff['changes']['sale_price']['after']);
        $this->assertSame(['modified' => true], $diff['changes']['purchase_cost']);
        $this->assertStringNotContainsString((string) self::COST, $this->sideEffectsDump());
    }

    public function test_une_version_perimee_est_un_conflit_qui_indique_la_version_a_reprendre(): void
    {
        $response = $this->write('PATCH', '/api/v1/catalog/items/'.$this->item['frame'], $this->itemBody(['expected_version' => 5, 'reference' => 'FR-001']), $this->manager);

        $response->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT')->assertJsonPath('error.details.current_version', 1);
        $this->assertMatchesContract($response, 'schemas/error.json');
        $this->assertSame(1, (int) DB::table('catalog_items')->where('id', $this->item['frame'])->value('version'));
    }

    public function test_une_modification_sans_effet_ne_change_ni_la_version_ni_l_audit(): void
    {
        $id = $this->item['exam'];
        $same = $this->itemBody([
            'expected_version' => 1, 'reference' => 'EX-001', 'category_code' => 'SERVICE', 'name' => 'Examen de vue (fictif)', 'unit_code' => 'SERVICE',
            'quantity_integer_only' => false, 'sale_price' => 15000, 'purchase_cost' => null, 'stock_mode' => 'NONE',
        ]);

        $this->write('PATCH', '/api/v1/catalog/items/'.$id, $same, $this->manager)->assertOk()->assertJsonPath('data.version', 1);

        $this->assertSame(1, (int) DB::table('catalog_items')->where('id', $id)->value('version'));
        $this->assertSame(0, DB::table('audit_events')->where('action', 'catalog.item_updated')->count());
    }

    public function test_un_article_d_un_autre_cabinet_est_introuvable(): void
    {
        $other = $this->makeClinic('TEST CABINET B');
        $this->makeStaff($other, 'MANAGER', 'gerante.b');
        $foreign = $this->seedItem($other, ['reference' => 'ETR-1']);

        $this->write('PATCH', '/api/v1/catalog/items/'.$foreign, $this->itemBody(['expected_version' => 1, 'reference' => 'ETR-1']), $this->manager)->assertNotFound();
        $this->assertSame(1, (int) DB::table('catalog_items')->where('id', $foreign)->value('version'));
    }

    public function test_un_rejeu_de_la_meme_cle_ne_cree_qu_un_article_et_un_autre_contenu_est_un_conflit(): void
    {
        $body = $this->itemBody();
        $key = Ulid::generate();

        $first = $this->write('POST', '/api/v1/catalog/items', $body, $this->manager, $key)->assertCreated();
        $replay = $this->write('POST', '/api/v1/catalog/items', $body, $this->manager, $key)->assertCreated();
        $this->write('POST', '/api/v1/catalog/items', [...$body, 'sale_price' => 41000], $this->manager, $key)
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, DB::table('catalog_items')->where('reference', 'FR-NEW')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'catalog.item_created')->count());
    }

    public function test_le_corps_est_valide_contre_le_contrat_et_la_cle_est_obligatoire(): void
    {
        $absent = $this->itemBody();
        unset($absent['expected_version']);

        foreach ([$absent, $this->itemBody(['expected_version' => 3]), $this->itemBody(['sale_price' => 100.5]), $this->itemBody(['stock_mode' => 'INCONNU']), [...$this->itemBody(), 'extra' => 1]] as $body) {
            $this->write('POST', '/api/v1/catalog/items', $body, $this->manager)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        // Les en-têtes par défaut du client de test persistent d'un appel à l'autre : sans les vider, la clé du dernier
        // appel serait renvoyée et la requête « sans clé » n'en serait pas une.
        $this->flushHeaders()->actingAsFresh($this->manager)->postJson('/api/v1/catalog/items', $this->itemBody())->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['Idempotency-Key']]]);
        $this->assertSame(0, DB::table('catalog_items')->where('reference', 'FR-NEW')->count());
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
    public function test_un_echec_a_n_importe_quel_point_annule_la_creation(Checkpoint $failAt): void
    {
        $before = $this->billingEffects() + ['items' => DB::table('catalog_items')->count()];
        $this->app->instance(CommandBusProbe::class, new RecordingProbe($failAt));
        $this->withoutExceptionHandling();

        try {
            $this->write('POST', '/api/v1/catalog/items', $this->itemBody(), $this->manager);
            $this->fail('Une erreur injectée devait annuler la commande.');
        } catch (RuntimeException) {
            // Attendu.
        }

        $this->assertSame($before, $this->billingEffects() + ['items' => DB::table('catalog_items')->count()]);
        $this->app->instance(CommandBusProbe::class, new RecordingProbe);
        $this->write('POST', '/api/v1/catalog/items', $this->itemBody(), $this->manager)->assertCreated();
    }
}
