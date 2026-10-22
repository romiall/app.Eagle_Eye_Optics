<?php

namespace App\Domain\Clinical\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Billing\BilledExamFeeControl;
use App\Domain\Clinical\ExamFeeControl;
use App\Domain\Clinical\Rules\ClinicalInput;
use App\Domain\Clinical\Rules\ClinicalSteps;
use App\Domain\Clinical\Rules\FieldErrors;
use App\Domain\Patients\Rules\VisitStatus;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Instant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Démarrage de l'examen d'une visite (EE003, AT18) : crée la consultation en brouillon, rattachée à la visite
 * et au patient, avec le compte connecté pour professionnel, et passe la visite à « en consultation ».
 *
 * Le contrôle du paiement de l'examen passe par `ExamFeeControl` : par défaut `UncontrolledExamFee`, qui répond
 * « non contrôlé » et le dit dans l'audit (`exam_fee_control`) sans rien enregistrer ; avec
 * `EEO_EXAM_FEE_CONTROL=billed`, `BilledExamFeeControl` exige la facture de frais de CETTE visite réglée ou la dispense
 * exacte de la gérante, consommée dans cette transaction (P03, B04, B06).
 *
 * Règles : visite du cabinet, du patient indiqué, de motif examen ou suivi (un achat ou un retrait ne crée
 * JAMAIS de consultation), au statut « arrivé » ou « prêt pour examen » (et, contrôle actif seulement, « en attente du
 * paiement de l'examen » : le contrôle dit alors ce qui manque) ; une seule consultation par visite
 * (une seconde tentative, même sous une autre clé, est un refus d'état 409 : l'écran reprend la consultation
 * existante). La consultation naît au brouillon, révision 0, version 1. Verrous : cabinet, idempotence, visite
 * (rang 46), consultation (rang 47).
 */
final class StartConsultationHandler implements CommandHandler
{
    public const COMMAND = 'StartConsultation';

    /** Statuts de visite à partir desquels l'examen peut démarrer. */
    private const STARTABLE = [VisitStatus::ARRIVED, VisitStatus::READY_FOR_EXAM];

    public function __construct(private readonly ExamFeeControl $examFee) {}

    public function permissions(): array
    {
        return ['clinical.write'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('Le démarrage d\'un examen depuis un poste hors-ligne n\'est pas encore ouvert.');
        }

        $payload = $command->payload;
        $visitId = (string) $payload['visit_id'];
        $patientId = (string) $payload['patient_id'];
        $consultationId = (string) $payload['consultation_id'];

        $visit = $locks->visit($visitId);
        if ($visit === null) {
            throw BusinessError::notFound('Visite introuvable dans ce cabinet.');
        }
        // Verrou (rang 47) pris avant tout contrôle : la consultation à créer n'existe pas encore, il ne protège que l'ordre.
        $locks->consultation($consultationId);

        // Refus d'état : la consultation de la visite existe déjà (même sous une autre clé d'idempotence), ou la
        // visite n'est pas dans un statut qui permet de démarrer l'examen.
        $existing = DB::table('consultations')->where('clinic_id', $context->clinicId)->where('visit_id', $visitId)->exists();
        // Contrôle du paiement de l'examen ACTIF : une visite « en attente du paiement » va jusqu'au contrôle, qui
        // dit précisément ce qui manque (422 EXAM_PAYMENT_REQUIRED) ; contrôle inactif : statut non démarrable (409).
        $startable = BilledExamFeeControl::isActive() ? [...self::STARTABLE, VisitStatus::AWAITING_EXAM_FEE] : self::STARTABLE;
        if ($existing || ! in_array($visit->status, $startable, true)) {
            throw BusinessError::stateRefused(
                $existing ? 'L\'examen de cette visite est déjà démarré : reprenez la consultation existante.' : 'L\'examen ne peut pas démarrer pour une visite à ce statut.',
                (string) $visit->status,
            );
        }

        $errors = new FieldErrors;
        if ($visit->patient_id !== $patientId) {
            $errors->add('patient_id', 'Cette visite concerne un autre patient.');
        }
        if (! in_array($visit->reason, VisitStatus::EXAM_REASONS, true)) {
            $errors->add('visit_id', 'Une visite d\'achat direct ou de retrait ne comporte pas d\'examen : enregistrez une nouvelle visite pour un examen.');
        }
        // Clé primaire GLOBALE : un identifiant déjà pris dans un AUTRE cabinet est refusé comme dans celui-ci (422), sans
        // distinguer les deux cas (aucun oracle d'existence entre cabinets) et sans erreur SQL.
        if (DB::table('consultations')->where('id', $consultationId)->exists()) {
            $errors->add('consultation_id', 'Cet identifiant de consultation est déjà utilisé.');
        }

        $effectiveAt = null;
        try {
            $effectiveAt = Instant::parse((string) $payload['effective_at'], 'effective_at');
        } catch (RuleViolation $violation) {
            $errors->add('effective_at', $violation->getMessage());
        }
        $skewMilliseconds = ((int) config('eeo.offline.max_clock_skew_seconds', 300)) * 1000;
        if ($effectiveAt !== null && $effectiveAt->epochMilliseconds > ((int) $context->now->format('Uv')) + $skewMilliseconds) {
            $errors->add('effective_at', 'La date de la consultation ne peut pas être dans le futur.');
        }
        $reason = ClinicalInput::text($payload['date_change_reason'] ?? null, 'date_change_reason', $errors);

        $decision = $this->examFee->decide($visit, $payload, $locks);
        foreach ($decision->errors as $field => $messages) {
            foreach ($messages as $message) {
                $errors->add($field, $message);
            }
        }

        if (! $errors->isEmpty() || $effectiveAt === null) {
            throw BusinessError::validation($errors->all());
        }

        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        $effective = CarbonImmutable::createFromTimestampMsUTC($effectiveAt->epochMilliseconds);

        DB::table('consultations')->insert([
            'id' => $consultationId,
            'clinic_id' => $context->clinicId,
            'visit_id' => $visitId,
            'patient_id' => $patientId,
            'clinician_id' => (string) $context->actorId,
            'effective_at' => $effective->format('Y-m-d H:i:s.u'),
            'date_change_reason' => $reason,
            'status' => ClinicalSteps::DRAFT,
            'current_revision' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $visitVersion = (int) $visit->version + 1;
        DB::table('visits')
            ->where('clinic_id', $context->clinicId)
            ->where('id', $visitId)
            ->update(['status' => VisitStatus::IN_CONSULTATION, 'version' => $visitVersion, 'updated_at' => $now]);

        // Références seulement dans le résultat idempotent : aucune donnée clinique n'y est copiée.
        return new CommandOutcome(
            result: ['consultation_id' => $consultationId, 'visit_id' => $visitId, 'version' => 1, 'status' => ClinicalSteps::DRAFT],
            resultType: 'consultations',
            resultId: $consultationId,
            audit: [new AuditEntry('consultation.started', 'consultations', $consultationId, null, [
                'visit_id' => $visitId,
                'patient_id' => $patientId,
                'exam_fee_control' => $decision->status,
                'effective_at' => $effectiveAt->toString(),
            ])],
            changes: [
                new ChangeRecord('visits', $visitId, $visitVersion, ChangeVisibility::Administrative, ChangeKind::Updated),
                new ChangeRecord('consultations', $consultationId, 1, ChangeVisibility::Clinical, ChangeKind::Created),
            ],
        );
    }
}
