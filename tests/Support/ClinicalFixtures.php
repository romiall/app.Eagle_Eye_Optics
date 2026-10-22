<?php

namespace Tests\Support;

use App\Domain\Access\EffectivePermissions;
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
        return [
            'consultations' => DB::table('consultations')->where('clinic_id', $this->clinicId)->count(),
            'complaints' => DB::table('consultation_complaints')->where('clinic_id', $this->clinicId)->count(),
            'histories' => DB::table('consultation_histories')->where('clinic_id', $this->clinicId)->count(),
            'history_terms' => DB::table('consultation_history_terms')->where('clinic_id', $this->clinicId)->count(),
            'referral_reasons' => DB::table('consultation_referral_reasons')->where('clinic_id', $this->clinicId)->count(),
            'audit' => DB::table('audit_events')->where('clinic_id', $this->clinicId)->where('action', 'like', 'consultation.%')->count(),
            'changes' => DB::table('sync_change_log')->where('clinic_id', $this->clinicId)->count(),
            'idempotency' => DB::table('idempotency_requests')->where('clinic_id', $this->clinicId)->count(),
        ];
    }
}
