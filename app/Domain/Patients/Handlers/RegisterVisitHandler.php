<?php

namespace App\Domain\Patients\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Instant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Enregistrement de l'arrivée d'un patient (EE002, AT05) : une visite horodatée avec son motif administratif
 * (examen de vue, achat direct, retrait, contrôle de suivi). Une visite n'impose pas d'examen : un achat ne crée
 * jamais de consultation vide. Une nouvelle visite ne remplace aucune précédente ; une autre visite du même
 * patient le même jour ne bloque pas (l'écran avertit à partir de la file du jour).
 *
 * Le statut est décidé par le serveur (`ARRIVED`). Le contrôle du paiement de l'examen n'existe pas encore (B04,
 * B06) : aucun statut « en attente de paiement » ni « prêt pour examen » n'est écrit.
 */
final class RegisterVisitHandler implements CommandHandler
{
    public const COMMAND = 'RegisterVisit';

    public function permissions(): array
    {
        return ['visit.register'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('L\'enregistrement d\'une visite depuis un poste hors-ligne n\'est pas encore ouvert.');
        }

        $payload = $command->payload;
        $visitId = (string) $payload['visit_id'];
        $patientId = (string) $payload['patient_id'];
        $errors = [];

        $patient = $locks->patient($patientId);
        if ($patient === null) {
            $errors['patient_id'] = ['Patient introuvable dans ce cabinet.'];
        } elseif ($patient->archived_at !== null || $patient->merged_into_patient_id !== null) {
            $errors['patient_id'] = ['Cette fiche est archivée ou fusionnée : ouvrez la fiche patient actuelle.'];
        }

        if (DB::table('visits')->where('id', $visitId)->exists()) {
            $errors['visit_id'] = ['Cet identifiant de visite est déjà utilisé.'];
        }

        $appointmentId = $payload['appointment_id'] ?? null;
        if ($appointmentId !== null) {
            $appointment = DB::table('appointments')->where('clinic_id', $context->clinicId)->where('id', $appointmentId)->first();
            if ($appointment === null) {
                $errors['appointment_id'] = ['Rendez-vous introuvable dans ce cabinet.'];
            } elseif ($appointment->patient_id !== $patientId) {
                $errors['appointment_id'] = ['Ce rendez-vous concerne un autre patient.'];
            } elseif (in_array($appointment->status, ['CANCELLED', 'NO_SHOW'], true)) {
                $errors['appointment_id'] = ['Ce rendez-vous est annulé ou manqué.'];
            }
        }

        try {
            $occurredAt = Instant::parse((string) $payload['occurred_at'], 'occurred_at');
        } catch (RuleViolation $violation) {
            $occurredAt = null;
            $errors['occurred_at'] = [$violation->getMessage()];
        }
        $skewMilliseconds = ((int) config('eeo.offline.max_clock_skew_seconds', 300)) * 1000;
        if ($occurredAt !== null && $occurredAt->epochMilliseconds > ((int) $context->now->format('Uv')) + $skewMilliseconds) {
            $errors['occurred_at'] = ['L\'heure d\'arrivée ne peut pas être dans le futur.'];
        }

        $note = $payload['administrative_note'] ?? null;
        $note = is_string($note) && trim($note) !== '' ? trim($note) : null;

        if ($errors !== [] || $occurredAt === null) {
            throw BusinessError::validation($errors);
        }

        $arrival = CarbonImmutable::createFromTimestampMsUTC($occurredAt->epochMilliseconds)->format('Y-m-d H:i:s.u');
        $now = $context->now->utc()->format('Y-m-d H:i:s.u');

        DB::table('visits')->insert([
            'id' => $visitId,
            'clinic_id' => $context->clinicId,
            'patient_id' => $patientId,
            'reason' => (string) $payload['reason'],
            'occurred_at' => $arrival,
            'status' => 'ARRIVED',
            'appointment_id' => $appointmentId,
            'exam_invoice_id' => null,
            'exam_waiver_approval_id' => null,
            'arrival_at' => $arrival,
            'closed_at' => null,
            'administrative_note' => $note,
            'created_by' => $context->actorId,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new CommandOutcome(
            result: ['visit_id' => $visitId, 'version' => 1],
            resultType: 'visits',
            resultId: $visitId,
            audit: [new AuditEntry('visit.registered', 'visits', $visitId, null, ['reason' => (string) $payload['reason'], 'patient_id' => $patientId])],
            changes: [new ChangeRecord('visits', $visitId, 1, ChangeVisibility::Administrative, ChangeKind::Created)],
        );
    }
}
