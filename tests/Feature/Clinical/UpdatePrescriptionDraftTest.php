<?php

namespace Tests\Feature\Clinical;

use App\Application\Command;
use App\Application\Locking\TransactionLocks;
use App\Domain\Clinical\Handlers\UpdatePrescriptionDraftHandler;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Modification d'un brouillon de prescription (C04, en ligne seulement) : jeton `expected_updated_at`, champs de
 * contenu seuls, yeux et traitements remplacés quand ils figurent dans les changements, revalidation du contenu
 * résultant, aucune écriture sans effet, prescription validée immuable. Données fictives.
 */
final class UpdatePrescriptionDraftTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    private string $consultationId;

    /** @var array<string, mixed> */
    private array $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClinical();
        [, $this->consultationId] = $this->startedExam();
        $this->draft = $this->draftPrescription($this->consultationId);
        // Le jeton a la précision de la milliseconde : l'horloge avance pour que chaque modification ait le sien.
        $this->tick();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    private function tick(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(5));
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function patch(array $changes, ?string $token = null): array
    {
        return ['expected_updated_at' => $token ?? (string) $this->draft['updated_at'], 'changes' => $changes];
    }

    public function test_un_champ_modifie_change_le_jeton_et_l_audit_ne_nomme_que_les_champs(): void
    {
        $response = $this->updatePrescription($this->draft['id'], $this->patch(['notes' => 'SENTINELLE-NOTE-MODIFIEE-RT4', 'lens_index' => '1.60']))->assertOk();

        $this->assertMatchesContract($response, 'schemas/resources/clinical.json#/$defs/PrescriptionResponse');
        $response->assertJsonPath('data.notes', 'SENTINELLE-NOTE-MODIFIEE-RT4')->assertJsonPath('data.lens_index', '1.60')->assertJsonPath('data.status', 'DRAFT');
        $this->assertNotSame($this->draft['updated_at'], $response->json('data.updated_at'));
        $this->assertSame($this->draft['eyes'], $response->json('data.eyes'), 'Les yeux absents des changements ne sont pas touchés.');

        $audit = DB::table('audit_events')->where('action', 'prescription.updated')->where('target_id', $this->draft['id'])->first();
        $this->assertNotNull($audit);
        $diff = json_decode((string) $audit->sanitized_diff, true);
        $this->assertEqualsCanonicalizing(['notes', 'lens_index'], $diff['fields']);
        $this->assertStringNotContainsString('SENTINELLE-NOTE-MODIFIEE-RT4', $this->sideEffectsDump());
    }

    public function test_un_jeton_perime_est_un_conflit_jamais_un_dernier_ecrit_gagne(): void
    {
        $first = $this->updatePrescription($this->draft['id'], $this->patch(['notes' => 'Premier poste']))->assertOk();
        $this->tick();

        $conflict = $this->updatePrescription($this->draft['id'], $this->patch(['notes' => 'Second poste']));

        $conflict->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT')->assertJsonPath('error.details.current_updated_at', $first->json('data.updated_at'));
        $this->assertMatchesContract($conflict, 'schemas/error.json');
        $this->assertSame('Premier poste', DB::table('optical_prescriptions')->where('id', $this->draft['id'])->value('notes'));
    }

    public function test_les_yeux_et_les_traitements_fournis_remplacent_les_actuels(): void
    {
        $eyes = [$this->rxEye('OD', '+2.00', '+0.75', 180, '1.00'), $this->rxEye('OG', null, null, null, null, true)];
        $treatments = [$this->term('PHOTOCHROMIC'), $this->term('BLUE_LIGHT_FILTER')];

        $data = $this->updatePrescription($this->draft['id'], $this->patch(['eyes' => $eyes, 'treatments' => $treatments]))->assertOk()->json('data');

        $this->assertSame([$eyes[0]['id'], $eyes[1]['id']], array_column($data['eyes'], 'id'));
        $this->assertSame(['2.00', '0.75', 180, '1.00'], [$data['eyes'][0]['sphere'], $data['eyes'][0]['cylinder'], $data['eyes'][0]['axis'], $data['eyes'][0]['addition']], 'Le cylindre positif n\'est pas transposé.');
        $this->assertTrue($data['eyes'][1]['no_correction']);
        $this->assertSame(['PHOTOCHROMIC', 'BLUE_LIGHT_FILTER'], array_column($data['treatments'], 'term_code'));
        $this->assertSame(2, DB::table('optical_prescription_eyes')->where('prescription_id', $this->draft['id'])->count());
        $this->assertSame(0, DB::table('optical_prescription_eyes')->whereIn('id', array_column($this->draft['eyes'], 'id'))->count());
    }

    public function test_le_contenu_resultant_est_revalide_en_entier(): void
    {
        // La DP totale seule devient incohérente avec les DP monoculaires déjà enregistrées.
        $this->assertFieldError($this->updatePrescription($this->draft['id'], $this->patch(['pd_total' => '70.00'])), 'changes.pd_inconsistency_reason');
        $this->assertFieldError($this->updatePrescription($this->draft['id'], $this->patch(['usage_code' => 'OTHER'])), 'changes.usage_other');
        $this->assertFieldError($this->updatePrescription($this->draft['id'], $this->patch(['eyes' => [$this->rxEye('OD', '1.255'), $this->rxEye('OG')]])), 'changes.eyes.0.sphere');
        $this->assertSame('62.00', (string) DB::table('optical_prescriptions')->where('id', $this->draft['id'])->value('pd_total'));
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function fixedFields(): array
    {
        return [
            'origine' => ['origin', 'EXTERNAL'], 'patient' => ['patient_id', '01J9ZXK4M2Q8R7S6T5V4W30001'], 'consultation' => ['consultation_id', null],
            'série' => ['series_id', '01J9ZXK4M2Q8R7S6T5V4W30002'], 'version' => ['version_no', 2], 'statut' => ['status', 'VALIDATED'],
            'validation' => ['validated_at', '2026-10-22T09:30:00.000Z'],
        ];
    }

    #[DataProvider('fixedFields')]
    public function test_les_champs_fixes_a_la_creation_ne_se_modifient_pas(string $field, mixed $value): void
    {
        $this->assertFieldError($this->updatePrescription($this->draft['id'], $this->patch([$field => $value])), 'changes.'.$field);

        $this->assertSame('DRAFT', DB::table('optical_prescriptions')->where('id', $this->draft['id'])->value('status'));
    }

    public function test_un_corps_mal_forme_est_refuse(): void
    {
        $this->assertFieldError($this->updatePrescription($this->draft['id'], $this->patch([])), 'changes');
        $this->assertFieldError($this->updatePrescription($this->draft['id'], ['expected_updated_at' => '2026-10-22 09:30:00', 'changes' => ['notes' => 'x']]), 'expected_updated_at');
        $this->assertFieldError($this->updatePrescription($this->draft['id'], [...$this->patch(['notes' => 'x']), 'expected_version' => 3]), 'expected_version');
        $this->assertFieldError($this->updatePrescription($this->draft['id'], [...$this->patch(['notes' => 'x']), 'autre' => 1]), 'autre');
        $this->assertNull(DB::table('optical_prescriptions')->where('id', $this->draft['id'])->value('notes'));
    }

    public function test_expected_version_null_ajoute_par_le_poste_est_tolere(): void
    {
        $this->updatePrescription($this->draft['id'], [...$this->patch(['notes' => 'Avec version nulle']), 'expected_version' => null])->assertOk()->assertJsonPath('data.notes', 'Avec version nulle');
    }

    public function test_une_modification_sans_effet_n_ecrit_rien(): void
    {
        $before = DB::table('optical_prescriptions')->where('id', $this->draft['id'])->first();

        $response = $this->updatePrescription($this->draft['id'], $this->patch(['lens_index' => $this->draft['lens_index'], 'pd_total' => '62']))->assertOk();

        $this->assertSame($this->draft['updated_at'], $response->json('data.updated_at'));
        $this->assertEquals($before, DB::table('optical_prescriptions')->where('id', $this->draft['id'])->first());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'prescription.updated')->count());
    }

    public function test_une_prescription_validee_ne_se_modifie_plus(): void
    {
        $validated = $this->validatePrescription($this->draft['id'])->assertOk()->json('data');
        $this->tick();

        $this->updatePrescription($this->draft['id'], $this->patch(['notes' => 'Après validation'], (string) $validated['updated_at']))
            ->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');

        $this->assertNull(DB::table('optical_prescriptions')->where('id', $this->draft['id'])->value('notes'));
    }

    public function test_une_prescription_inconnue_ou_d_un_autre_cabinet_repond_introuvable(): void
    {
        $this->updatePrescription(Ulid::generate(), $this->patch(['notes' => 'x']))->assertNotFound();

        $foreign = $this->makeStaff($this->makeClinic(), 'CLINICIAN', 'autre.pro.brouillon');
        $this->updatePrescription($this->draft['id'], $this->patch(['notes' => 'x']), $foreign)->assertNotFound();
        $this->assertNull(DB::table('optical_prescriptions')->where('id', $this->draft['id'])->value('notes'));
    }

    public function test_l_accueil_et_la_caisse_ne_modifient_pas_un_brouillon(): void
    {
        $this->updatePrescription($this->draft['id'], $this->patch(['notes' => 'x']), $this->reception)->assertStatus(403);
        $this->updatePrescription($this->draft['id'], $this->patch(['notes' => 'x']), $this->cashier)->assertStatus(403);
        $this->assertNull(DB::table('optical_prescriptions')->where('id', $this->draft['id'])->value('notes'));
    }

    public function test_un_rejeu_de_la_meme_cle_n_applique_la_modification_qu_une_fois(): void
    {
        $key = Ulid::generate();
        $body = $this->patch(['notes' => 'Une fois']);

        $this->updatePrescription($this->draft['id'], $body, null, $key)->assertOk();
        $this->updatePrescription($this->draft['id'], $body, null, $key)->assertOk();

        $this->assertSame(1, DB::table('audit_events')->where('action', 'prescription.updated')->count());
    }

    public function test_la_modification_n_est_ouverte_qu_en_ligne(): void
    {
        $payload = ['prescription_id' => $this->draft['id'], 'expected_updated_at' => $this->draft['updated_at'], 'changes' => ['notes' => 'Hors ligne']];

        foreach ([CommandContext::job($this->clinicId, 'test', 'corr-test', CarbonImmutable::now('UTC')), $this->syncContextFor($this->clinician)] as $context) {
            try {
                $this->app->make(UpdatePrescriptionDraftHandler::class)->handle(new Command('UpdatePrescriptionDraft', $payload, $this->draft['id']), $context, new TransactionLocks($this->clinicId), null);
                $this->fail('Une source autre que la route directe devait être refusée.');
            } catch (BusinessError $error) {
                $this->assertSame('ACTION_FORBIDDEN', $error->errorCode);
            }
        }
        $this->assertNull(DB::table('optical_prescriptions')->where('id', $this->draft['id'])->value('notes'));
    }
}
