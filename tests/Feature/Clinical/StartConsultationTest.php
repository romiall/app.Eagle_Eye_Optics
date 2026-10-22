<?php

namespace Tests\Feature\Clinical;

use App\Application\Checkpoint;
use App\Application\Command;
use App\Application\CommandBusProbe;
use App\Application\Locking\TransactionLocks;
use App\Domain\Clinical\Handlers\StartConsultationHandler;
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
 * Démarrage de l'examen d'une visite (C01, AT18) sur MySQL réel, SANS contrôle du paiement de l'examen (B04 et
 * B06 n'existent pas) : consultation en brouillon, visite « en consultation », une seule consultation par
 * visite, motif d'examen ou de suivi seulement, droits cliniques, isolation des cabinets, idempotence, retour
 * arrière à chaque point du chemin, aucune donnée clinique dans les effets de bord. Données fictives.
 */
final class StartConsultationTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClinical();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    public function test_le_demarrage_cree_la_consultation_en_brouillon_et_passe_la_visite_en_consultation(): void
    {
        $visitId = $this->seedVisit();
        $body = $this->startBody($visitId, ['effective_at' => '2026-10-22T09:20:00.000Z']);

        $response = $this->startConsultation($visitId, $body);

        $response->assertCreated()
            ->assertJsonPath('data.id', $body['consultation_id'])
            ->assertJsonPath('data.visit_id', $visitId)
            ->assertJsonPath('data.patient_id', $this->patientId)
            ->assertJsonPath('data.clinician_id', $this->clinician->id)
            ->assertJsonPath('data.effective_at', '2026-10-22T09:20:00.000Z')
            ->assertJsonPath('data.date_change_reason', null)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.current_revision', 0)
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.revisions', []);
        $this->assertMatchesContract($response, 'schemas/resources/clinical.json#/$defs/ConsultationResponse');

        $row = DB::table('consultations')->where('id', $body['consultation_id'])->first();
        $this->assertNotNull($row);
        $this->assertSame($this->clinicId, $row->clinic_id);
        $this->assertSame('DRAFT', $row->status);
        $this->assertSame(0, (int) $row->current_revision);
        $this->assertSame(1, (int) $row->version);
        $this->assertSame($this->clinician->id, $row->clinician_id, 'Le professionnel est le compte connecté, jamais un champ de la charge.');

        $visit = DB::table('visits')->where('id', $visitId)->first();
        $this->assertNotNull($visit);
        $this->assertSame('IN_CONSULTATION', $visit->status);
        $this->assertSame(2, (int) $visit->version);
        $this->assertNull($visit->exam_invoice_id, 'Aucune preuve de paiement n\'est enregistrée : le contrôle n\'existe pas.');
        $this->assertNull($visit->exam_waiver_approval_id);

        $audit = DB::table('audit_events')->where('action', 'consultation.started')->where('target_id', $body['consultation_id'])->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->clinician->id, $audit->actor_id);
        $diff = json_decode((string) $audit->sanitized_diff, true);
        $this->assertSame('NOT_CONTROLLED', $diff['exam_fee_control']);
        $this->assertSame($visitId, $diff['visit_id']);

        $changes = DB::table('sync_change_log')->where('clinic_id', $this->clinicId)->orderBy('change_sequence')->get();
        $this->assertSame(
            [['visits', $visitId, 2, 'ADMINISTRATIVE', 'UPDATED'], ['consultations', $body['consultation_id'], 1, 'CLINICAL', 'CREATED']],
            $changes->map(static fn (object $change): array => [$change->entity_type, $change->entity_id, (int) $change->entity_version, $change->visibility, $change->change_kind])->all(),
        );
        $this->assertSame(0, DB::table('outbox_events')->where('clinic_id', $this->clinicId)->count(), 'Aucun événement n\'existe pour le démarrage.');
    }

    public function test_les_etapes_non_saisies_sont_rendues_vides_et_conformes_au_schema(): void
    {
        $visitId = $this->seedVisit();

        $steps = $this->startConsultation($visitId)->assertCreated()->json('data.steps');

        $this->assertSame(['ANAMNESIS', 'HISTORIES', 'VISUAL_ACUITY', 'REFRACTION', 'CENTERING', 'CONCLUSION'], array_keys($steps));
        $this->assertSame(['symptoms' => null, 'wears_glasses' => null, 'glasses_duration' => null, 'previous_correction_available' => null, 'complaints' => []], $steps['ANAMNESIS']);
        $this->assertSame(['histories' => []], $steps['HISTORIES']);
        $this->assertSame(['acuities' => []], $steps['VISUAL_ACUITY']);
        $this->assertSame(['measurements' => [], 'refraction_observations' => null, 'ametropias' => []], $steps['REFRACTION']);
        $this->assertSame(['centering_id' => null, 'pd_total' => null, 'pd_od' => null, 'pd_og' => null, 'inconsistency_reason' => null], $steps['CENTERING']);
        $this->assertSame(['conclusion' => null, 'referral' => null, 'referral_urgency' => null, 'referral_reasons' => []], $steps['CONCLUSION']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function startableVisits(): array
    {
        return [
            'examen arrivé' => ['EXAM', 'ARRIVED'],
            'suivi arrivé' => ['FOLLOW_UP', 'ARRIVED'],
            'examen prêt (futur contrôle de paiement)' => ['EXAM', 'READY_FOR_EXAM'],
        ];
    }

    #[DataProvider('startableVisits')]
    public function test_un_examen_ou_un_suivi_arrive_ou_pret_peut_demarrer(string $reason, string $status): void
    {
        $visitId = $this->seedVisit(['reason' => $reason, 'status' => $status]);

        $this->startConsultation($visitId)->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $this->assertSame('IN_CONSULTATION', DB::table('visits')->where('id', $visitId)->value('status'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonExamReasons(): array
    {
        return ['achat direct' => ['DIRECT_PURCHASE'], 'retrait' => ['PICKUP']];
    }

    #[DataProvider('nonExamReasons')]
    public function test_un_achat_ou_un_retrait_ne_cree_jamais_de_consultation(string $reason): void
    {
        $visitId = $this->seedVisit(['reason' => $reason]);

        $this->startConsultation($visitId)->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['visit_id']]]);

        $this->assertSame(0, DB::table('consultations')->count());
        $this->assertSame('ARRIVED', DB::table('visits')->where('id', $visitId)->value('status'));
        $this->assertSame(1, (int) DB::table('visits')->where('id', $visitId)->value('version'));
    }

    public function test_une_seconde_tentative_sous_une_autre_cle_est_un_refus_d_etat_et_non_une_erreur_sql(): void
    {
        $visitId = $this->seedVisit();
        $this->startConsultation($visitId)->assertCreated();

        $second = $this->startConsultation($visitId);

        $second->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED')
            ->assertJsonPath('error.details.current_status', 'IN_CONSULTATION');
        $this->assertMatchesContract($second, 'schemas/error.json');
        $this->assertSame(1, DB::table('consultations')->where('visit_id', $visitId)->count());
        $this->assertSame(2, (int) DB::table('visits')->where('id', $visitId)->value('version'), 'Le refus n\'a pas touché la visite.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notStartableStatuses(): array
    {
        return [
            'en attente du paiement' => ['AWAITING_EXAM_FEE'],
            'déjà en consultation' => ['IN_CONSULTATION'],
            'terminée' => ['COMPLETED'],
            'annulée' => ['CANCELLED'],
        ];
    }

    #[DataProvider('notStartableStatuses')]
    public function test_une_visite_a_un_autre_statut_n_est_pas_demarrable(string $status): void
    {
        $visitId = $this->seedVisit(['status' => $status]);

        $this->startConsultation($visitId)->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED')
            ->assertJsonPath('error.details.current_status', $status);

        $this->assertSame(0, DB::table('consultations')->count());
        $this->assertSame($status, DB::table('visits')->where('id', $visitId)->value('status'));
    }

    public function test_un_rejeu_de_la_meme_cle_ne_cree_qu_une_consultation(): void
    {
        $visitId = $this->seedVisit();
        $body = $this->startBody($visitId);
        $key = Ulid::generate();

        $first = $this->startConsultation($visitId, $body, null, $key)->assertCreated();
        $replay = $this->startConsultation($visitId, $body, null, $key)->assertCreated();

        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertSame(1, DB::table('consultations')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'consultation.started')->count());
        $this->assertSame(2, (int) DB::table('visits')->where('id', $visitId)->value('version'), 'La visite n\'est avancée qu\'une fois.');
    }

    public function test_la_meme_cle_avec_un_autre_contenu_est_refusee_et_rien_n_est_ecrit(): void
    {
        $visitId = $this->seedVisit();
        $body = $this->startBody($visitId);
        $key = Ulid::generate();
        $this->startConsultation($visitId, $body, null, $key)->assertCreated();

        $this->startConsultation($visitId, [...$body, 'effective_at' => '2026-10-22T09:25:00.000Z'], null, $key)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');

        $this->assertSame(1, DB::table('consultations')->count());
        $this->assertSame('2026-10-22 09:20:00.000000', DB::table('consultations')->value('effective_at'));
    }

    public function test_un_autre_patient_que_celui_de_la_visite_est_refuse(): void
    {
        $visitId = $this->seedVisit();
        $other = $this->seedPatient($this->clinicId, 'Autre Patient');

        $this->startConsultation($visitId, $this->startBody($visitId, ['patient_id' => $other]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['patient_id']]]);

        $this->assertSame(0, DB::table('consultations')->count());
    }

    public function test_les_identifiants_du_chemin_et_du_corps_doivent_coincider(): void
    {
        $visitId = $this->seedVisit();
        $otherVisit = $this->seedVisit();

        $this->startConsultation($visitId, $this->startBody($otherVisit), null, null, $visitId)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['visit_id']]]);

        $this->assertSame(0, DB::table('consultations')->count());
    }

    public function test_une_visite_inconnue_ou_d_un_autre_cabinet_repond_introuvable(): void
    {
        $otherClinic = $this->makeClinic();
        $this->makeStaff($otherClinic, 'MANAGER', 'autre.gerante');
        $foreignVisit = $this->seedVisit([], $otherClinic);
        $unknown = Ulid::generate();

        $this->startConsultation($foreignVisit, $this->startBody($foreignVisit))->assertNotFound();
        $this->startConsultation($unknown, $this->startBody($unknown))->assertNotFound();
        $this->actingAsFresh($this->clinician)->postJson('/api/v1/visits/pas-un-ulid/consultations', $this->startBody($unknown))->assertNotFound();

        $this->assertSame(0, DB::table('consultations')->count());
        $this->assertSame('ARRIVED', DB::table('visits')->where('id', $foreignVisit)->value('status'));
    }

    public function test_un_identifiant_de_consultation_deja_pris_est_refuse_sans_erreur_sql(): void
    {
        [, $takenId] = $this->startedExam();
        $visitId = $this->seedVisit();

        $this->startConsultation($visitId, $this->startBody($visitId, ['consultation_id' => $takenId]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['consultation_id']]]);

        $this->assertSame(1, DB::table('consultations')->count());
        $this->assertSame('ARRIVED', DB::table('visits')->where('id', $visitId)->value('status'));
    }

    public function test_la_date_effective_ne_peut_pas_etre_dans_le_futur_au_dela_de_la_tolerance_d_horloge(): void
    {
        config(['eeo.offline.max_clock_skew_seconds' => 300]);
        $visitId = $this->seedVisit();

        $this->startConsultation($visitId, $this->startBody($visitId, ['effective_at' => '2026-10-22T09:36:00.000Z']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['effective_at']]]);
        $this->assertSame(0, DB::table('consultations')->count());

        $this->startConsultation($visitId, $this->startBody($visitId, ['effective_at' => '2026-10-22T09:34:00.000Z']))->assertCreated();
    }

    public function test_une_saisie_retrospective_est_acceptee_avec_ou_sans_motif_et_le_motif_est_conserve(): void
    {
        $withReason = $this->seedVisit();
        $without = $this->seedVisit();

        $this->startConsultation($withReason, $this->startBody($withReason, ['effective_at' => '2026-10-20T14:00:00.000Z', 'date_change_reason' => "  Saisie du carnet papier\ndu 20 octobre  "]))
            ->assertCreated()
            ->assertJsonPath('data.effective_at', '2026-10-20T14:00:00.000Z')
            ->assertJsonPath('data.date_change_reason', "Saisie du carnet papier\ndu 20 octobre");
        $this->startConsultation($without, $this->startBody($without, ['effective_at' => '2026-10-20T14:00:00.000Z', 'date_change_reason' => '   ']))
            ->assertCreated()
            ->assertJsonPath('data.date_change_reason', null);
    }

    public function test_aucune_preuve_de_paiement_n_est_acceptee_tant_que_le_controle_n_existe_pas(): void
    {
        $visitId = $this->seedVisit();

        // Sans preuve : admis (le contrat l'autorise et le serveur dit « non contrôlé »).
        // Avec une preuve : refusée plutôt qu'ignorée, car l'écran laisserait croire qu'elle est enregistrée.
        $response = $this->startConsultation($visitId, $this->startBody($visitId, [
            'exam_invoice_id' => Ulid::generate(),
            'exam_waiver_approval_id' => Ulid::generate(),
        ]));

        $response->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['exam_invoice_id', 'exam_waiver_approval_id']]]);
        $this->assertSame(0, DB::table('consultations')->count());
        $this->assertNull(DB::table('visits')->where('id', $visitId)->value('exam_invoice_id'));
    }

    public function test_le_corps_de_la_route_directe_est_valide_contre_son_contrat(): void
    {
        $visitId = $this->seedVisit();
        $absent = $this->startBody($visitId);
        unset($absent['expected_version']);

        foreach ([
            $this->startBody($visitId, ['expected_version' => 1]),
            $absent,
            $this->startBody($visitId, ['effective_at' => '22/10/2026 09:20']),
            $this->startBody($visitId, ['consultation_id' => 'pas-un-ulid']),
            $this->startBody($visitId, ['status' => 'COMPLETED']),
            $this->startBody($visitId, ['clinician_id' => $this->manager->id]),
            $this->startBody($visitId, ['date_change_reason' => str_repeat('x', 4001)]),
        ] as $body) {
            $this->startConsultation($visitId, $body)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->assertSame(0, DB::table('consultations')->count());
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function roles(): array
    {
        return ['gérante' => ['MANAGER', 201], 'professionnel' => ['CLINICIAN', 201], 'accueil' => ['RECEPTION', 403], 'caisse' => ['CASHIER', 403]];
    }

    #[DataProvider('roles')]
    public function test_seuls_la_gerante_et_le_professionnel_demarrent_un_examen(string $role, int $status): void
    {
        $visitId = $this->seedVisit();
        $user = $this->makeStaff($this->clinicId, $role);

        $this->startConsultation($visitId, [], $user)->assertStatus($status);

        $this->assertSame($status === 201 ? 1 : 0, DB::table('consultations')->count());
        $this->assertSame($status === 201 ? 'IN_CONSULTATION' : 'ARRIVED', DB::table('visits')->where('id', $visitId)->value('status'));
    }

    public function test_la_gerante_devient_le_professionnel_de_la_consultation_qu_elle_demarre(): void
    {
        $visitId = $this->seedVisit();

        $this->startConsultation($visitId, [], $this->manager)->assertCreated()->assertJsonPath('data.clinician_id', $this->manager->id);
    }

    public function test_un_module_ferme_repond_404_meme_pour_une_gerante(): void
    {
        $visitId = $this->seedVisit();
        config(['eeo.enabled_modules' => ['today', 'patients']]);

        $this->startConsultation($visitId, [], $this->manager)->assertNotFound();
        $this->assertSame(0, DB::table('consultations')->count());
    }

    public function test_le_droit_d_ecrire_retire_par_derogation_refuse_le_demarrage(): void
    {
        $visitId = $this->seedVisit();
        $this->denyPermission($this->clinician, 'clinical.write');

        $this->startConsultation($visitId)->assertStatus(403);
        $this->assertSame(0, DB::table('consultations')->count());
    }

    public function test_sans_le_droit_de_lire_le_demarrage_est_refuse_car_il_rend_la_consultation(): void
    {
        // Un rejeu de la clé relirait la consultation courante : sans clinical.read, le démarrage est refusé d'emblée.
        $first = $this->seedVisit();
        $second = $this->seedVisit();
        $this->startConsultation($first, [], $this->manager)->assertCreated();
        $this->denyPermission($this->clinician, 'clinical.read');

        $this->startConsultation($second)->assertStatus(403);

        $this->assertSame(1, DB::table('consultations')->count());
        $this->assertSame('ARRIVED', DB::table('visits')->where('id', $second)->value('status'));
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
    public function test_un_echec_a_n_importe_quel_point_annule_tout_y_compris_la_visite(Checkpoint $failAt): void
    {
        $visitId = $this->seedVisit();
        $before = $this->clinicalEffectCounts();
        $this->app->instance(CommandBusProbe::class, new RecordingProbe($failAt));
        $this->withoutExceptionHandling();

        try {
            $this->startConsultation($visitId);
            $this->fail('Une erreur injectée devait annuler la commande.');
        } catch (\RuntimeException) {
            // Attendu : la transaction est annulée en entier.
        }

        $this->assertSame($before, $this->clinicalEffectCounts());
        $visit = DB::table('visits')->where('id', $visitId)->first();
        $this->assertNotNull($visit);
        $this->assertSame('ARRIVED', $visit->status);
        $this->assertSame(1, (int) $visit->version);

        // La même demande aboutit ensuite : rien n'a été brûlé.
        $this->app->instance(CommandBusProbe::class, new RecordingProbe);
        $this->startConsultation($visitId)->assertCreated();
    }

    public function test_un_poste_hors_ligne_est_refuse_tant_que_la_synchronisation_clinique_n_existe_pas(): void
    {
        $visitId = $this->seedVisit();
        $context = CommandContext::job($this->clinicId, 'test', 'corr-test', CarbonImmutable::now('UTC'));
        $payload = $this->startBody($visitId);
        unset($payload['expected_version']);

        try {
            $this->app->make(StartConsultationHandler::class)->handle(new Command('StartConsultation', $payload), $context, new TransactionLocks($this->clinicId), null);
            $this->fail('Une source autre que la route directe devait être refusée.');
        } catch (BusinessError $error) {
            $this->assertSame('ACTION_FORBIDDEN', $error->errorCode);
            $this->assertStringContainsString('hors-ligne', $error->getMessage());
        }
        $this->assertSame(0, DB::table('consultations')->count());
    }

    public function test_les_verrous_sont_pris_dans_l_ordre_du_contrat_cabinet_visite_consultation(): void
    {
        $visitId = $this->seedVisit();
        $payload = $this->startBody($visitId);
        unset($payload['expected_version']);
        $locks = new TransactionLocks($this->clinicId);
        $locks->clinic();

        $this->app->make(StartConsultationHandler::class)->handle(
            new Command('StartConsultation', $payload, (string) $payload['consultation_id']),
            CommandContext::direct($this->clinician, 'corr-test-0001'),
            $locks,
            null,
        );

        $this->assertSame(['10:'.$this->clinicId, '46:'.$visitId, '47:'.$payload['consultation_id']], $locks->acquired());
    }

    public function test_les_effets_de_bord_ne_portent_aucune_donnee_clinique(): void
    {
        $visitId = $this->seedVisit();
        $this->startConsultation($visitId, $this->startBody($visitId, ['date_change_reason' => 'SENTINELLE-MOTIF-DATE-QX7']))->assertCreated();

        $this->assertStringNotContainsString('SENTINELLE-MOTIF-DATE-QX7', $this->sideEffectsDump());
        $result = json_decode((string) DB::table('idempotency_requests')->where('command_name', 'StartConsultation')->value('result_payload'), true);
        $this->assertSame(['consultation_id', 'status', 'version', 'visit_id'], collect($result)->keys()->sort()->values()->all(), 'Références seulement dans le résultat idempotent.');
    }

    public function test_la_visite_porte_la_consultation_pour_un_compte_clinique_et_la_cache_aux_autres(): void
    {
        $visitId = $this->seedVisit();
        $body = $this->startBody($visitId);
        $this->startConsultation($visitId, $body)->assertCreated();

        $clinical = $this->actingAsFresh($this->clinician)->getJson('/api/v1/visits?date=2026-10-22')->assertOk();
        $this->assertSame($body['consultation_id'], $clinical->json('data.0.consultation_id'));
        $this->assertSame('DRAFT', $clinical->json('data.0.consultation_status'));
        $this->assertSame('IN_CONSULTATION', $clinical->json('data.0.status'));
        $this->assertMatchesContract($clinical, 'schemas/resources/patients.json#/$defs/VisitPage');

        foreach ([$this->reception, $this->cashier] as $user) {
            $masked = $this->actingAsFresh($user)->getJson('/api/v1/visits?date=2026-10-22')->assertOk();
            $this->assertNull($masked->json('data.0.consultation_id'), 'La file ne révèle pas qu\'un examen est en cours.');
            $this->assertNull($masked->json('data.0.consultation_status'));
            $this->assertMatchesContract($masked, 'schemas/resources/patients.json#/$defs/VisitPage');
        }
    }
}
