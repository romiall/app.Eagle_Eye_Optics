<?php

namespace Tests\Feature\Clinical;

use App\Application\Checkpoint;
use App\Application\Command;
use App\Application\CommandBusProbe;
use App\Application\Locking\TransactionLocks;
use App\Domain\Clinical\Handlers\SaveClinicalStepHandler;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalFixtures;
use Tests\Support\Commands\RecordingProbe;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Enregistrement d'une rubrique du dossier (C01, AT18, AT19) : mécanique commune aux rubriques narratives —
 * version attendue, états de la consultation, rubriques pas encore ouvertes, identifiants du chemin et du corps,
 * idempotence, droits cliniques, isolation des cabinets, retour arrière, confidentialité des effets de bord.
 * Les règles propres à chaque rubrique sont dans SaveAnamnesisStepTest, SaveHistoriesStepTest et
 * SaveConclusionStepTest. Données fictives.
 */
final class SaveClinicalStepTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    private string $visitId;

    private string $consultationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClinical();
        [$this->visitId, $this->consultationId] = $this->startedExam();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    public function test_la_premiere_sauvegarde_passe_le_brouillon_en_cours_et_incremente_la_version(): void
    {
        $data = $this->anamnesis(['symptoms' => 'Gêne en lecture (fictif)', 'wears_glasses' => true, 'glasses_duration' => '3 ans', 'complaints' => [$this->term('VISION_DECLINE')]]);

        $response = $this->saveStep($this->consultationId, 'ANAMNESIS', $data, 1);

        $response->assertOk()
            ->assertJsonPath('data.id', $this->consultationId)
            ->assertJsonPath('data.status', 'IN_PROGRESS')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.steps.ANAMNESIS.symptoms', 'Gêne en lecture (fictif)')
            ->assertJsonPath('data.steps.ANAMNESIS.complaints.0.term_code', 'VISION_DECLINE');
        $this->assertMatchesContract($response, 'schemas/resources/clinical.json#/$defs/ConsultationResponse');

        $row = DB::table('consultations')->where('id', $this->consultationId)->first();
        $this->assertNotNull($row);
        $this->assertSame('IN_PROGRESS', $row->status);
        $this->assertSame(2, (int) $row->version);

        $change = DB::table('sync_change_log')->where('entity_type', 'consultations')->where('entity_version', 2)->first();
        $this->assertNotNull($change);
        $this->assertSame('CLINICAL', $change->visibility);
        $this->assertSame('UPDATED', $change->change_kind);
    }

    public function test_l_audit_ne_porte_que_la_rubrique_la_version_et_le_nombre_de_lignes(): void
    {
        $data = $this->anamnesis([
            'symptoms' => 'SENTINELLE-SYMPTOME-ZX9',
            'glasses_duration' => null,
            'complaints' => [$this->term('OTHER', 'SENTINELLE-AUTRE-QW4'), $this->term('HEADACHE')],
        ]);

        $this->saveStep($this->consultationId, 'ANAMNESIS', $data, 1)->assertOk();

        $audit = DB::table('audit_events')->where('action', 'consultation.step_saved')->where('target_id', $this->consultationId)->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->clinician->id, $audit->actor_id);
        $this->assertNull($audit->reason);
        $this->assertEquals(['rows' => 2, 'step' => 'ANAMNESIS', 'version' => 2], json_decode((string) $audit->sanitized_diff, true));

        $dump = $this->sideEffectsDump();
        $this->assertStringNotContainsString('SENTINELLE-SYMPTOME-ZX9', $dump);
        $this->assertStringNotContainsString('SENTINELLE-AUTRE-QW4', $dump);
        $result = json_decode((string) DB::table('idempotency_requests')->where('command_name', 'SaveClinicalStep')->value('result_payload'), true);
        $this->assertEquals(['consultation_id' => $this->consultationId, 'status' => 'IN_PROGRESS', 'step' => 'ANAMNESIS', 'version' => 2], $result);
    }

    public function test_une_version_perimee_est_un_conflit_avec_la_version_actuelle_et_rien_n_est_ecrit(): void
    {
        $this->saveStep($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Première saisie']), 1)->assertOk();
        $before = $this->clinicalEffectCounts();

        $conflict = $this->saveStep($this->consultationId, 'CONCLUSION', $this->conclusion(['conclusion' => 'Saisie d\'un autre poste']), 1);

        $conflict->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT')
            ->assertJsonPath('error.details.current_version', 2);
        $this->assertMatchesContract($conflict, 'schemas/error.json');
        $this->assertSame($before, $this->clinicalEffectCounts());
        $this->assertNull(DB::table('consultations')->where('id', $this->consultationId)->value('conclusion'));

        // Avec la version actuelle, la même saisie passe.
        $this->saveStep($this->consultationId, 'CONCLUSION', $this->conclusion(['conclusion' => 'Saisie d\'un autre poste']), 2)->assertOk()->assertJsonPath('data.version', 3);
    }

    public function test_une_version_trop_recente_est_aussi_un_conflit(): void
    {
        $this->saveStep($this->consultationId, 'ANAMNESIS', $this->anamnesis(), 5)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT')
            ->assertJsonPath('error.details.current_version', 1);
    }

    public function test_enregistrer_une_rubrique_invalide_la_version_tenue_par_un_autre_poste_meme_sur_une_autre_rubrique(): void
    {
        // La version couvre toute la consultation : le poste B qui édite la conclusion avec la version 1 est
        // refusé après l'enregistrement de l'anamnèse par le poste A (AT19 : conflit conservé, rien n'est perdu).
        $this->saveStep($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Poste A']), 1)->assertOk();

        $this->saveStep($this->consultationId, 'CONCLUSION', $this->conclusion(['conclusion' => 'Poste B']), 1)->assertStatus(409);

        $this->assertSame('Poste A', DB::table('consultations')->where('id', $this->consultationId)->value('symptoms'));
    }

    public function test_un_enregistrement_sans_effet_n_incremente_pas_la_version_et_ne_laisse_aucun_audit(): void
    {
        $data = $this->anamnesis(['symptoms' => 'Stable', 'complaints' => [$this->term('HEADACHE'), $this->term('PAIN')]]);
        $this->saveStep($this->consultationId, 'ANAMNESIS', $data, 1)->assertOk();
        $before = $this->clinicalEffectCounts();

        // Même contenu, lignes dans un autre ordre : aucun effet.
        $same = [...$data, 'complaints' => array_reverse($data['complaints'])];
        $this->saveStep($this->consultationId, 'ANAMNESIS', $same, 2)->assertOk()->assertJsonPath('data.version', 2);

        $this->assertSame($before['audit'], $this->clinicalEffectCounts()['audit']);
        $this->assertSame($before['changes'], $this->clinicalEffectCounts()['changes']);
        $this->assertSame(2, (int) DB::table('consultations')->where('id', $this->consultationId)->value('version'));
    }

    public function test_un_brouillon_vide_enregistre_passe_quand_meme_en_cours(): void
    {
        $this->saveStep($this->consultationId, 'ANAMNESIS', $this->anamnesis(), 1)
            ->assertOk()
            ->assertJsonPath('data.status', 'IN_PROGRESS')
            ->assertJsonPath('data.version', 2);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function unopenedSteps(): array
    {
        return [
            'acuités' => ['VISUAL_ACUITY', ['acuities' => []]],
            'réfraction' => ['REFRACTION', ['measurements' => [], 'refraction_observations' => null, 'ametropias' => []]],
            'centrage' => ['CENTERING', ['centering_id' => null, 'pd_total' => null, 'pd_od' => null, 'pd_og' => null, 'inconsistency_reason' => null]],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('unopenedSteps')]
    public function test_les_rubriques_pas_encore_ouvertes_repondent_422_et_n_ecrivent_rien(string $step, array $data): void
    {
        $before = $this->clinicalEffectCounts();

        $response = $this->saveStep($this->consultationId, $step, $data, 1);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertSame(['Cette rubrique n\'est pas encore ouverte.'], $response->json('error.fields')['step.step']);
        $this->assertSame($before['audit'], $this->clinicalEffectCounts()['audit']);
        $this->assertSame($before['changes'], $this->clinicalEffectCounts()['changes']);
        $row = DB::table('consultations')->where('id', $this->consultationId)->first();
        $this->assertNotNull($row);
        $this->assertSame('DRAFT', $row->status, 'Rien n\'a été écrit : la consultation reste au brouillon.');
        $this->assertSame(1, (int) $row->version);
        $this->assertSame(0, DB::table('visual_acuities')->count() + DB::table('refraction_measurements')->count() + DB::table('autorefractor_centering')->count());
    }

    public function test_une_consultation_terminee_ne_se_modifie_plus_par_une_saisie(): void
    {
        DB::table('consultations')->where('id', $this->consultationId)->update(['status' => 'COMPLETED', 'completed_at' => '2026-10-22 09:40:00.000000', 'current_revision' => 1]);

        $response = $this->saveStep($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Tentative tardive']), 1);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED')
            ->assertJsonPath('error.details.current_status', 'COMPLETED');
        $this->assertMatchesContract($response, 'schemas/error.json');
        $this->assertNull(DB::table('consultations')->where('id', $this->consultationId)->value('symptoms'));
    }

    public function test_une_etape_hors_catalogue_ou_des_identifiants_incoherents_sont_refuses(): void
    {
        $data = $this->anamnesis();
        $body = ['expected_version' => 1, 'consultation_id' => $this->consultationId, 'step' => ['step' => 'ANAMNESIS', 'data' => $data]];

        // Rubrique du chemin différente de celle du corps.
        $this->saveStepBody($this->consultationId, 'CONCLUSION', $body)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['step.step']]]);
        // Rubrique inconnue partout.
        $unknown = $body;
        $unknown['step']['step'] = 'PRESCRIPTION';
        $this->saveStepBody($this->consultationId, 'PRESCRIPTION', $unknown)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        // Consultation du chemin différente de celle du corps.
        $this->saveStepBody($this->consultationId, 'ANAMNESIS', [...$body, 'consultation_id' => Ulid::generate()])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['consultation_id']]]);

        $this->assertSame(1, (int) DB::table('consultations')->where('id', $this->consultationId)->value('version'));
    }

    public function test_le_corps_de_la_route_directe_est_valide_contre_son_contrat(): void
    {
        $valid = ['expected_version' => 1, 'consultation_id' => $this->consultationId, 'step' => ['step' => 'ANAMNESIS', 'data' => $this->anamnesis()]];
        $noVersion = $valid;
        unset($noVersion['expected_version']);
        $unknownKey = $valid;
        $unknownKey['step']['data']['diagnostic'] = 'non prévu';
        $missingKey = $valid;
        unset($missingKey['step']['data']['wears_glasses']);

        foreach ([
            $noVersion,
            [...$valid, 'expected_version' => 0],
            [...$valid, 'expected_version' => '1'],
            [...$valid, 'extra' => 1],
            $unknownKey,
            $missingKey,
        ] as $body) {
            $this->saveStepBody($this->consultationId, 'ANAMNESIS', $body)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->assertSame(1, (int) DB::table('consultations')->where('id', $this->consultationId)->value('version'));
    }

    public function test_les_erreurs_du_contrat_sont_rendues_ensemble_avec_des_cles_pointees(): void
    {
        $data = $this->anamnesis(['complaints' => [['id' => 'pas-un-ulid', 'term_code' => 'minuscule', 'other_text' => 3]]]);

        $response = $this->saveStep($this->consultationId, 'ANAMNESIS', $data, 1);

        $response->assertStatus(422);
        $fields = array_keys($response->json('error.fields'));
        $this->assertContains('step.data.complaints.0.id', $fields);
        $this->assertContains('step.data.complaints.0.term_code', $fields);
        $this->assertContains('step.data.complaints.0.other_text', $fields);
    }

    public function test_un_rejeu_de_la_meme_cle_n_applique_qu_une_fois_et_rend_la_consultation_actuelle(): void
    {
        $data = $this->anamnesis(['symptoms' => 'Une fois']);
        $key = Ulid::generate();

        $first = $this->saveStep($this->consultationId, 'ANAMNESIS', $data, 1, null, $key)->assertOk();
        $replay = $this->saveStep($this->consultationId, 'ANAMNESIS', $data, 1, null, $key)->assertOk();

        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertSame(2, (int) DB::table('consultations')->where('id', $this->consultationId)->value('version'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'consultation.step_saved')->count());
    }

    public function test_la_meme_cle_avec_un_autre_contenu_est_refusee_et_rien_n_est_ecrit(): void
    {
        $key = Ulid::generate();
        $this->saveStep($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Premier']), 1, null, $key)->assertOk();

        $this->saveStep($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Second']), 1, null, $key)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');

        $this->assertSame('Premier', DB::table('consultations')->where('id', $this->consultationId)->value('symptoms'));
    }

    public function test_une_consultation_inconnue_ou_d_un_autre_cabinet_repond_introuvable(): void
    {
        $otherClinic = $this->makeClinic();
        $otherClinician = $this->makeStaff($otherClinic, 'CLINICIAN', 'autre.pro');
        $otherPatient = $this->seedPatient($otherClinic);
        $otherVisit = $this->seedVisit([], $otherClinic, $otherPatient);
        $foreignId = Ulid::generate();
        $this->startConsultation($otherVisit, [
            ...$this->startBody($otherVisit, ['consultation_id' => $foreignId]),
            'patient_id' => $otherPatient,
        ], $otherClinician)->assertCreated();

        $this->saveStep($foreignId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Intrusion']), 1)->assertNotFound();
        $this->saveStep(Ulid::generate(), 'ANAMNESIS', $this->anamnesis(), 1)->assertNotFound();
        $this->assertNull(DB::table('consultations')->where('id', $foreignId)->value('symptoms'));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function roles(): array
    {
        return ['gérante' => ['MANAGER', 200], 'professionnel' => ['CLINICIAN', 200], 'accueil' => ['RECEPTION', 403], 'caisse' => ['CASHIER', 403]];
    }

    #[DataProvider('roles')]
    public function test_seuls_la_gerante_et_le_professionnel_enregistrent_une_rubrique(string $role, int $status): void
    {
        $user = $this->makeStaff($this->clinicId, $role);

        $this->saveStep($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Par '.$role]), 1, $user)->assertStatus($status);

        $this->assertSame($status === 200 ? 2 : 1, (int) DB::table('consultations')->where('id', $this->consultationId)->value('version'));
    }

    public function test_sans_le_droit_de_lire_l_ecriture_est_refusee_car_elle_rend_des_donnees_cliniques(): void
    {
        $this->denyPermission($this->clinician, 'clinical.read');

        $this->saveStep($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Sans lecture']), 1)->assertStatus(403);

        $this->assertNull(DB::table('consultations')->where('id', $this->consultationId)->value('symptoms'));
    }

    public function test_un_module_ferme_repond_404_avant_le_controle_des_droits(): void
    {
        config(['eeo.enabled_modules' => ['today', 'patients']]);

        $this->saveStep($this->consultationId, 'ANAMNESIS', $this->anamnesis(), 1, $this->manager)->assertNotFound();
        $this->saveStep($this->consultationId, 'ANAMNESIS', $this->anamnesis(), 1, $this->cashier)->assertNotFound();
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
    public function test_un_echec_a_n_importe_quel_point_annule_la_rubrique_entiere(Checkpoint $failAt): void
    {
        $data = $this->anamnesis(['symptoms' => 'Annulé', 'wears_glasses' => true, 'complaints' => [$this->term('HEADACHE'), $this->term('OTHER', 'Précision annulée')]]);
        $before = $this->clinicalEffectCounts();
        $this->app->instance(CommandBusProbe::class, new RecordingProbe($failAt));
        $this->withoutExceptionHandling();

        try {
            $this->saveStep($this->consultationId, 'ANAMNESIS', $data, 1);
            $this->fail('Une erreur injectée devait annuler la commande.');
        } catch (\RuntimeException) {
            // Attendu : la transaction est annulée en entier.
        }

        $this->assertSame($before, $this->clinicalEffectCounts());
        $row = DB::table('consultations')->where('id', $this->consultationId)->first();
        $this->assertNotNull($row);
        $this->assertSame('DRAFT', $row->status);
        $this->assertSame(1, (int) $row->version);
        $this->assertNull($row->symptoms);
        $this->assertNull($row->wears_glasses);

        // La même saisie aboutit ensuite.
        $this->app->instance(CommandBusProbe::class, new RecordingProbe);
        $this->saveStep($this->consultationId, 'ANAMNESIS', $data, 1)->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_la_consultation_est_verrouillee_apres_le_cabinet_et_avant_toute_ecriture(): void
    {
        $payload = ['consultation_id' => $this->consultationId, 'step' => ['step' => 'ANAMNESIS', 'data' => $this->anamnesis(['symptoms' => 'Verrou'])]];
        $locks = new TransactionLocks($this->clinicId);
        $locks->clinic();

        $this->app->make(SaveClinicalStepHandler::class)->handle(
            new Command('SaveClinicalStep', $payload, $this->consultationId, 1),
            CommandContext::direct($this->clinician, 'corr-test-0001'),
            $locks,
            null,
        );

        $this->assertSame(['10:'.$this->clinicId, '47:'.$this->consultationId], $locks->acquired());
    }

    public function test_un_poste_hors_ligne_est_refuse_tant_que_la_synchronisation_clinique_n_existe_pas(): void
    {
        $context = CommandContext::job($this->clinicId, 'test', 'corr-test', CarbonImmutable::now('UTC'));
        $payload = ['consultation_id' => $this->consultationId, 'step' => ['step' => 'ANAMNESIS', 'data' => $this->anamnesis(['symptoms' => 'Hors ligne'])]];

        try {
            $this->app->make(SaveClinicalStepHandler::class)->handle(new Command('SaveClinicalStep', $payload, $this->consultationId, 1), $context, new TransactionLocks($this->clinicId), null);
            $this->fail('Une source autre que la route directe devait être refusée.');
        } catch (BusinessError $error) {
            $this->assertSame('ACTION_FORBIDDEN', $error->errorCode);
        }
        $this->assertNull(DB::table('consultations')->where('id', $this->consultationId)->value('symptoms'));
    }
}
