<?php

namespace App\Domain\Operations\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Locking\TransactionLocks;
use App\Domain\Operations\ExportPlan;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;

/**
 * Demande d'un export contrôlé (X02, `RequestExport`), EN LIGNE, réauthentification exigée par la route : l'export
 * est inscrit « demandé » ; l'archive est produite ensuite par la tâche planifiée (`eeo:exports:process`), jamais
 * dans la requête (hébergement simple : délai d'exécution court). Le dossier d'un patient exige clinical.export,
 * tout autre type export.global. Audit sans contenu (type et patient seulement).
 */
final class RequestExportHandler implements CommandHandler
{
    public const COMMAND = 'RequestExport';

    public function permissions(): array
    {
        return ['export.global', 'clinical.export'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        $type = $command->payload['export_type'] ?? null;
        if (! is_string($type) || ! in_array($type, ExportPlan::TYPES, true)) {
            throw BusinessError::validation(['export_type' => ['Type d\'export inconnu.']]);
        }
        if ($context->user === null || ! $context->user->can(ExportPlan::permissionFor($type))) {
            throw BusinessError::forbidden('Ce compte ne peut pas demander ce type d\'export.');
        }

        $patientId = $command->payload['patient_id'] ?? null;
        if ($type === 'PATIENT_RECORD') {
            if (! is_string($patientId) || ! DB::table('patient_records')->where('clinic_id', $context->clinicId)->where('id', $patientId)->exists()) {
                throw BusinessError::validation(['patient_id' => ['Choisissez le patient dont le dossier est exporté.']]);
            }
        } elseif ($patientId !== null) {
            throw BusinessError::validation(['patient_id' => ['Seul l\'export du dossier d\'un patient désigne un patient.']]);
        }

        $id = Ulid::generate();
        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        DB::table('export_requests')->insert([
            'id' => $id,
            'clinic_id' => $context->clinicId,
            'requested_by' => (string) $context->actorId,
            'export_type' => $type,
            'patient_id' => $type === 'PATIENT_RECORD' ? $patientId : null,
            'filters' => null,
            'status' => 'REQUESTED',
            'file_id' => null,
            'completed_at' => null,
            'expires_at' => null,
            'error_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new CommandOutcome(
            result: ['export_id' => $id],
            resultType: 'export_requests',
            resultId: $id,
            audit: [new AuditEntry('export.requested', 'export_requests', $id, null, [
                'export_type' => $type,
                'patient_id' => $type === 'PATIENT_RECORD' ? $patientId : null,
            ])],
        );
    }
}
