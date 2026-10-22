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
use App\Domain\Patients\Rules\PatientIdentity;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CanonicalJson;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\ValueObjects\CivilDate;
use Illuminate\Support\Facades\DB;

/**
 * Modification de l'identité d'un patient (EE001) : la version attendue est comparée à celle de la fiche lue
 * sous verrou (jamais un écrasement silencieux : 409 avec la version actuelle), l'état résultant est revalidé en
 * entier (une raison d'absence ne disparaît pas avec la valeur qu'elle explique) et les colonnes dérivées sont
 * recalculées. Le numéro de dossier ne change jamais. Un changement sans effet n'incrémente pas la version.
 * L'audit conserve l'avant et l'après de chaque champ modifié : c'est l'historique de l'identité.
 */
final class UpdatePatientHandler implements CommandHandler
{
    public const COMMAND = 'UpdatePatient';

    public function permissions(): array
    {
        return ['patient.update'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        $patientId = (string) $command->payload['patient_id'];
        /** @var array<string, mixed> $changes */
        $changes = $command->payload['changes'];

        $row = $locks->patient($patientId);
        if ($row === null) {
            throw BusinessError::notFound();
        }
        if ((int) $row->version !== $command->expectedVersion) {
            throw new BusinessError('VERSION_CONFLICT', 'Une version plus récente existe : actualisez avant de modifier.', 409, [], null, [], ['current_version' => (int) $row->version]);
        }

        $timezone = (string) $locks->clinic()->timezone;
        $local = $context->now->setTimezone($timezone);
        $today = CivilDate::of((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));

        $before = PatientIdentity::fromRow($row);
        $columns = PatientIdentity::columns(array_merge($before, $changes), $today, 'changes.');

        $diff = [];
        foreach (PatientIdentity::FIELDS as $field) {
            $old = $before[$field];
            $new = $field === 'guardian_contact' && is_string($columns[$field])
                ? json_decode($columns[$field], true, 512, JSON_THROW_ON_ERROR)
                : $columns[$field];
            // Comparaison stricte (jamais « 1e3 » égal à « 1000 ») ; le contact du responsable est un objet JSON
            // dont l'ordre des clés ne compte pas.
            $same = $field === 'guardian_contact'
                ? CanonicalJson::hash([$old]) === CanonicalJson::hash([$new])
                : $old === $new;
            if (! $same) {
                $diff[$field] = ['avant' => $old, 'apres' => $new];
            }
        }

        if ($diff === []) {
            return new CommandOutcome(['patient_id' => $patientId, 'version' => (int) $row->version], 'patient_records', $patientId);
        }

        $version = (int) $row->version + 1;
        DB::table('patient_records')
            ->where('clinic_id', $context->clinicId)
            ->where('id', $patientId)
            ->update($columns + ['version' => $version, 'updated_at' => $context->now->utc()->format('Y-m-d H:i:s.u')]);

        return new CommandOutcome(
            result: ['patient_id' => $patientId, 'version' => $version],
            resultType: 'patient_records',
            resultId: $patientId,
            audit: [new AuditEntry('patient.updated', 'patient_records', $patientId, null, $diff)],
            changes: [new ChangeRecord('patient_records', $patientId, $version, ChangeVisibility::Administrative, ChangeKind::Updated)],
        );
    }
}
