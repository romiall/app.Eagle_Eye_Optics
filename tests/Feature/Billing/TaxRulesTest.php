<?php

namespace Tests\Feature\Billing;

use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\BillingFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Règles fiscales (B01, EE020) : aucune taxe préconfigurée ni inventée, saisie et validation par la gérante
 * réauthentifiée, règle active seulement si validée, taux changé = validation retirée, version attendue. Les taux
 * ci-dessous sont des valeurs de TEST sans valeur fiscale.
 */
final class TaxRulesTest extends TestCase
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ruleBody(array $overrides = []): array
    {
        return array_merge(['expected_version' => null, 'code' => 'TEST_TAXE', 'label' => 'Taxe fictive de test', 'rate_percent' => '10', 'active' => false, 'validate' => false, 'legal_note' => 'Sans valeur fiscale'], $overrides);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function writeRule(string $method, string $uri, array $body, bool $reauthenticated = true, ?string $key = null): TestResponse
    {
        $client = $reauthenticated ? $this->actingAsReauthenticated($this->manager) : $this->actingAsFresh($this->manager);

        return $client->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])->json($method, $uri, $body);
    }

    public function test_aucune_regle_n_existe_tant_que_la_gerante_n_en_a_pas_saisi(): void
    {
        $this->assertSame(0, DB::table('tax_rules')->where('clinic_id', $this->clinicId)->count());
        $this->actingAsFresh($this->manager)->getJson('/api/v1/tax-rules')->assertOk()->assertJsonPath('data', []);
    }

    public function test_la_saisie_exige_une_reauthentification_recente(): void
    {
        $response = $this->writeRule('POST', '/api/v1/tax-rules', $this->ruleBody(), false);

        $response->assertStatus(423)->assertJsonPath('error.code', 'REAUTHENTICATION_REQUIRED');
        $this->assertSame(0, DB::table('tax_rules')->count());
    }

    public function test_seule_la_gerante_saisit_une_regle(): void
    {
        $this->actingAsReauthenticated($this->cashier)->withHeaders(['Idempotency-Key' => Ulid::generate()])
            ->postJson('/api/v1/tax-rules', $this->ruleBody())->assertForbidden();
        $this->actingAsFresh($this->cashier)->getJson('/api/v1/tax-rules')->assertForbidden();
    }

    public function test_une_regle_non_validee_est_creee_inactive_et_sans_validateur(): void
    {
        $response = $this->writeRule('POST', '/api/v1/tax-rules', $this->ruleBody());

        $response->assertCreated()
            ->assertJsonPath('data.code', 'TEST_TAXE')
            ->assertJsonPath('data.rate_percent', '10.0000')
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.validated_by', null)
            ->assertJsonPath('data.validated_at', null)
            ->assertJsonPath('data.version', 1);
        $this->assertMatchesContract($response, 'schemas/resources/billing.json#/$defs/TaxRuleResponse');
    }

    public function test_la_validation_enregistre_la_gerante_et_l_instant(): void
    {
        $response = $this->writeRule('POST', '/api/v1/tax-rules', $this->ruleBody(['active' => true, 'validate' => true]));

        $response->assertCreated()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.validated_by', $this->manager->id)
            ->assertJsonPath('data.validated_at', '2026-10-22T09:30:00.000Z');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'tax_rule.created')->count());
    }

    public function test_une_regle_active_exige_sa_validation_et_un_taux(): void
    {
        $this->writeRule('POST', '/api/v1/tax-rules', $this->ruleBody(['active' => true]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['active']]]);
        $this->writeRule('POST', '/api/v1/tax-rules', $this->ruleBody(['rate_percent' => null, 'validate' => true]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['validate']]]);

        $this->assertSame(0, DB::table('tax_rules')->count());
    }

    public function test_le_code_est_unique_par_cabinet(): void
    {
        $this->writeRule('POST', '/api/v1/tax-rules', $this->ruleBody())->assertCreated();

        $this->writeRule('POST', '/api/v1/tax-rules', $this->ruleBody(['label' => 'Autre libellé']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['code']]]);
        $this->assertSame(1, DB::table('tax_rules')->count());
    }

    public function test_changer_le_taux_d_une_regle_validee_retire_la_validation_sauf_nouvelle_validation(): void
    {
        $id = $this->writeRule('POST', '/api/v1/tax-rules', $this->ruleBody(['active' => true, 'validate' => true]))->assertCreated()->json('data.id');

        // Taux changé, règle laissée active sans revalidation : refus, la règle validée reste intacte.
        $this->writeRule('PATCH', '/api/v1/tax-rules/'.$id, $this->ruleBody(['expected_version' => 1, 'rate_percent' => '12.5', 'active' => true]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['active']]]);
        $this->assertSame('10.0000', DB::table('tax_rules')->where('id', $id)->value('rate_percent'));
        $this->assertNotNull(DB::table('tax_rules')->where('id', $id)->value('validated_at'));

        // Désactivée avec un autre taux : la validation est retirée.
        $this->writeRule('PATCH', '/api/v1/tax-rules/'.$id, $this->ruleBody(['expected_version' => 1, 'rate_percent' => '12.5', 'active' => false]))
            ->assertOk()->assertJsonPath('data.validated_at', null)->assertJsonPath('data.validated_by', null)->assertJsonPath('data.version', 2);

        // Revalidée : de nouveau active.
        $this->writeRule('PATCH', '/api/v1/tax-rules/'.$id, $this->ruleBody(['expected_version' => 2, 'rate_percent' => '12.5', 'active' => true, 'validate' => true]))
            ->assertOk()->assertJsonPath('data.active', true)->assertJsonPath('data.rate_percent', '12.5000')->assertJsonPath('data.version', 3);
    }

    public function test_une_version_perimee_est_un_conflit_et_une_demande_sans_effet_ne_change_rien(): void
    {
        $id = $this->writeRule('POST', '/api/v1/tax-rules', $this->ruleBody())->assertCreated()->json('data.id');

        $this->writeRule('PATCH', '/api/v1/tax-rules/'.$id, $this->ruleBody(['expected_version' => 4]))
            ->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT')->assertJsonPath('error.details.current_version', 1);

        $this->writeRule('PATCH', '/api/v1/tax-rules/'.$id, $this->ruleBody(['expected_version' => 1]))->assertOk()->assertJsonPath('data.version', 1);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'tax_rule.updated')->count());
    }

    public function test_un_taux_hors_de_zero_a_cent_est_refuse_par_le_contrat(): void
    {
        $this->writeRule('POST', '/api/v1/tax-rules', $this->ruleBody(['rate_percent' => '100.5']))->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->writeRule('POST', '/api/v1/tax-rules', $this->ruleBody(['rate_percent' => '-1']))->assertStatus(422);
        $this->assertSame(0, DB::table('tax_rules')->count());
    }

    public function test_un_module_ferme_repond_404(): void
    {
        config(['eeo.enabled_modules' => []]);

        $this->writeRule('POST', '/api/v1/tax-rules', $this->ruleBody())->assertNotFound();
    }
}
