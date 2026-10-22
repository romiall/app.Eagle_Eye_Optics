<?php

namespace Tests\Feature\Clinical;

use App\Application\Checkpoint;
use App\Application\Command;
use App\Application\CommandBusProbe;
use App\Application\Locking\TransactionLocks;
use App\Domain\Clinical\Handlers\CompleteConsultationHandler;
use App\Domain\Clinical\RevisionHash;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalFixtures;
use Tests\Support\Commands\RecordingProbe;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Finalisation d'une consultation (C03, AT19, AT21) : la révision 1 immuable est écrite avec le passage à « terminé »
 * et la clôture de la visite, dans une seule transaction. Version attendue, empreinte recalculée par le serveur,
 * justification de l'absence du motif ou de la conclusion, états refusés, rejeu idempotent, droits, isolation des
 * cabinets, retour arrière, confidentialité des effets de bord. Aucun contrôle de paiement. Données fictives.
 */
final class CompleteConsultationTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    private string $visitId;

    private string $consultationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClinical();
        [$this->visitId, $this->consultationId] = $this->completableExam();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    public function test_la_finalisation_fige_la_revision_1_termine_la_consultation_et_cloture_la_visite(): void
    {
        $body = $this->completeBody($this->consultationId);

        $response = $this->completeConsultation($this->consultationId, $body)->assertOk();

        $this->assertMatchesContract($response, 'schemas/resources/clinical.json#/$defs/ConsultationResponse');
        $response->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.version', 4)
            ->assertJsonPath('data.revisions.0.id', $body['revision_id'])
            ->assertJsonPath('data.revisions.0.revision_no', 1)
            ->assertJsonPath('data.revisions.0.previous_revision_id', null)
            ->assertJsonPath('data.revisions.0.author_id', $this->clinician->id);
        $consultation = DB::table('consultations')->where('id', $this->consultationId)->first();
        $this->assertNotNull($consultation);
        $this->assertSame(['COMPLETED', 1, 4], [$consultation->status, (int) $consultation->current_revision, (int) $consultation->version]);
        $this->assertNotNull($consultation->completed_at);

        $visit = DB::table('visits')->where('id', $this->visitId)->first();
        $this->assertNotNull($visit);
        $this->assertSame('COMPLETED', $visit->status);
        $this->assertNotNull($visit->closed_at, 'La date de clôture de la visite est écrite.');
        $this->assertSame(1, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
    }

    public function test_le_snapshot_est_complet_et_son_empreinte_est_celle_de_sa_forme_canonique(): void
    {
        $body = $this->completeBody($this->consultationId);
        $this->completeConsultation($this->consultationId, $body)->assertOk();

        $revision = DB::table('clinical_revisions')->where('id', $body['revision_id'])->first();
        $this->assertNotNull($revision);
        $snapshot = json_decode((string) $revision->snapshot, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($revision->sha256, RevisionHash::hash($snapshot), 'L\'empreinte de la révision est le SHA-256 de la forme canonique du snapshot.');
        $this->assertSame($body['snapshot_sha256'], RevisionHash::hash($snapshot['steps']), 'L\'empreinte des rubriques est celle que le poste a calculée sur ce qu\'il a lu.');
        $this->assertSame([$this->consultationId, 1, $this->visitId, $this->patientId, $this->clinician->id], [$snapshot['consultation_id'], $snapshot['revision_no'], $snapshot['visit_id'], $snapshot['patient']['patient_id'], $snapshot['author']['user_id']]);
        $this->assertSame(['ANAMNESIS', 'HISTORIES', 'VISUAL_ACUITY', 'REFRACTION', 'CENTERING', 'MEDICATIONS', 'CONCLUSION'], array_keys($snapshot['steps']));
        $this->assertNull($snapshot['previous_revision_id']);
        $this->assertNull($snapshot['amendment_reason']);
        $this->assertSame('Conclusion fictive', $snapshot['steps']['CONCLUSION']['conclusion']);

        $this->assertDataMatchesContract(json_decode((string) $revision->snapshot, false, 512, JSON_THROW_ON_ERROR), 'snapshots/clinical-revision.json');
    }

    public function test_les_mesures_non_realisees_ne_sont_jamais_exigees_ni_transformees_en_aucun(): void
    {
        $this->completeConsultation($this->consultationId)->assertOk();

        $steps = $this->stepOf($this->consultationId, 'VISUAL_ACUITY');
        $this->assertSame(['acuities' => []], $steps);
        $this->assertSame(0, DB::table('visual_acuities')->count());
        $this->assertSame(0, DB::table('refraction_measurements')->count());
        $this->assertSame(0, DB::table('autorefractor_centering')->count());
    }

    public function test_les_mesures_saisies_sont_figees_dans_le_snapshot_a_l_identique(): void
    {
        $scale = $this->seedScale();
        $this->saveCurrent($this->consultationId, 'VISUAL_ACUITY', ['acuities' => [$this->acuity($scale, '10/10', 'FAR', 'WITHOUT', 'OD')]])->assertOk();
        $this->saveCurrent($this->consultationId, 'REFRACTION', $this->refraction(['measurements' => [$this->measurement('SUBJECTIVE', 'OD', '-1.25', '-0.50', 90, '2')]]))->assertOk();
        $this->saveCurrent($this->consultationId, 'MEDICATIONS', ['orders' => [$this->order('Collyre fictif')]])->assertOk();
        $body = $this->completeBody($this->consultationId);

        $this->completeConsultation($this->consultationId, $body)->assertOk();

        $snapshot = $this->readRevisionSnapshot(1);
        $this->assertSame('10/10', $snapshot['steps']['VISUAL_ACUITY']['acuities'][0]['raw_value']);
        $this->assertSame(['-1.25', '-0.50', 90, '2.00'], [
            $snapshot['steps']['REFRACTION']['measurements'][0]['sphere'], $snapshot['steps']['REFRACTION']['measurements'][0]['cylinder'],
            $snapshot['steps']['REFRACTION']['measurements'][0]['axis'], $snapshot['steps']['REFRACTION']['measurements'][0]['addition'],
        ]);
        $this->assertSame('Collyre fictif', $snapshot['steps']['MEDICATIONS']['orders'][0]['name']);
    }

    public function test_les_effets_durables_sont_l_audit_sans_contenu_l_evenement_minimal_et_le_journal_des_changements(): void
    {
        $body = $this->completeBody($this->consultationId);
        $this->completeConsultation($this->consultationId, $body)->assertOk();

        $audit = DB::table('audit_events')->where('action', 'consultation.completed')->where('target_id', $this->consultationId)->first();
        $this->assertNotNull($audit);
        $diff = json_decode((string) $audit->sanitized_diff, true);
        $this->assertSame(['revision_id', 'revision_no', 'sha256'], collect($diff)->keys()->sort()->values()->all());
        $this->assertSame(1, $diff['revision_no']);

        $event = DB::table('outbox_events')->where('clinic_id', $this->clinicId)->where('event_type', 'ConsultationCompleted')->first();
        $this->assertNotNull($event);
        $payload = json_decode((string) $event->payload, true);
        $this->assertSame(['completed_at', 'consultation_id', 'patient_id', 'revision_id', 'revision_no', 'visit_id'], collect($payload)->keys()->sort()->values()->all());
        $this->assertSame($this->patientId, $payload['patient_id']);

        $this->assertSame(1, DB::table('sync_change_log')->where('entity_type', 'clinical_revisions')->where('entity_id', $body['revision_id'])->count());
        $this->assertSame(1, DB::table('sync_change_log')->where('entity_type', 'visits')->where('entity_id', $this->visitId)->where('entity_version', '>=', 3)->count());
        $this->assertStringNotContainsString('Conclusion fictive', $this->sideEffectsDump());
    }

    public function test_aucun_controle_de_paiement_ni_aucune_facture_n_est_cree(): void
    {
        $counts = [DB::table('invoices')->count(), DB::table('payments')->count(), DB::table('stock_movements')->count()];

        $this->completeConsultation($this->consultationId)->assertOk();

        $this->assertSame($counts, [DB::table('invoices')->count(), DB::table('payments')->count(), DB::table('stock_movements')->count()]);
    }

    public function test_une_consultation_brouillon_sans_rubrique_ne_se_termine_pas(): void
    {
        [, $draftId] = $this->startedExam();
        $body = [
            'expected_version' => 1, 'consultation_id' => $draftId, 'revision_id' => Ulid::generate(), 'revision_no' => 1,
            'snapshot_sha256' => str_repeat('a', 64), 'finalization_missing_data_reason' => 'Rien',
        ];

        $this->completeConsultation($draftId, $body)->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');

        $this->assertSame('DRAFT', DB::table('consultations')->where('id', $draftId)->value('status'));
        $this->assertSame(0, DB::table('clinical_revisions')->where('consultation_id', $draftId)->count());
    }

    public function test_une_consultation_deja_terminee_est_refusee_et_sa_revision_reste_unique(): void
    {
        $first = $this->completeBody($this->consultationId);
        $this->completeConsultation($this->consultationId, $first)->assertOk();

        $second = [...$first, 'expected_version' => 4, 'revision_id' => Ulid::generate()];
        $this->completeConsultation($this->consultationId, $second)->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');

        $this->assertSame(1, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
    }

    public function test_une_version_perimee_est_un_conflit_avec_la_version_actuelle_et_rien_n_est_ecrit(): void
    {
        $body = $this->completeBody($this->consultationId);
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['conclusion' => 'Modifiée par un autre poste']))->assertOk();
        $before = $this->clinicalEffectCounts();

        $conflict = $this->completeConsultation($this->consultationId, $body);

        $conflict->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT')->assertJsonPath('error.details.current_version', 4);
        $this->assertMatchesContract($conflict, 'schemas/error.json');
        $this->assertSame($before, $this->clinicalEffectCounts());
        $this->assertSame('IN_PROGRESS', DB::table('consultations')->where('id', $this->consultationId)->value('status'));
    }

    public function test_une_empreinte_qui_n_est_plus_celle_du_contenu_est_refusee(): void
    {
        $body = $this->completeBody($this->consultationId);
        // Une écriture qui garde la même version est impossible : on altère l'empreinte envoyée, comme le ferait un
        // poste qui aurait relu un état antérieur.
        $stale = [...$body, 'snapshot_sha256' => str_repeat('0', 64)];

        $this->assertFieldError($this->completeConsultation($this->consultationId, $stale), 'snapshot_sha256');

        $this->assertSame('IN_PROGRESS', DB::table('consultations')->where('id', $this->consultationId)->value('status'));
        $this->assertSame(0, DB::table('clinical_revisions')->count());
    }

    public function test_le_numero_de_revision_est_1_et_l_identifiant_de_revision_est_neuf(): void
    {
        $body = $this->completeBody($this->consultationId);

        $this->assertFieldError($this->completeConsultation($this->consultationId, [...$body, 'revision_no' => 2]), 'revision_no');
        $this->assertFieldError($this->completeConsultation($this->consultationId, [...$body, 'revision_no' => 0]), 'revision_no');

        // Un identifiant de révision déjà pris par une autre consultation est un refus lisible, jamais une erreur SQL.
        [, $otherId] = $this->completableExamForOtherPatient();
        $other = $this->completeBody($otherId);
        $this->completeConsultation($otherId, $other)->assertOk();
        $this->assertFieldError($this->completeConsultation($this->consultationId, [...$body, 'revision_id' => $other['revision_id']]), 'revision_id');
        $this->assertSame(0, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
    }

    public function test_le_motif_et_la_conclusion_absents_exigent_une_justification(): void
    {
        [, $consultationId] = $this->startedExam();
        $this->saveCurrent($consultationId, 'MEDICATIONS', ['orders' => [$this->order('Collyre fictif')]])->assertOk();
        $body = $this->completeBody($consultationId);

        $this->assertFieldError($this->completeConsultation($consultationId, $body), 'finalization_missing_data_reason');
        $this->assertSame('IN_PROGRESS', DB::table('consultations')->where('id', $consultationId)->value('status'));

        $this->completeConsultation($consultationId, [...$body, 'finalization_missing_data_reason' => 'Patient parti avant la conclusion (fictif)'])->assertOk();
        $this->assertSame('Patient parti avant la conclusion (fictif)', DB::table('consultations')->where('id', $consultationId)->value('finalization_missing_data_reason'));
        $this->assertSame('Patient parti avant la conclusion (fictif)', $this->readRevisionSnapshot(1, $consultationId)['finalization_missing_data_reason']);
    }

    public function test_la_justification_est_bornee(): void
    {
        [, $consultationId] = $this->startedExam();
        $this->saveCurrent($consultationId, 'MEDICATIONS', ['orders' => [$this->order('Collyre fictif')]])->assertOk();
        $body = $this->completeBody($consultationId);

        $this->assertFieldError($this->completeConsultation($consultationId, [...$body, 'finalization_missing_data_reason' => str_repeat('x', 4001)]), 'finalization_missing_data_reason');
        $this->assertFieldError($this->completeConsultation($consultationId, [...$body, 'finalization_missing_data_reason' => "Motif\x07"]), 'finalization_missing_data_reason');
        $this->assertSame(0, DB::table('clinical_revisions')->count());
    }

    public function test_un_etat_enregistre_qui_viole_les_regles_actuelles_empeche_la_finalisation(): void
    {
        // Centrage écrit hors du chemin de saisie : OD + OG ne fait pas le total et aucune justification n'existe.
        $now = '2026-10-22 09:31:00.000000';
        DB::table('autorefractor_centering')->insert([
            'id' => Ulid::generate(), 'clinic_id' => $this->clinicId, 'consultation_id' => $this->consultationId,
            'pd_total' => '62.00', 'pd_od' => '31.00', 'pd_og' => '30.00', 'inconsistency_reason' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $body = $this->completeBody($this->consultationId);

        $this->assertFieldError($this->completeConsultation($this->consultationId, $body), 'steps.CENTERING.inconsistency_reason');

        $this->assertSame('IN_PROGRESS', DB::table('consultations')->where('id', $this->consultationId)->value('status'));
        $this->assertSame(0, DB::table('clinical_revisions')->count());
    }

    public function test_un_code_de_plainte_desactive_depuis_ne_bloque_pas_la_finalisation(): void
    {
        DB::table('clinical_terms')->where('group_code', 'COMPLAINT')->where('code', 'NEAR_DIFFICULTY')->update(['active' => false]);
        $body = $this->completeBody($this->consultationId);

        // Le code désactivé reste valable pour la ligne déjà enregistrée.
        $this->completeConsultation($this->consultationId, $body)->assertOk();
    }

    public function test_l_identifiant_du_chemin_et_du_corps_doivent_coincider(): void
    {
        $body = $this->completeBody($this->consultationId);
        $other = Ulid::generate();

        $this->assertFieldError($this->completeConsultation($this->consultationId, [...$body, 'consultation_id' => $other]), 'consultation_id');
        $this->assertSame(0, DB::table('clinical_revisions')->count());
    }

    public function test_un_rejeu_de_la_meme_cle_ne_cree_qu_une_revision_et_une_autre_charge_est_refusee(): void
    {
        $body = $this->completeBody($this->consultationId);
        $key = Ulid::generate();

        $first = $this->completeConsultation($this->consultationId, $body, null, $key)->assertOk();
        $replay = $this->completeConsultation($this->consultationId, $body, null, $key)->assertOk();

        $this->assertSame($first->json('data.revisions'), $replay->json('data.revisions'));
        $this->assertSame(1, DB::table('clinical_revisions')->where('consultation_id', $this->consultationId)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'consultation.completed')->where('target_id', $this->consultationId)->count());

        $this->completeConsultation($this->consultationId, [...$body, 'revision_id' => Ulid::generate()], null, $key)
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }

    public function test_les_corps_incomplets_ou_inconnus_sont_refuses_par_le_contrat(): void
    {
        $body = $this->completeBody($this->consultationId);
        $missing = $body;
        unset($missing['snapshot_sha256']);

        $this->assertFieldError($this->completeConsultation($this->consultationId, $missing), 'snapshot_sha256');
        $this->assertFieldError($this->completeConsultation($this->consultationId, [...$body, 'snapshot_sha256' => 'ABC']), 'snapshot_sha256');
        $this->assertFieldError($this->completeConsultation($this->consultationId, [...$body, 'champ_inconnu' => 1]), 'champ_inconnu');
        $this->assertSame(0, DB::table('clinical_revisions')->count());
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function roles(): array
    {
        return ['gérante' => ['MANAGER', 200], 'professionnel' => ['CLINICIAN', 200], 'accueil' => ['RECEPTION', 403], 'caisse' => ['CASHIER', 403]];
    }

    #[DataProvider('roles')]
    public function test_seuls_la_gerante_et_le_professionnel_terminent_une_consultation(string $role, int $status): void
    {
        $user = $this->makeStaff($this->clinicId, $role);
        $body = $this->completeBody($this->consultationId);

        $this->completeConsultation($this->consultationId, $body, $user)->assertStatus($status);

        $this->assertSame($status === 200 ? 1 : 0, DB::table('clinical_revisions')->count());
    }

    public function test_sans_le_droit_de_finaliser_ou_de_lire_la_demande_est_refusee(): void
    {
        $body = $this->completeBody($this->consultationId);

        $this->denyPermission($this->clinician, 'clinical.complete');
        $this->completeConsultation($this->consultationId, $body)->assertStatus(403);

        $other = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.sans.lecture');
        $this->denyPermission($other, 'clinical.read');
        $this->completeConsultation($this->consultationId, $body, $other)->assertStatus(403);

        $this->assertSame(0, DB::table('clinical_revisions')->count());
        $this->assertSame('IN_PROGRESS', DB::table('consultations')->where('id', $this->consultationId)->value('status'));
    }

    public function test_une_consultation_d_un_autre_cabinet_repond_introuvable(): void
    {
        $otherClinic = $this->makeClinic();
        $otherClinician = $this->makeStaff($otherClinic, 'CLINICIAN', 'autre.pro.final');
        $body = $this->completeBody($this->consultationId);

        $this->completeConsultation($this->consultationId, $body, $otherClinician)->assertNotFound();

        $this->assertSame(0, DB::table('clinical_revisions')->count());
    }

    public function test_un_module_ferme_repond_404(): void
    {
        $body = $this->completeBody($this->consultationId);
        config(['eeo.enabled_modules' => ['today', 'patients']]);

        $this->completeConsultation($this->consultationId, $body, $this->manager)->assertNotFound();
    }

    public function test_la_revision_est_immuable_dans_la_base(): void
    {
        $body = $this->completeBody($this->consultationId);
        $this->completeConsultation($this->consultationId, $body)->assertOk();

        try {
            DB::table('clinical_revisions')->where('id', $body['revision_id'])->update(['reason' => 'Réécrite']);
            $this->fail('Une révision figée ne peut pas être modifiée.');
        } catch (QueryException) {
            // Attendu : trigger de la base.
        }
        try {
            DB::table('clinical_revisions')->where('id', $body['revision_id'])->delete();
            $this->fail('Une révision figée ne peut pas être supprimée.');
        } catch (QueryException) {
            // Attendu : trigger de la base.
        }
        $this->assertSame('Finalisation de la consultation', DB::table('clinical_revisions')->where('id', $body['revision_id'])->value('reason'));
    }

    public function test_une_consultation_terminee_ne_se_modifie_plus_par_une_saisie_simple(): void
    {
        $this->completeConsultation($this->consultationId)->assertOk();

        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['conclusion' => 'Réécriture directe']))
            ->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');

        $this->assertSame('Conclusion fictive', DB::table('consultations')->where('id', $this->consultationId)->value('conclusion'));
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
    public function test_un_echec_a_n_importe_quel_point_annule_la_finalisation_entiere(Checkpoint $failAt): void
    {
        $body = $this->completeBody($this->consultationId);
        $before = $this->clinicalEffectCounts();
        $this->app->instance(CommandBusProbe::class, new RecordingProbe($failAt));
        $this->withoutExceptionHandling();

        try {
            $this->completeConsultation($this->consultationId, $body);
            $this->fail('Une erreur injectée devait annuler la commande.');
        } catch (\RuntimeException) {
            // Attendu : la transaction est annulée en entier.
        }

        $this->assertSame($before, $this->clinicalEffectCounts());
        $this->assertSame('IN_PROGRESS', DB::table('consultations')->where('id', $this->consultationId)->value('status'));
        $this->assertSame('IN_CONSULTATION', DB::table('visits')->where('id', $this->visitId)->value('status'));

        // La même demande aboutit ensuite.
        $this->app->instance(CommandBusProbe::class, new RecordingProbe);
        $this->completeConsultation($this->consultationId, $body)->assertOk();
    }

    public function test_les_verrous_sont_pris_dans_l_ordre_du_contrat_cabinet_visite_consultation(): void
    {
        $body = $this->completeBody($this->consultationId);
        $expected = (int) $body['expected_version'];
        unset($body['expected_version']);
        $locks = new TransactionLocks($this->clinicId);
        $locks->clinic();

        $this->app->make(CompleteConsultationHandler::class)->handle(
            new Command('CompleteConsultation', $body, $this->consultationId, $expected),
            CommandContext::direct($this->clinician, 'corr-test-0001'),
            $locks,
            null,
        );

        $this->assertSame(['10:'.$this->clinicId, '46:'.$this->visitId, '47:'.$this->consultationId], $locks->acquired());
    }

    public function test_un_poste_hors_ligne_ou_synchronise_est_refuse(): void
    {
        $body = $this->completeBody($this->consultationId);
        $expected = (int) $body['expected_version'];
        unset($body['expected_version']);

        foreach ([CommandContext::job($this->clinicId, 'test', 'corr-test', CarbonImmutable::now('UTC')), $this->syncContextFor($this->clinician)] as $context) {
            try {
                $this->app->make(CompleteConsultationHandler::class)->handle(new Command('CompleteConsultation', $body, $this->consultationId, $expected), $context, new TransactionLocks($this->clinicId), null);
                $this->fail('Une source autre que la route directe devait être refusée.');
            } catch (BusinessError $error) {
                $this->assertSame('ACTION_FORBIDDEN', $error->errorCode);
            }
        }
        $this->assertSame('IN_PROGRESS', DB::table('consultations')->where('id', $this->consultationId)->value('status'));
    }

    /**
     * Snapshot décodé d'une révision de la consultation.
     *
     * @return array<string, mixed>
     */
    private function readRevisionSnapshot(int $revisionNo, ?string $consultationId = null): array
    {
        $snapshot = DB::table('clinical_revisions')
            ->where('consultation_id', $consultationId ?? $this->consultationId)
            ->where('revision_no', $revisionNo)
            ->value('snapshot');

        return (array) json_decode((string) $snapshot, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Examen prêt à terminer pour un autre patient : [visite, consultation].
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
