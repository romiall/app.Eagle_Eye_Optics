<?php

namespace Tests\Support;

use App\Domain\Access\EffectivePermissions;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Activité d'un patient : cabinet, comptes des quatre profils seedés, patient et lignes FICTIVES insérées
 * directement en base (visites, consultations, ordonnances, pièces financières). Les commandes cliniques et de
 * facturation qui écrivent ces lignes en production ne sont pas utilisées : la lecture de l'activité ne dépend que
 * des tables.
 *
 * Horloge : `bootActivity()` fige l'horloge EN PREMIER, avant de créer les comptes. L'attribution d'un profil porte
 * l'instant courant et une permission n'est effective qu'à partir de cet instant : figer l'horloge dans le passé
 * APRÈS la création des comptes rendrait leurs profils « futurs » et tout répondrait 403.
 *
 * Sentinelles : les champs cliniques et les références de paiement reçoivent des textes reconnaissables
 * (`SENTINELLE-…`) qui ne doivent JAMAIS apparaître dans la réponse de l'activité, quel que soit le profil.
 */
trait PatientActivityFixtures
{
    use AccessFixtures;
    use PatientFixtures;
    use ValidatesContracts;

    protected const CLINICAL_SENTINEL = 'SENTINELLE-CLINIQUE';

    protected const PAYMENT_SENTINEL = 'SENTINELLE-REFERENCE';

    protected string $clinicId;

    protected User $manager;

    protected User $clinician;

    protected User $reception;

    protected User $cashier;

    protected string $patientId;

    private int $documentCounter = 0;

    /**
     * @param  list<string>  $modules
     */
    protected function bootActivity(array $modules = ['today', 'patients', 'consultations', 'sales']): void
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

