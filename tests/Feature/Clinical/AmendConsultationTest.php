<?php

namespace Tests\Feature\Clinical;

use App\Application\Checkpoint;
use App\Application\Command;
use App\Application\CommandBusProbe;
use App\Application\Locking\TransactionLocks;
use App\Domain\Clinical\Handlers\AmendConsultationHandler;
use App\Domain\Clinical\RevisionHash;
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
 * Correction motivée d'une consultation terminée (C03, AT19, AT21, AT42) : une nouvelle révision immuable avec son motif
 * et son auteur, les révisions antérieures intactes, aucune écriture « dernier écrit gagnant ». Données fictives.
 */
final class AmendConsultationTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    private string $visitId;

    private string $consultationId;

    private string $firstRevisionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClinical();
        [$this->visitId, $this->consultationId] = $this->completableExam();
        $body = $this->completeBody($this->consultationId);
        $this->completeConsultation($this->consultationId, $body)->assertOk();
        $this->firstRevisionId = (string) $body['revision_id'];
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    private function newConclusion(string $text = 'Conclusion corrigée (fictif)'): array
    {
        return $this->conclusion(['conclusion' => $text, 'referral' => false]);
    }

    public function test_la_correction_ecrit_la_revision_2_chainee_avec_son_motif_et_laisse_la_revision_1_intacte(): void
    {
        $firstSnapshot = DB::table('clinical_revisions')->where('id', $this->firstRevisionId)->first();
        $body = $this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion()], 'Faute de frappe dans la conclusion (fictif)');

        $response = $this->amendConsultation($this->consultationId, $body)->assertCreated();

        $this->assertMatchesContract($response, 'schemas/resources/clinical.json#/$defs/ConsultationResponse');
        $response->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.version', 5)
            ->assertJsonPath('data.steps.CONCLUSION.conclusion', 'Conclusion corrigée (fictif)')
            ->assertJsonPath('data.revisions.1.revision_no', 2)
            ->assertJsonPath('data.revisions.1.previous_revision_id', $this->firstRevisionId)
            ->assertJsonPath('data.revisions.1.reason', 'Faute de frappe dans la conclusion (fictif)')
            ->assertJsonPath('data.revisions.1.author_id', $this->clinician->id);
        $this->assertSame(2, (int) DB::table('consultations')->where('id', $this->consultationId)->value('current_revision'));
        $this->assertEquals($firstSnapshot, DB::table('clinical_revisions')->where('id', $this->firstRevisionId)->first(), 'La révision 1 n\'est jamais modifiée.');
        $this->assertSame(2, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
    }

    public function test_le_snapshot_de_la_revision_2_decrit_l_etat_apres_correction_avec_le_motif_et_la_revision_precedente(): void
    {
        $body = $this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion()], 'Précision de la conclusion (fictif)');
        $this->amendConsultation($this->consultationId, $body)->assertCreated();

        $revision = DB::table('clinical_revisions')->where('id', $body['revision_id'])->first();
        $this->assertNotNull($revision);
        $snapshot = json_decode((string) $revision->snapshot, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(2, $snapshot['revision_no']);
        $this->assertSame($this->firstRevisionId, $snapshot['previous_revision_id']);
        $this->assertSame('Précision de la conclusion (fictif)', $snapshot['amendment_reason']);
        $this->assertSame('Conclusion corrigée (fictif)', $snapshot['steps']['CONCLUSION']['conclusion']);
        $this->assertSame('Gêne en vision de près (fictif)', $snapshot['steps']['ANAMNESIS']['symptoms'], 'Les rubriques non corrigées sont reprises telles quelles.');
        $this->assertSame($revision->sha256, RevisionHash::hash($snapshot));
        $this->assertDataMatchesContract(json_decode((string) $revision->snapshot, false, 512, JSON_THROW_ON_ERROR), 'snapshots/clinical-revision.json');

        $first = json_decode((string) DB::table('clinical_revisions')->where('id', $this->firstRevisionId)->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Conclusion fictive', $first['steps']['CONCLUSION']['conclusion'], 'L\'ancien contenu reste lisible dans la révision 1.');
    }

    public function test_plusieurs_rubriques_se_corrigent_ensemble_et_une_seconde_correction_chaine_la_revision_3(): void
    {
        $scale = $this->seedScale();
        $first = $this->amendBody($this->consultationId, [
            'CONCLUSION' => $this->newConclusion('Nouvelle conclusion (fictif)'),
            'MEDICATIONS' => ['orders' => [$this->order('Collyre fictif')]],
            'VISUAL_ACUITY' => ['acuities' => [$this->acuity($scale, '9/10')]],
        ]);
        $this->amendConsultation($this->consultationId, $first)->assertCreated();

        $this->assertSame(1, DB::table('medication_orders')->where('consultation_id', $this->consultationId)->count());
        $this->assertSame(1, DB::table('visual_acuities')->where('consultation_id', $this->consultationId)->count());

        $second = $this->amendBody($this->consultationId, ['MEDICATIONS' => ['orders' => []]], 'Médicament retiré par erreur de saisie');
        $this->amendConsultation($this->consultationId, $second)->assertCreated()
            ->assertJsonPath('data.revisions.2.revision_no', 3)
            ->assertJsonPath('data.revisions.2.previous_revision_id', $first['revision_id']);
        $this->assertSame(0, DB::table('medication_orders')->where('consultation_id', $this->consultationId)->count());
        $this->assertSame(3, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
    }

    public function test_l_audit_nomme_les_rubriques_corrigees_sans_contenu_ni_motif_et_la_visite_n_est_pas_touchee(): void
    {
        $visitBefore = DB::table('visits')->where('id', $this->visitId)->first();
        $body = $this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion('SENTINELLE-CORRECTION-WB6')], 'SENTINELLE-MOTIF-WB6');
        $this->amendConsultation($this->consultationId, $body)->assertCreated();

        $audit = DB::table('audit_events')->where('action', 'consultation.amended')->where('target_id', $this->consultationId)->first();
        $this->assertNotNull($audit);
        $diff = json_decode((string) $audit->sanitized_diff, true);
        $this->assertSame(['previous_revision_id', 'revision_id', 'revision_no', 'sha256', 'steps'], collect($diff)->keys()->sort()->values()->all());
        $this->assertSame(['CONCLUSION'], $diff['steps']);
        $this->assertSame(2, $diff['revision_no']);

        $dump = $this->sideEffectsDump();
        $this->assertStringNotContainsString('SENTINELLE-CORRECTION-WB6', $dump);
        $this->assertStringNotContainsString('SENTINELLE-MOTIF-WB6', $dump, 'Le motif peut contenir une donnée clinique : il reste avec la révision, jamais dans l\'audit.');
        $this->assertEquals($visitBefore, DB::table('visits')->where('id', $this->visitId)->first());
        $this->assertSame(1, DB::table('sync_change_log')->where('entity_type', 'clinical_revisions')->where('entity_id', $body['revision_id'])->count());
    }

    public function test_une_consultation_en_cours_se_modifie_directement_et_ne_se_corrige_pas(): void
    {
        [, $inProgressId] = $this->completableExamForOtherPatient();
        $body = [
            'expected_version' => 3, 'consultation_id' => $inProgressId, 'revision_id' => Ulid::generate(), 'revision_no' => 2,
            'previous_revision_id' => Ulid::generate(), 'reason' => 'Correction prématurée',
            'steps' => [['step' => 'CONCLUSION', 'data' => $this->newConclusion()]],
        ];
        $body['snapshot_sha256'] = RevisionHash::hash($body['steps']);

        $this->amendConsultation($inProgressId, $body)->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');

        $this->assertSame(0, DB::table('clinical_revisions')->where('consultation_id', $inProgressId)->count());
    }

    public function test_une_version_perimee_est_un_conflit_et_rien_n_est_ecrit(): void
    {
        $body = $this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion('Première correction')]);
        $this->amendConsultation($this->consultationId, $body)->assertCreated();
        $before = $this->clinicalEffectCounts();

        // Un autre poste corrige la même consultation avec la version qu'il avait lue (périmée).
        $stale = [...$this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion('Deuxième correction')]), 'expected_version' => 4];
        $conflict = $this->amendConsultation($this->consultationId, $stale);

        $conflict->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT')->assertJsonPath('error.details.current_version', 5);
        $this->assertMatchesContract($conflict, 'schemas/error.json');
        $this->assertSame($before, $this->clinicalEffectCounts());
        $this->assertSame('Première correction', DB::table('consultations')->where('id', $this->consultationId)->value('conclusion'));
    }

    public function test_le_numero_et_la_revision_precedente_doivent_etre_ceux_de_la_chaine(): void
    {
        $steps = ['CONCLUSION' => $this->newConclusion()];

        $this->assertFieldError($this->amendConsultation($this->consultationId, $this->amendBody($this->consultationId, $steps, overrides: ['revision_no' => 3])), 'revision_no');
        $this->assertFieldError($this->amendConsultation($this->consultationId, $this->amendBody($this->consultationId, $steps, overrides: ['previous_revision_id' => Ulid::generate()])), 'previous_revision_id');
        $this->assertFieldError($this->amendConsultation($this->consultationId, $this->amendBody($this->consultationId, $steps, overrides: ['revision_no' => 1])), 'revision_no');
        $this->assertSame(1, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
    }

    public function test_l_identifiant_de_revision_doit_etre_neuf(): void
    {
        $response = $this->amendConsultation($this->consultationId, $this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion()], overrides: ['revision_id' => $this->firstRevisionId]));

        $this->assertFieldError($response, 'revision_id');
        $this->assertSame(1, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidReasons(): array
    {
        return ['vide' => [''], 'blanc' => ['   '], 'deux caractères' => ['ab'], 'trop long' => [str_repeat('m', 1001)], 'caractère de contrôle' => ["Motif\x07 de correction"]];
    }

    #[DataProvider('invalidReasons')]
    public function test_le_motif_est_obligatoire_et_borne(string $reason): void
    {
        $response = $this->amendConsultation($this->consultationId, $this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion()], $reason));

        $this->assertFieldError($response, 'reason');
        $this->assertSame(1, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
    }

    public function test_une_correction_qui_ne_change_rien_est_refusee(): void
    {
        $same = $this->conclusion(['conclusion' => 'Conclusion fictive', 'referral' => false]);

        $response = $this->amendConsultation($this->consultationId, $this->amendBody($this->consultationId, ['CONCLUSION' => $same]));

        $this->assertFieldError($response, 'steps');
        $this->assertSame(1, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
    }

    public function test_une_rubrique_ne_figure_qu_une_fois_et_les_erreurs_sont_rendues_par_rubrique(): void
    {
        $steps = [['step' => 'CONCLUSION', 'data' => $this->newConclusion('A')], ['step' => 'CONCLUSION', 'data' => $this->newConclusion('B')]];
        $body = [...$this->amendBody($this->consultationId, []), 'steps' => $steps, 'snapshot_sha256' => RevisionHash::hash($steps)];
        $this->assertFieldError($this->amendConsultation($this->consultationId, $body), 'steps.1.step');

        $invalid = $this->amendBody($this->consultationId, ['CONCLUSION' => $this->conclusion(['conclusion' => 'Orientation incomplète', 'referral' => true])]);
        $this->assertFieldError($this->amendConsultation($this->consultationId, $invalid), 'steps.0.data.referral_urgency');
        $this->assertSame(1, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
    }

    public function test_l_empreinte_doit_etre_celle_des_rubriques_recues(): void
    {
        $body = [...$this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion()]), 'snapshot_sha256' => str_repeat('0', 64)];

        $this->assertFieldError($this->amendConsultation($this->consultationId, $body), 'snapshot_sha256');
        $this->assertSame('Conclusion fictive', DB::table('consultations')->where('id', $this->consultationId)->value('conclusion'));
    }

    public function test_un_identifiant_de_ligne_pris_par_une_autre_consultation_est_refuse_par_rubrique(): void
    {
        $scale = $this->seedScale();
        [, $otherId] = $this->completableExamForOtherPatient();
        $taken = $this->acuity($scale, '8/10');
        $this->saveCurrent($otherId, 'VISUAL_ACUITY', ['acuities' => [$taken]])->assertOk();

        $response = $this->amendConsultation($this->consultationId, $this->amendBody($this->consultationId, ['VISUAL_ACUITY' => ['acuities' => [$taken]]]));

        $this->assertFieldError($response, 'steps.0.data.acuities.0.id');
    }

    public function test_les_corps_inconnus_ou_vides_sont_refuses_par_le_contrat(): void
    {
        $body = $this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion()]);

        $this->assertFieldError($this->amendConsultation($this->consultationId, [...$body, 'steps' => [], 'snapshot_sha256' => RevisionHash::hash([])]), 'steps');
        $this->assertFieldError($this->amendConsultation($this->consultationId, [...$body, 'champ_inconnu' => 1]), 'champ_inconnu');
        $this->assertFieldError($this->amendConsultation($this->consultationId, [...$body, 'consultation_id' => Ulid::generate()]), 'consultation_id');
        $this->assertSame(1, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
    }

    public function test_un_rejeu_de_la_meme_cle_ne_cree_qu_une_revision(): void
    {
        $body = $this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion()]);
        $key = Ulid::generate();

        $first = $this->amendConsultation($this->consultationId, $body, null, $key)->assertCreated();
        $replay = $this->amendConsultation($this->consultationId, $body, null, $key)->assertCreated();

        $this->assertSame($first->json('data.revisions'), $replay->json('data.revisions'));
        $this->assertSame(2, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'consultation.amended')->where('target_id', $this->consultationId)->count());
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function roles(): array
    {
        return ['gérante' => ['MANAGER', 201], 'professionnel' => ['CLINICIAN', 201], 'accueil' => ['RECEPTION', 403], 'caisse' => ['CASHIER', 403]];
    }

    #[DataProvider('roles')]
    public function test_seuls_la_gerante_et_le_professionnel_corrigent(string $role, int $status): void
    {
        $user = $this->makeStaff($this->clinicId, $role);
        $body = $this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion()]);

        $this->amendConsultation($this->consultationId, $body, $user)->assertStatus($status);

        $this->assertSame($status === 201 ? 2 : 1, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
    }

    public function test_sans_le_droit_de_corriger_ou_de_lire_la_demande_est_refusee(): void
    {
        $body = $this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion()]);
        $this->denyPermission($this->clinician, 'clinical.amend');
        $this->amendConsultation($this->consultationId, $body)->assertStatus(403);

        $other = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.sans.lecture');
        $this->denyPermission($other, 'clinical.read');
        $this->amendConsultation($this->consultationId, $body, $other)->assertStatus(403);

        $this->assertSame(1, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
    }

    public function test_une_consultation_d_un_autre_cabinet_ou_un_module_ferme_repondent_404(): void
    {
        $body = $this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion()]);
        $foreign = $this->makeStaff($this->makeClinic(), 'CLINICIAN', 'autre.pro.correction');

        $this->amendConsultation($this->consultationId, $body, $foreign)->assertNotFound();

        config(['eeo.enabled_modules' => ['today', 'patients']]);
        $this->amendConsultation($this->consultationId, $body, $this->manager)->assertNotFound();
        $this->assertSame(1, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
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
    public function test_un_echec_a_n_importe_quel_point_annule_la_correction_entiere(Checkpoint $failAt): void
    {
        $scale = $this->seedScale();
        $body = $this->amendBody($this->consultationId, [
            'CONCLUSION' => $this->newConclusion('Annulée'),
            'VISUAL_ACUITY' => ['acuities' => [$this->acuity($scale, '9/10')]],
        ]);
        $before = $this->clinicalEffectCounts();
        $this->app->instance(CommandBusProbe::class, new RecordingProbe($failAt));
        $this->withoutExceptionHandling();

        try {
            $this->amendConsultation($this->consultationId, $body);
            $this->fail('Une erreur injectée devait annuler la commande.');
        } catch (\RuntimeException) {
            // Attendu : la transaction est annulée en entier.
        }

        $this->assertSame($before, $this->clinicalEffectCounts());
        $consultation = DB::table('consultations')->where('id', $this->consultationId)->first();
        $this->assertNotNull($consultation);
        $this->assertSame(['Conclusion fictive', 1, 4], [$consultation->conclusion, (int) $consultation->current_revision, (int) $consultation->version]);

        $this->app->instance(CommandBusProbe::class, new RecordingProbe);
        $this->amendConsultation($this->consultationId, $body)->assertCreated();
    }

    public function test_le_verrou_de_la_consultation_est_pris_apres_celui_du_cabinet(): void
    {
        $body = $this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion()]);
        $expected = (int) $body['expected_version'];
        unset($body['expected_version']);
        $locks = new TransactionLocks($this->clinicId);
        $locks->clinic();

        $this->app->make(AmendConsultationHandler::class)->handle(
            new Command('AmendConsultation', $body, $this->consultationId, $expected),
            CommandContext::direct($this->clinician, 'corr-test-0001'),
            $locks,
            null,
        );

        $this->assertSame(['10:'.$this->clinicId, '47:'.$this->consultationId], $locks->acquired());
    }

    public function test_un_poste_hors_ligne_ou_synchronise_est_refuse(): void
    {
        $body = $this->amendBody($this->consultationId, ['CONCLUSION' => $this->newConclusion()]);
        $expected = (int) $body['expected_version'];
        unset($body['expected_version']);

        foreach ([CommandContext::job($this->clinicId, 'test', 'corr-test', CarbonImmutable::now('UTC')), $this->syncContextFor($this->clinician)] as $context) {
            try {
                $this->app->make(AmendConsultationHandler::class)->handle(new Command('AmendConsultation', $body, $this->consultationId, $expected), $context, new TransactionLocks($this->clinicId), null);
                $this->fail('Une source autre que la route directe devait être refusée.');
            } catch (BusinessError $error) {
                $this->assertSame('ACTION_FORBIDDEN', $error->errorCode);
            }
        }
        $this->assertSame(1, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
    }

    /**
     * Autre examen, resté en cours, d'un autre patient : [visite, consultation].
     *
     * @return array{0: string, 1: string}
     */
    private function completableExamForOtherPatient(): array
    {
        $patientId = $this->seedPatient($this->clinicId);
        $visitId = $this->seedVisit([], $this->clinicId, $patientId);
        $body = $this->startBody($visitId, ['patient_id' => $patientId]);
        $this->startConsultation($visitId, $body)->assertCreated();
        $consultationId = (string) $body['consultation_id'];
        $this->saveCurrent($consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Autre patient (fictif)']))->assertOk();
        $this->saveCurrent($consultationId, 'CONCLUSION', $this->conclusion(['conclusion' => 'Autre conclusion fictive', 'referral' => false]))->assertOk();

        return [$visitId, $consultationId];
    }
}
