<?php

namespace App\Domain\Patients\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Effects\OutboxEvent;
use App\Application\Locking\TransactionLocks;
use App\Domain\Patients\Rules\PatientIdentity;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\DocumentNumbers;
use App\Domain\Shared\ValueObjects\CivilDate;
use Illuminate\Support\Facades\DB;

/**
 * Création d'une fiche patient (EE001, AT03, AT04) : identité validée, numéro `EE-AAAA-NNNN` attribué sous le
 * verrou du cabinet puis celui de la séquence, fiche et usage du numéro écrits dans la MÊME transaction. Un
 * échec annule tout, numéro compris : aucun trou. Un homonyme ou un téléphone partagé ne bloque jamais la
 * création ; le signalement des ressemblances est fait par l'écran avant l'enregistrement (aucune fusion
 * automatique).
 *
 * Sur la route directe le serveur numérote (`record_number` est null dans la requête). Un numéro venant d'un
 * poste hors-ligne vient de sa plage réservée (O03) : tant que les plages n'existent pas, la commande d'un
 * poste est refusée explicitement plutôt que d'attribuer un numéro hors de toute autorisation.
 */
final class CreatePatientHandler implements CommandHandler
{
    public const COMMAND = 'CreatePatient';

    public function permissions(): array
    {
        return ['patient.create'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La création d\'un patient depuis un poste hors-ligne n\'est pas encore ouverte : les plages de numéros ne sont pas préparées.');
        }

        $payload = $command->payload;
        $patientId = (string) $payload['patient_id'];
        $usageId = (string) $payload['record_number_usage_id'];

        $timezone = (string) $locks->clinic()->timezone;
        $local = $context->now->setTimezone($timezone);
        $today = CivilDate::of((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));
        $columns = PatientIdentity::columns($payload, $today);

        // Identifiants fournis par le client : un identifiant déjà pris (de ce cabinet ou d'un autre) n'est jamais écrasé.
        $errors = [];
        if (DB::table('patient_records')->where('id', $patientId)->exists()) {
            $errors['patient_id'] = ['Cet identifiant de patient est déjà utilisé.'];
        }
        if (DB::table('document_number_usages')->where('id', $usageId)->exists()) {
            $errors['record_number_usage_id'] = ['Cet identifiant d\'usage de numéro est déjà utilisé.'];
        }
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }

        $number = DocumentNumbers::allocateCentral($locks, $context, DocumentNumbers::PATIENT, $timezone);
        $now = $context->now->utc()->format('Y-m-d H:i:s.u');

        DB::table('document_number_usages')->insert([
            'id' => $usageId,
            'clinic_id' => $context->clinicId,
            'allocation_id' => null,
            'document_type' => DocumentNumbers::PATIENT,
            'document_year' => $number['year'],
            'number_value' => $number['value'],
            'rendered_number' => $number['rendered'],
            'entity_type' => 'patient_records',
            'entity_id' => $patientId,
            'source_device_id' => null,
            'actor_id' => $context->actorId,
            'consumed_at' => $now,
            'status' => 'USED',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('patient_records')->insert($columns + [
            'id' => $patientId,
            'clinic_id' => $context->clinicId,
            'record_number' => $number['rendered'],
            'archived_at' => null,
            'merged_into_patient_id' => null,
            'created_by' => $context->actorId,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Références seulement dans le résultat idempotent : aucune donnée d'identité n'y est copiée.
        return new CommandOutcome(
            result: ['patient_id' => $patientId, 'record_number' => $number['rendered'], 'version' => 1],
            resultType: 'patient_records',
            resultId: $patientId,
            audit: [new AuditEntry('patient.created', 'patient_records', $patientId, null, ['record_number' => $number['rendered']])],
            events: [new OutboxEvent('PatientCreated', 1, 'patient_records', $patientId, 1, [
                'patient_id' => $patientId,
                'record_number' => $number['rendered'],
                'legacy_record' => (bool) ($payload['legacy_record'] ?? false),
                'source_device_id' => null,
            ])],
            changes: [new ChangeRecord('patient_records', $patientId, 1, ChangeVisibility::Administrative, ChangeKind::Created)],
        );
    }
}
