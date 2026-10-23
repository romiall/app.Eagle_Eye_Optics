<?php

namespace Tests\Support;

use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Cabinet fictif de l'agenda (S01, S02) : horloge figée le jeudi 2026-10-22 à 09:30 UTC (10:30 à Douala) AVANT la
 * création des comptes (piège connu : un profil créé avant le gel de l'horloge serait « futur »), les quatre profils
 * seedés, un patient, une salle ouverte du lundi au samedi 08:00–12:00 et 14:00–17:00 (heure locale, FICTIF) au pas de
 * 15 minutes, et une prestation de 30 minutes. Données fictives.
 *
 * Repères : vendredi 2026-10-23 08:00 locale = 07:00 UTC ; 12:00 locale = 11:00 UTC ; 14:00 locale = 13:00 UTC.
 */
trait AgendaFixtures
{
    use AccessFixtures;
    use PatientFixtures;
    use ValidatesContracts;

    protected string $clinicId;

    protected User $manager;

    protected User $reception;

    protected User $clinician;

    protected User $cashier;

    protected string $patientId;

    protected string $resourceId;

    protected string $serviceId;

    /**
     * @param  list<string>  $modules
     */
    protected function bootAgenda(array $modules = ['agenda', 'today', 'patients', 'consultations']): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 09:30:00', 'UTC'));
        config(['eeo.enabled_modules' => $modules]);
        $this->clinicId = $this->makeClinic();
        $this->manager = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');
        $this->reception = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        $this->clinician = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.test');
        $this->cashier = $this->makeStaff($this->clinicId, 'CASHIER', 'caisse.test');
        $this->patientId = $this->seedPatient($this->clinicId);
        $this->resourceId = $this->seedResource();
        $this->serviceId = $this->seedService();
    }

    protected function resetClock(): void
    {
        CarbonImmutable::setTestNow();
    }

    protected function moveClock(string $utc): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string}>|null  $hours  jour (1 = lundi), ouverture, fermeture (heure locale)
     */
    protected function seedResource(?string $clinicId = null, ?array $hours = null, string $name = 'Salle fictive', bool $active = true): string
    {
        $clinicId ??= $this->clinicId;
        $id = Ulid::generate();
        $now = '2026-10-01 08:00:00.000000';
        DB::table('scheduling_resources')->insert([
            'id' => $id, 'clinic_id' => $clinicId, 'name' => $name, 'professional_user_id' => null, 'active' => $active,
            'slot_duration_minutes' => 15, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $hours ??= [
            [1, '08:00', '12:00'], [1, '14:00', '17:00'], [2, '08:00', '12:00'], [2, '14:00', '17:00'],
            [3, '08:00', '12:00'], [3, '14:00', '17:00'], [4, '08:00', '12:00'], [4, '14:00', '17:00'],
            [5, '08:00', '12:00'], [5, '14:00', '17:00'], [6, '08:00', '12:00'],
        ];
        foreach ($hours as [$weekday, $opens, $closes]) {
            DB::table('resource_opening_hours')->insert([
                'id' => Ulid::generate(), 'clinic_id' => $clinicId, 'resource_id' => $id, 'weekday' => $weekday,
                'opens_at' => $opens.':00', 'closes_at' => $closes.':00', 'valid_from' => null, 'valid_to' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $id;
    }

    protected function seedService(int $minutes = 30, ?string $clinicId = null, bool $active = true): string
    {
        $id = Ulid::generate();
        $now = '2026-10-01 08:00:00.000000';
        DB::table('appointment_services')->insert([
            'id' => $id, 'clinic_id' => $clinicId ?? $this->clinicId, 'name' => 'Prestation fictive', 'duration_minutes' => $minutes,
            'catalog_item_id' => null, 'active' => $active, 'public_booking' => false, 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /** Politique de rappel interne de rendez-vous (canal interne, aucun envoi). */
    protected function seedAppointmentPolicy(string $code, string $unit, int $value, ?string $localTime = null): string
    {
        $id = Ulid::generate();
        $now = '2026-10-01 08:00:00.000000';
        DB::table('reminder_policies')->insert([
            'id' => $id, 'clinic_id' => $this->clinicId, 'code' => $code, 'label' => 'Rappel fictif '.$code, 'purpose' => 'APPOINTMENT',
            'channel' => 'INTERNAL', 'offset_unit' => $unit, 'offset_value' => $value, 'local_send_time' => $localTime,
            'active' => true, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function send(User $as, string $method, string $uri, array $body = [], ?string $key = null): TestResponse
    {
        return $this->actingAsFresh($as)
            ->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])
            ->json($method, $uri, $body);
    }

    protected function read(User $as, string $uri): TestResponse
    {
        return $this->actingAsFresh($as)->getJson($uri);
    }

    /**
     * Corps de la prise de rendez-vous : vendredi 2026-10-23, 08:00–08:30 heure locale.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function appointmentBody(array $overrides = []): array
    {
        return $overrides + [
            'expected_version' => null,
            'appointment_id' => Ulid::generate(),
            'patient_id' => $this->patientId,
            'prospect_contact_id' => null,
            'follow_up_id' => null,
            'resource_id' => $this->resourceId,
            'service_id' => $this->serviceId,
            'starts_at' => '2026-10-23T07:00:00.000Z',
            'ends_at' => '2026-10-23T07:30:00.000Z',
            'administrative_reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function book(array $overrides = [], ?User $as = null, ?string $key = null): TestResponse
    {
        return $this->send($as ?? $this->reception, 'POST', '/api/v1/appointments', $this->appointmentBody($overrides), $key);
    }

    /**
     * Consultation fictive du patient (date effective donnée, UTC) sur une visite d'examen, pour les contrôles.
     */
    protected function seedConsultation(string $effectiveAt = '2026-10-22 08:00:00'): string
    {
        $visitId = Ulid::generate();
        $stamp = $effectiveAt.'.000000';
        DB::table('visits')->insert([
            'id' => $visitId, 'clinic_id' => $this->clinicId, 'patient_id' => $this->patientId, 'reason' => 'EXAM',
            'occurred_at' => $stamp, 'status' => 'COMPLETED', 'appointment_id' => null, 'exam_invoice_id' => null,
            'exam_waiver_approval_id' => null, 'arrival_at' => $stamp, 'closed_at' => null, 'administrative_note' => null,
            'created_by' => $this->reception->id, 'version' => 1, 'created_at' => $stamp, 'updated_at' => $stamp,
        ]);
        $id = Ulid::generate();
        DB::table('consultations')->insert([
            'id' => $id, 'clinic_id' => $this->clinicId, 'visit_id' => $visitId, 'patient_id' => $this->patientId,
            'clinician_id' => $this->clinician->id, 'effective_at' => $stamp, 'date_change_reason' => null, 'status' => 'IN_PROGRESS',
            'current_revision' => 0, 'symptoms' => null, 'wears_glasses' => null, 'glasses_duration' => null,
            'previous_correction_available' => null, 'refraction_observations' => null, 'conclusion' => null, 'referral' => null,
            'referral_urgency' => null, 'finalization_missing_data_reason' => null, 'completed_at' => null, 'version' => 2,
            'created_at' => $stamp, 'updated_at' => $stamp,
        ]);

        return $id;
    }

    /**
     * Corps d'un contrôle recommandé calculé : départ « consultation » du 2026-10-22, 3 mois, rappel 7 jours avant.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function followUpBody(string $consultationId, array $overrides = []): array
    {
        return $overrides + [
            'expected_version' => null,
            'follow_up_plan_id' => Ulid::generate(),
            'consultation_id' => $consultationId,
            'patient_id' => $this->patientId,
            'reason' => 'ROUTINE',
            'anchor_kind' => 'CONSULTATION',
            'anchor_date' => '2026-10-22',
            'interval_value' => 3,
            'interval_unit' => 'MONTH',
            'recommended_date' => '2027-01-22',
            'date_origin' => 'CALCULATED',
            'manual_reason' => null,
            'reminder_enabled' => true,
            'preferred_channel' => 'CALL',
            'advance_value' => 7,
            'advance_unit' => 'DAY',
            'notes' => 'SENTINELLE-CLINIQUE-NOTE',
        ];
    }

    /**
     * Ressource d'un AUTRE cabinet (isolation).
     *
     * @return array{clinic: string, resource: string, service: string, patient: string}
     */
    protected function otherClinic(): array
    {
        $clinic = $this->makeClinic('TEST CABINET B');
        $this->makeStaff($clinic, 'MANAGER', 'gerante.b');

        return [
            'clinic' => $clinic,
            'resource' => $this->seedResource($clinic),
            'service' => $this->seedService(30, $clinic),
            'patient' => $this->seedPatient($clinic, 'Autre Fictif'),
        ];
    }
}
