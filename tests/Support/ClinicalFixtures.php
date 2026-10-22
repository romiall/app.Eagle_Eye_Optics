<?php

namespace Tests\Support;

use App\Domain\Access\EffectivePermissions;
use App\Domain\Clinical\RevisionHash;
use App\Domain\Clinical\RevisionSnapshot;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\GrantSnapshot;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Cabinet, comptes, patient, visites et appels FICTIFS pour les tests de la consultation clinique (C01).
 *
 * Horloge : `bootClinical()` fige l'horloge EN PREMIER, avant de créer les comptes. L'attribution d'un profil
 * porte l'instant courant et une permission n'est effective qu'à partir de cet instant : figer l'horloge dans le
 * passé APRÈS la création des comptes rendrait leurs profils « futurs » et tout répondrait 403. Un changement
 * d'horloge ultérieur dans un test ne va que vers le futur. Chaque test remet l'horloge en place dans tearDown.
 */
trait ClinicalFixtures
{
    use AccessFixtures;
    use PatientFixtures;
    use ValidatesContracts;

    protected string $clinicId;

    protected User $manager;

    protected User $clinician;

    protected User $reception;

    protected User $cashier;

    protected string $patientId;

    /**
     * @param  list<string>  $modules
     */
    protected function bootClinical(array $modules = ['today', 'patients', 'consultations']): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 09:30:00', 'UTC'));
        config(['eeo.enabled_modules' => $modules]);
        $this->clinicId = $this->makeClinic();
        $this->manager = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');
        $this->clinician = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.test');
        $this->reception = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        $this->cashier = $this->makeStaff($this->clinicId, 'CASHIER', 'caisse.test');
        $this->patientId = $this->seedPatient($this->clinicId);
    }

    protected function resetClock(): void
    {
        CarbonImmutable::setTestNow();
    }

    /**
     * Dérogation nominative qui retire une permission au compte (le cache des droits de la requête précédente
     * est oublié : en test, plusieurs requêtes partagent un même processus).
     */
    protected function denyPermission(User $user, string $permission): void
    {
        DB::table('user_permissions')->insert([
            'id' => Ulid::generate(),
            'clinic_id' => $this->clinicId,
            'user_id' => $user->id,
            'permission_code' => $permission,
            'allowed' => false,
            'assigned_by' => $this->manager->id,
            'reason' => 'Test',
            'expires_at' => null,
            'created_at' => '2026-01-01 00:00:00.000000',
            'updated_at' => '2026-01-01 00:00:00.000000',
        ]);
        $this->app->make(EffectivePermissions::class)->forget($user);
    }

    /**
     * Visite insérée directement (arrivée à 09:15 UTC, le jour du test).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function seedVisit(array $overrides = [], ?string $clinicId = null, ?string $patientId = null): string
    {
        $id = Ulid::generate();
        $clinicId ??= $this->clinicId;
        DB::table('visits')->insert($overrides + [
            'id' => $id,
            'clinic_id' => $clinicId,
            'patient_id' => $patientId ?? ($clinicId === $this->clinicId ? $this->patientId : $this->seedPatient($clinicId)),
            'reason' => 'EXAM',
            'occurred_at' => '2026-10-22 09:15:00.000000',
            'status' => 'ARRIVED',
            'appointment_id' => null,
            'exam_invoice_id' => null,
            'exam_waiver_approval_id' => null,
            'arrival_at' => '2026-10-22 09:15:00.000000',
            'closed_at' => null,
            'administrative_note' => null,
            'created_by' => DB::table('users')->where('clinic_id', $clinicId)->value('id'),
            'version' => 1,
            'created_at' => '2026-10-22 09:15:00.000000',
            'updated_at' => '2026-10-22 09:15:00.000000',
        ]);

        return (string) ($overrides['id'] ?? $id);
    }

    /**
     * Corps de la route directe POST /visits/{id}/consultations.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function startBody(string $visitId, array $overrides = []): array
    {
        return array_merge([
            'expected_version' => null,
            'consultation_id' => Ulid::generate(),
            'visit_id' => $visitId,
            'patient_id' => $this->patientId,
            'effective_at' => '2026-10-22T09:20:00.000Z',
            'date_change_reason' => null,
            'exam_invoice_id' => null,
            'exam_waiver_approval_id' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function startConsultation(string $visitId, array $body = [], ?User $as = null, ?string $key = null, ?string $pathVisitId = null): TestResponse
    {
        $body = $body === [] ? $this->startBody($visitId) : $body;

        return $this->actingAsFresh($as ?? $this->clinician)
            ->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])
            ->postJson('/api/v1/visits/'.($pathVisitId ?? $visitId).'/consultations', $body);
    }

    /**
     * Examen démarré par le professionnel sur une visite neuve : [visite, consultation].
     *
     * @return array{0: string, 1: string}
     */
    protected function startedExam(): array
    {
        $visitId = $this->seedVisit();
        $body = $this->startBody($visitId);
        $this->startConsultation($visitId, $body)->assertCreated();

        return [$visitId, (string) $body['consultation_id']];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveStep(string $consultationId, string $step, array $data, int $version, ?User $as = null, ?string $key = null): TestResponse
    {
        return $this->saveStepBody($consultationId, $step, [
            'expected_version' => $version,
            'consultation_id' => $consultationId,
            'step' => ['step' => $step, 'data' => $data],
        ], $as, $key);
    }

    /**
     * Enregistre une rubrique avec la version ACTUELLE de la consultation (lue en base).
     *
     * @param  array<string, mixed>  $data
     */
    protected function saveCurrent(string $consultationId, string $step, array $data, ?User $as = null): TestResponse
    {
        return $this->saveStep($consultationId, $step, $data, (int) DB::table('consultations')->where('id', $consultationId)->value('version'), $as);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function saveStepBody(string $consultationId, string $step, array $body, ?User $as = null, ?string $key = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->clinician)
            ->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])
            ->patchJson('/api/v1/consultations/'.$consultationId.'/steps/'.$step, $body);
    }

    protected function readConsultation(string $consultationId, ?User $as = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->clinician)->getJson('/api/v1/consultations/'.$consultationId);
    }

    /**
     * Ligne `{id, term_code, other_text}` d'une liste de termes.
     *
     * @return array{id: string, term_code: string, other_text: string|null}
     */
    protected function term(string $code, ?string $otherText = null, ?string $id = null): array
    {
        return ['id' => $id ?? Ulid::generate(), 'term_code' => $code, 'other_text' => $otherText];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function anamnesis(array $overrides = []): array
    {
        return array_merge([
            'symptoms' => null,
            'wears_glasses' => null,
            'glasses_duration' => null,
            'previous_correction_available' => null,
            'complaints' => [],
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $terms
     * @return array<string, mixed>
     */
    protected function history(string $group, bool $knownNone = false, array $terms = [], ?string $comments = null, ?string $id = null): array
    {
        return ['id' => $id ?? Ulid::generate(), 'history_group' => $group, 'known_none' => $knownNone, 'comments' => $comments, 'terms' => $terms];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function conclusion(array $overrides = []): array
    {
        return array_merge([
            'conclusion' => null,
            'referral' => null,
            'referral_urgency' => null,
            'referral_reasons' => [],
        ], $overrides);
    }

    /**
     * Tout ce que le chemin central a écrit ailleurs que dans les tables de la consultation (audit, outbox,
     * journal des changements, clés d'idempotence), sérialisé : c'est là qu'aucune valeur clinique ne doit figurer.
     */
    protected function sideEffectsDump(): string
    {
        $dump = '';
        foreach (['audit_events', 'outbox_events', 'sync_change_log', 'idempotency_requests'] as $table) {
            $dump .= $table.json_encode(DB::table($table)->where('clinic_id', $this->clinicId)->get(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return $dump;
    }

    /**
     * Effets durables d'une commande clinique, pour prouver qu'un retour arrière n'en laisse aucun.
     *
     * @return array<string, int>
     */
    protected function clinicalEffectCounts(): array
    {
        $count = fn (string $table): int => DB::table($table)->where('clinic_id', $this->clinicId)->count();

        return [
            'consultations' => $count('consultations'),
            'complaints' => $count('consultation_complaints'),
            'histories' => $count('consultation_histories'),
            'history_terms' => $count('consultation_history_terms'),
            'referral_reasons' => $count('consultation_referral_reasons'),
            'acuities' => $count('visual_acuities'),
            'measurements' => $count('refraction_measurements'),
            'ametropias' => $count('consultation_ametropias'),
            'centering' => $count('autorefractor_centering'),
            'medications' => $count('medication_orders'),
            'revisions' => $count('clinical_revisions'),
            'audit' => DB::table('audit_events')->where('clinic_id', $this->clinicId)->where('action', 'like', 'consultation.%')->count(),
            'changes' => $count('sync_change_log'),
            'idempotency' => $count('idempotency_requests'),
        ];
    }

    /**
     * Contexte d'une commande de poste (source SYNC) pour un compte : les actions cliniques le refusent tant que la
     * synchronisation clinique n'existe pas (O01 à O05).
     */
    protected function syncContextFor(User $actor): CommandContext
    {
        $grant = new GrantSnapshot(
            Ulid::generate(), Ulid::generate(), $actor->id, ['clinical.write', 'clinical.complete', 'clinical.amend', 'clinical.read'], 'rules-test', 'catalog-test',
            CarbonImmutable::parse('2026-10-22 08:00:00', 'UTC'), CarbonImmutable::parse('2026-10-22 20:00:00', 'UTC'),
        );

        return CommandContext::sync(
            $this->clinicId, $grant->deviceId, $actor->id, $actor->display_name, $grant, 'corr-sync-clin',
            CarbonImmutable::parse('2026-10-22 09:30:00', 'UTC'), CarbonImmutable::parse('2026-10-22 09:00:00', 'UTC'),
            CarbonImmutable::parse('2026-10-22 09:30:00', 'UTC'),
        );
    }

    /** Échelle d'acuité fictive du cabinet (identifiant rendu). */
    protected function seedScale(string $code = 'TEST_ECHELLE', bool $active = true, ?string $clinicId = null): string
    {
        $id = Ulid::generate();
        DB::table('visual_acuity_scales')->insert([
            'id' => $id, 'clinic_id' => $clinicId ?? $this->clinicId, 'code' => $code, 'label' => 'Échelle '.$code.' (fictive)', 'description' => null,
            'active' => $active, 'created_at' => '2026-10-22 08:00:00.000000', 'updated_at' => '2026-10-22 08:00:00.000000',
        ]);

        return $id;
    }

    /**
     * Ligne d'acuité.
     *
     * @return array<string, mixed>
     */
    protected function acuity(string $scaleId, string $rawValue, string $distance = 'FAR', string $correction = 'WITHOUT', string $eye = 'OD', string $context = 'PRELIMINARY', ?string $id = null): array
    {
        return [
            'id' => $id ?? Ulid::generate(), 'context' => $context, 'distance' => $distance, 'correction' => $correction, 'eye' => $eye,
            'scale_id' => $scaleId, 'raw_value' => $rawValue, 'normalized_value' => null,
        ];
    }

    /**
     * Mesure de réfraction.
     *
     * @return array<string, mixed>
     */
    protected function measurement(string $stage, string $eye, ?string $sphere = null, ?string $cylinder = null, ?int $axis = null, ?string $addition = null, ?string $id = null): array
    {
        return ['id' => $id ?? Ulid::generate(), 'stage' => $stage, 'eye' => $eye, 'sphere' => $sphere, 'cylinder' => $cylinder, 'axis' => $axis, 'addition' => $addition];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function refraction(array $overrides = []): array
    {
        return array_merge(['measurements' => [], 'refraction_observations' => null, 'ametropias' => []], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function centering(array $overrides = []): array
    {
        return array_merge(['centering_id' => null, 'pd_total' => null, 'pd_od' => null, 'pd_og' => null, 'inconsistency_reason' => null], $overrides);
    }

    /**
     * Ligne de médicament (saisie libre).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function order(string $name, array $overrides = []): array
    {
        return array_merge([
            'id' => Ulid::generate(), 'catalog_item_id' => null, 'name' => $name, 'form' => 'DROPS', 'form_other' => null, 'strength' => null,
            'dosage' => null, 'frequency' => null, 'duration_text' => null, 'duration_value' => null, 'duration_unit' => null, 'instructions' => null,
        ], $overrides);
    }

    /**
     * Examen en cours avec motif et conclusion renseignés (prêt à terminer sans justification) : [visite, consultation].
     *
     * @return array{0: string, 1: string}
     */
    protected function completableExam(): array
    {
        [$visitId, $consultationId] = $this->startedExam();
        $this->saveCurrent($consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Gêne en vision de près (fictif)', 'complaints' => [$this->term('NEAR_DIFFICULTY')]]))->assertOk();
        $this->saveCurrent($consultationId, 'CONCLUSION', $this->conclusion(['conclusion' => 'Conclusion fictive', 'referral' => false]))->assertOk();

        return [$visitId, $consultationId];
    }

    /**
     * Corps de finalisation (route directe) : l'empreinte est celle des rubriques lues, comme le poste la calcule.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function completeBody(string $consultationId, array $overrides = []): array
    {
        $read = $this->readConsultation($consultationId)->assertOk();

        return array_merge([
            'expected_version' => (int) $read->json('data.version'),
            'consultation_id' => $consultationId,
            'revision_id' => Ulid::generate(),
            'revision_no' => 1,
            'snapshot_sha256' => RevisionSnapshot::stepsHash($read->json('data.steps')),
            'finalization_missing_data_reason' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function completeConsultation(string $consultationId, ?array $body = null, ?User $as = null, ?string $key = null, ?string $pathId = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->clinician)
            ->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])
            ->postJson('/api/v1/consultations/'.($pathId ?? $consultationId).'/complete', $body ?? $this->completeBody($consultationId));
    }

    /**
     * Corps de correction (route directe) : une ou plusieurs rubriques complètes, motif, empreinte de la requête.
     *
     * @param  array<string, array<string, mixed>>  $stepsByName  nom de rubrique → données
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function amendBody(string $consultationId, array $stepsByName, string $reason = 'Erreur de saisie corrigée (fictif)', array $overrides = []): array
    {
        $consultation = DB::table('consultations')->where('id', $consultationId)->first();
        $current = (int) $consultation->current_revision;
        $previous = (string) DB::table('clinical_revisions')->where('consultation_id', $consultationId)->where('revision_no', $current)->value('id');
        $steps = [];
        foreach ($stepsByName as $name => $data) {
            $steps[] = ['step' => $name, 'data' => $data];
        }

        return array_merge([
            'expected_version' => (int) $consultation->version,
            'consultation_id' => $consultationId,
            'revision_id' => Ulid::generate(),
            'revision_no' => $current + 1,
            'previous_revision_id' => $previous,
            'reason' => $reason,
            'steps' => $steps,
            'snapshot_sha256' => RevisionHash::hash($steps),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function amendConsultation(string $consultationId, array $body, ?User $as = null, ?string $key = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->clinician)
            ->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])
            ->postJson('/api/v1/consultations/'.$consultationId.'/amendments', $body);
    }

    /**
     * Œil d'une prescription optique (OD ou OG).
     *
     * @return array<string, mixed>
     */
    protected function rxEye(string $eye, ?string $sphere = null, ?string $cylinder = null, ?int $axis = null, ?string $addition = null, bool $noCorrection = false, ?string $fittingHeight = null, ?string $id = null): array
    {
        return [
            'id' => $id ?? Ulid::generate(), 'eye' => $eye, 'no_correction' => $noCorrection, 'sphere' => $sphere, 'cylinder' => $cylinder,
            'axis' => $axis, 'addition' => $addition, 'fitting_height' => $fittingHeight,
        ];
    }

    /**
     * Corps de création d'une prescription INTERNE (brouillon complet par défaut : deux yeux renseignés).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function rxBody(string $consultationId, array $overrides = []): array
    {
        return array_merge([
            'expected_version' => null,
            'prescription_id' => Ulid::generate(),
            'series_id' => Ulid::generate(),
            'version_no' => 1,
            'supersedes_id' => null,
            'patient_id' => $this->patientId,
            'origin' => 'INTERNAL',
            'consultation_id' => $consultationId,
            'external_prescriber' => null,
            'external_prescribed_on' => null,
            'external_file_id' => null,
            'usage_code' => 'PERMANENT',
            'usage_other' => null,
            'lens_type_code' => 'SINGLE_VISION',
            'lens_type_other' => null,
            'lens_index' => '1.50',
            'lens_index_other' => null,
            'pd_total' => '62.00',
            'pd_od' => '31.00',
            'pd_og' => '31.00',
            'pd_inconsistency_reason' => null,
            'notes' => null,
            'eyes' => [$this->rxEye('OD', '-1.25', '-0.50', 90), $this->rxEye('OG', '-1.00', null, null)],
            'treatments' => [],
        ], $overrides);
    }

    /**
     * Corps de transcription d'une ordonnance EXTERNE (aucune consultation).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function rxExternalBody(array $overrides = []): array
    {
        return $this->rxBody('', array_merge([
            'origin' => 'EXTERNAL',
            'consultation_id' => null,
            'external_prescriber' => 'Dr Prescripteur Fictif',
            'external_prescribed_on' => '2026-10-01',
        ], $overrides));
    }

    /**
     * Crée une prescription : interne (route de la consultation), externe ou nouvelle version selon le corps.
     *
     * @param  array<string, mixed>  $body
     */
    protected function createPrescription(array $body, ?User $as = null, ?string $key = null): TestResponse
    {
        $url = match (true) {
            ($body['supersedes_id'] ?? null) !== null => '/api/v1/prescriptions/'.$body['supersedes_id'].'/revisions',
            ($body['origin'] ?? null) === 'EXTERNAL' => '/api/v1/prescriptions/external',
            default => '/api/v1/consultations/'.($body['consultation_id'] ?? '').'/prescriptions',
        };

        return $this->actingAsFresh($as ?? $this->clinician)->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])->postJson($url, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function updatePrescription(string $prescriptionId, array $body, ?User $as = null, ?string $key = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->clinician)->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])->patchJson('/api/v1/prescriptions/'.$prescriptionId, $body);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function validatePrescription(string $prescriptionId, ?array $body = null, ?User $as = null, ?string $key = null): TestResponse
    {
        $body ??= ['expected_version' => null, 'prescription_id' => $prescriptionId, 'document_id' => Ulid::generate()];

        return $this->actingAsFresh($as ?? $this->clinician)->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])->postJson('/api/v1/prescriptions/'.$prescriptionId.'/validate', $body);
    }

    protected function readPrescription(string $prescriptionId, ?User $as = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->clinician)->getJson('/api/v1/prescriptions/'.$prescriptionId);
    }

    /**
     * Brouillon de prescription interne créé pour la consultation : sa ressource (`data`).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function draftPrescription(string $consultationId, array $overrides = []): array
    {
        return (array) $this->createPrescription($this->rxBody($consultationId, $overrides))->assertCreated()->json('data');
    }

    /**
     * Prescription interne validée : sa ressource (`data`) après validation.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validatedPrescription(string $consultationId, array $overrides = []): array
    {
        $draft = $this->draftPrescription($consultationId, $overrides);

        return (array) $this->validatePrescription((string) $draft['id'])->assertOk()->json('data');
    }

    /**
     * Refus de validation (422) dont l'un des champs en erreur contient le fragment de chemin donné (« raw_value »,
     * « acuities.1.eye »…). Les erreurs de schéma et de règle n'ont pas toujours exactement le même préfixe : le
     * fragment est cherché dans les clés rendues.
     */
    protected function assertFieldError(TestResponse $response, string $fragment): void
    {
        $response->assertStatus(422);
        $keys = array_keys((array) $response->json('error.fields'));
        $this->assertTrue(
            count(array_filter($keys, static fn (string $key): bool => str_contains($key, $fragment))) > 0,
            'Aucune erreur de champ ne contient « '.$fragment.' » : '.implode(', ', $keys),
        );
    }

    /**
     * Rubrique enregistrée relue dans la consultation (état courant du dossier).
     *
     * @return array<string, mixed>
     */
    protected function stepOf(string $consultationId, string $step): array
    {
        return (array) $this->readConsultation($consultationId)->assertOk()->json('data.steps.'.$step);
    }
}