    protected function readActivity(?User $as = null, string $query = '', ?string $patientId = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->manager)
            ->getJson('/api/v1/patients/'.($patientId ?? $this->patientId).'/activity'.$query);
    }

    /**
     * Dérogation nominative qui retire une permission au compte (le cache des droits de la requête précédente est
     * oublié : en test, plusieurs requêtes partagent un même processus).
     */
    protected function denyPermission(User $user, string $permission): void
    {
        DB::table('user_permissions')->insert([
            'id' => Ulid::generate(),
            'clinic_id' => $user->clinic_id,
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
     * Instant stocké (`AAAA-MM-JJ HH:MM:SS`, UTC) → colonne DATETIME(6).
     */
    protected static function at(string $instant): string
    {
        return $instant.'.000000';
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function seedActivityVisit(string $occurredAt, array $overrides = [], ?string $clinicId = null, ?string $patientId = null): string
    {
        $id = (string) ($overrides['id'] ?? Ulid::generate());
        $clinicId ??= $this->clinicId;
        DB::table('visits')->insert($overrides + [
            'id' => $id,
            'clinic_id' => $clinicId,
            'patient_id' => $patientId ?? $this->patientId,
            'reason' => 'EXAM',
            'occurred_at' => self::at($occurredAt),
            'status' => 'ARRIVED',
            'appointment_id' => null,
            'exam_invoice_id' => null,
            'exam_waiver_approval_id' => null,
            'arrival_at' => self::at($occurredAt),
            'closed_at' => null,
            'administrative_note' => null,
            'created_by' => DB::table('users')->where('clinic_id', $clinicId)->value('id'),
            'version' => 1,
            'created_at' => self::at($occurredAt),
            'updated_at' => self::at($occurredAt),
        ]);

        return $id;
    }

    /**
     * Consultation de la visite, avec des valeurs cliniques sentinelles (qui ne doivent jamais sortir dans l'activité).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function seedActivityConsultation(string $visitId, string $effectiveAt, string $status = 'IN_PROGRESS', array $overrides = []): string
    {
        $id = (string) ($overrides['id'] ?? Ulid::generate());
        $completed = $status === 'COMPLETED';
        DB::table('consultations')->insert($overrides + [
            'id' => $id,
            'clinic_id' => $this->clinicId,
            'visit_id' => $visitId,
            'patient_id' => $this->patientId,
            'clinician_id' => $this->clinician->id,
            'effective_at' => self::at($effectiveAt),
            'date_change_reason' => self::CLINICAL_SENTINEL.'-MOTIF-DATE',
            'status' => $status,
            'current_revision' => $completed ? 1 : 0,
            'symptoms' => self::CLINICAL_SENTINEL.'-SYMPTOMES',
            'wears_glasses' => true,
            'glasses_duration' => self::CLINICAL_SENTINEL.'-DUREE',
            'previous_correction_available' => null,
            'refraction_observations' => self::CLINICAL_SENTINEL.'-OBSERVATIONS',
            'conclusion' => self::CLINICAL_SENTINEL.'-CONCLUSION',
            'referral' => true,
            'referral_urgency' => 'RAPID',
            'finalization_missing_data_reason' => null,
            'completed_at' => $completed ? self::at($effectiveAt) : null,
            'version' => 2,
            'created_at' => self::at($effectiveAt),
            'updated_at' => self::at($effectiveAt),
        ]);

        return $id;
    }

    protected function seedMedicationOrder(string $consultationId, int $position = 1): string
    {
        $id = Ulid::generate();
        DB::table('medication_orders')->insert([
            'id' => $id,
            'clinic_id' => $this->clinicId,
            'consultation_id' => $consultationId,
            'position' => $position,
            'catalog_item_id' => null,
            'name' => self::CLINICAL_SENTINEL.'-MEDICAMENT',
            'form' => 'DROPS',
            'form_other' => null,
            'strength' => null,
            'dosage' => self::CLINICAL_SENTINEL.'-POSOLOGIE',
            'frequency' => null,
            'duration_text' => null,
            'duration_value' => null,
            'duration_unit' => null,
            'instructions' => null,
            'created_at' => '2026-10-01 08:10:00.000000',
            'updated_at' => '2026-10-01 08:10:00.000000',
        ]);

        return $id;
    }

    /**
     * Prescription optique (interne avec consultation, externe sans). Une version validée porte son instant de
     * validation, qui est la date de l'élément dans l'activité.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function seedOpticalPrescription(?string $consultationId, string $createdAt, ?string $validatedAt = null, array $overrides = []): string
    {
        $id = (string) ($overrides['id'] ?? Ulid::generate());
        $external = $consultationId === null;
        DB::table('optical_prescriptions')->insert($overrides + [
            'id' => $id,
            'clinic_id' => $this->clinicId,
            'patient_id' => $this->patientId,
            'series_id' => Ulid::generate(),
            'origin' => $external ? 'EXTERNAL' : 'INTERNAL',
            'external_file_id' => null,
            'external_prescriber' => $external ? self::CLINICAL_SENTINEL.'-PRESCRIPTEUR' : null,
            'external_prescribed_on' => $external ? '2026-09-30' : null,
            'consultation_id' => $consultationId,
            'version_no' => 1,
            'status' => $validatedAt === null ? 'DRAFT' : 'VALIDATED',
            'usage_code' => null,
            'usage_other' => self::CLINICAL_SENTINEL.'-USAGE',
            'lens_type_code' => null,
            'lens_type_other' => null,
            'lens_index' => null,
            'lens_index_other' => null,
            'pd_total' => '62.00',
            'pd_od' => null,
            'pd_og' => null,
            'pd_inconsistency_reason' => null,
            'notes' => self::CLINICAL_SENTINEL.'-NOTES',
            'validated_at' => $validatedAt === null ? null : self::at($validatedAt),
            'validated_by' => $validatedAt === null ? null : $this->clinician->id,
            'supersedes_id' => null,
            'created_at' => self::at($createdAt),
            'updated_at' => self::at($validatedAt ?? $createdAt),
        ]);

        return $id;
    }

    /**
     * Numéro consommé (registre `document_number_usages`) pour une pièce : [identifiant d'usage, numéro rendu].
     *
     * @return array{0: string, 1: string}
     */
    private function seedNumberUsage(string $type, string $prefix, string $entityType, string $entityId, string $at): array
    {
        $this->documentCounter++;
        $number = sprintf('%s-%06d', $prefix, $this->documentCounter);
        $id = Ulid::generate();
        DB::table('document_number_usages')->insert([
            'id' => $id,
            'clinic_id' => $this->clinicId,
            'allocation_id' => null,
            'document_type' => $type,
            'document_year' => 2026,
            'number_value' => $this->documentCounter,
            'rendered_number' => $number,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'source_device_id' => null,
            'actor_id' => $this->cashier->id,
            'consumed_at' => self::at($at),
            'status' => 'USED',
            'created_at' => self::at($at),
            'updated_at' => self::at($at),
        ]);

        return [$id, $number];
    }

    private function seedIdempotency(string $commandName, string $at): string
    {
        $id = Ulid::generate();
        DB::table('idempotency_requests')->insert([
            'id' => $id,
            'clinic_id' => $this->clinicId,
            'namespace' => 'test',
            'command_name' => $commandName,
            'request_key' => Ulid::generate(),
            'payload_hash' => str_repeat('a', 64),
            'status' => 'COMMITTED',
            'result_type' => null,
            'result_id' => null,
            'result_payload' => null,
            'completed_at' => self::at($at),
            'created_at' => self::at($at),
            'updated_at' => self::at($at),
        ]);

        return $id;
    }

    private function seedApproval(string $actionCode, string $targetType, string $targetId, string $at): string
    {
        $id = Ulid::generate();
        DB::table('approvals')->insert([
            'id' => $id,
            'clinic_id' => $this->clinicId,
            'action_code' => $actionCode,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload_hash' => str_repeat('b', 64),
            'requested_by' => $this->cashier->id,
            'reason' => 'Autorisation fictive',
            'status' => 'APPROVED',
            'decided_by' => $this->manager->id,
            'decided_at' => self::at($at),
            'expires_at' => null,
            'consumed_at' => null,
            'created_at' => self::at($at),
            'updated_at' => self::at($at),
        ]);

        return $id;
    }

    /**
     * Facture ÉMISE insérée directement (numéro consommé, snapshots fictifs) : [identifiant, numéro].
     *
     * @return array{0: string, 1: string}
     */
    protected function seedIssuedInvoice(int $total, string $issuedAt): array
    {
        $id = Ulid::generate();
        [$usageId, $number] = $this->seedNumberUsage('INVOICE', 'TEST-FAC', 'invoices', $id, $issuedAt);
        DB::table('invoices')->insert([
            'id' => $id,
            'clinic_id' => $this->clinicId,
            'patient_id' => $this->patientId,
            'visit_id' => null,
            'quote_id' => null,
            'number' => $number,
            'issued_at' => self::at($issuedAt),
            'issued_by' => $this->cashier->id,
            'number_usage_id' => $usageId,
            'status' => 'ISSUED',
            'due_date' => null,
            'currency' => 'XAF',
            'total_gross' => $total,
            'patient_snapshot' => json_encode(['fictif' => true], JSON_THROW_ON_ERROR),
            'clinic_snapshot' => json_encode(['fictif' => true], JSON_THROW_ON_ERROR),
            'tax_snapshot' => json_encode(['fictif' => true], JSON_THROW_ON_ERROR),
            'origin' => 'NATIVE',
            'legacy_reference' => null,
            'legacy_opening_balance' => null,
            'source_invoice_id' => null,
            'created_by' => $this->cashier->id,
            'source_device_id' => null,
            'version' => 1,
            'created_at' => self::at($issuedAt),
            'updated_at' => self::at($issuedAt),
        ]);

        return [$id, $number];
    }

    /** Brouillon de facture : pas une pièce, il ne figure jamais dans l'activité. */
    protected function seedDraftInvoice(int $total, string $createdAt, string $status = 'DRAFT'): string
    {
        $id = Ulid::generate();
        DB::table('invoices')->insert([
            'id' => $id,
            'clinic_id' => $this->clinicId,
            'patient_id' => $this->patientId,
            'visit_id' => null,
            'quote_id' => null,
            'number' => null,
            'issued_at' => null,
            'issued_by' => null,
            'number_usage_id' => null,
            'status' => $status,
            'due_date' => null,
            'currency' => 'XAF',
            'total_gross' => $total,
            'patient_snapshot' => null,
            'clinic_snapshot' => null,
            'tax_snapshot' => null,
            'origin' => 'NATIVE',
            'legacy_reference' => null,
            'legacy_opening_balance' => null,
            'source_invoice_id' => null,
            'created_by' => $this->cashier->id,
            'source_device_id' => null,
            'version' => 1,
            'created_at' => self::at($createdAt),
            'updated_at' => self::at($createdAt),
        ]);

        return $id;
    }

    /**
     * Encaissement confirmé (mobile money, sans session de caisse) et son reçu : [paiement, reçu, numéro du reçu].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function seedPaymentWithReceipt(string $invoiceId, int $amount, string $at): array
    {
        $paymentId = Ulid::generate();
        DB::table('payments')->insert([
            'id' => $paymentId,
            'clinic_id' => $this->clinicId,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'method' => 'MTN_MOMO',
            'reference' => self::PAYMENT_SENTINEL,
            'effective_at' => self::at($at),
            'status' => 'CONFIRMED',
            'confirmed_at' => self::at($at),
            'cash_session_id' => null,
            'received_by' => $this->cashier->id,
            'idempotency_id' => $this->seedIdempotency('RegisterPayment', $at),
            'source_device_id' => null,
            'sync_command_id' => null,
            'created_at' => self::at($at),
            'updated_at' => self::at($at),
        ]);

        $receiptId = Ulid::generate();
        [$usageId, $number] = $this->seedNumberUsage('RECEIPT', 'TEST-REC', 'receipts', $receiptId, $at);
        DB::table('receipts')->insert([
            'id' => $receiptId,
            'clinic_id' => $this->clinicId,
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'number' => $number,
            'number_usage_id' => $usageId,
            'issued_at' => self::at($at),
            'issued_by' => $this->cashier->id,
            'snapshot' => json_encode(['fictif' => true], JSON_THROW_ON_ERROR),
            'created_at' => self::at($at),
            'updated_at' => self::at($at),
        ]);

        return [$paymentId, $receiptId, $number];
    }

    /** Contre-écriture approuvée d'un paiement (le reçu reste). */
    protected function seedReversal(string $paymentId, int $amount, string $at): string
    {
        $id = Ulid::generate();
        DB::table('payment_reversals')->insert([
            'id' => $id,
            'clinic_id' => $this->clinicId,
            'payment_id' => $paymentId,
            'amount' => $amount,
            'reason' => 'Erreur de saisie fictive',
            'approval_id' => $this->seedApproval('payment.reverse', 'payments', $paymentId, $at),
            'recorded_at' => self::at($at),
            'created_by' => $this->manager->id,
            'idempotency_id' => $this->seedIdempotency('ReversePayment', $at),
            'created_at' => self::at($at),
            'updated_at' => self::at($at),
        ]);

        return $id;
    }

    /**
     * Avoir émis (statut ISSUED par défaut ; un brouillon ou un avoir abandonné ne figure pas dans l'activité) :
     * [identifiant, numéro ou null].
     *
     * @return array{0: string, 1: string|null}
     */
    protected function seedCreditNote(string $invoiceId, int $amount, string $at, string $status = 'ISSUED'): array
    {
        $id = Ulid::generate();
        $issued = $status === 'ISSUED';
        [$usageId, $number] = $issued ? $this->seedNumberUsage('CREDIT_NOTE', 'TEST-AV', 'credit_notes', $id, $at) : [null, null];
        DB::table('credit_notes')->insert([
            'id' => $id,
            'clinic_id' => $this->clinicId,
            'invoice_id' => $invoiceId,
            'number' => $number,
            'issued_at' => $issued ? self::at($at) : null,
            'issued_by' => $issued ? $this->manager->id : null,
            'number_usage_id' => $usageId,
            'status' => $status,
            'amount_gross' => $amount,
            'reason' => 'Avoir fictif',
            'approval_id' => $this->seedApproval('credit_note.issue', 'invoices', $invoiceId, $at),
            'snapshot' => $issued ? json_encode(['fictif' => true], JSON_THROW_ON_ERROR) : null,
            'idempotency_id' => $this->seedIdempotency('IssueCreditNote', $at),
            'created_at' => self::at($at),
            'updated_at' => self::at($at),
        ]);

        return [$id, $number];
    }

    /**
     * Remboursement d'un avoir émis (mobile money, sans session de caisse) : [identifiant, numéro].
     *
     * @return array{0: string, 1: string}
     */
    protected function seedRefund(string $invoiceId, string $creditNoteId, int $amount, string $at): array
    {
        $id = Ulid::generate();
        [$usageId, $number] = $this->seedNumberUsage('REFUND', 'TEST-RMB', 'refunds', $id, $at);
        DB::table('refunds')->insert([
            'id' => $id,
            'clinic_id' => $this->clinicId,
            'invoice_id' => $invoiceId,
            'credit_note_id' => $creditNoteId,
            'amount' => $amount,
            'method' => 'MTN_MOMO',
            'reference' => self::PAYMENT_SENTINEL,
            'approval_id' => $this->seedApproval('refund.execute', 'credit_notes', $creditNoteId, $at),
            'number' => $number,
            'number_usage_id' => $usageId,
            'effective_at' => self::at($at),
            'cash_session_id' => null,
            'idempotency_id' => $this->seedIdempotency('RefundCredit', $at),
            'created_by' => $this->manager->id,
            'snapshot' => json_encode(['fictif' => true], JSON_THROW_ON_ERROR),
            'created_at' => self::at($at),
            'updated_at' => self::at($at),
        ]);

        return [$id, $number];
    }
}
