<?php

namespace App\Domain\Scheduling\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Scheduling\SchedulingInput;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use Illuminate\Support\Facades\DB;

/**
 * Remplacement des créneaux hebdomadaires d'une ressource (S01 ; « Configurer ressources/services/horaires ») :
 * la liste envoyée REMPLACE l'ancienne, version de la ressource attendue (409 sinon, la ressource passe à la version
 * suivante). Chaque créneau : jour 1 (lundi) à 7 (dimanche), heure d'ouverture avant l'heure de fermeture (heure
 * locale du cabinet), aucun chevauchement le même jour, identifiants neufs. Une liste vide ferme la ressource : aucun
 * horaire n'est jamais inventé. Les rendez-vous déjà pris ne sont pas modifiés (ils restent visibles et se déplacent
 * à la main). Gérante seulement (`users.manage`), en ligne seulement.
 */
final class ReplaceOpeningHoursHandler implements CommandHandler
{
    public const COMMAND = 'ReplaceOpeningHours';

    public function permissions(): array
    {
        return ['users.manage'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La configuration de l\'agenda se fait en ligne.');
        }
        $resourceId = (string) $command->entityId;
        $resource = $locks->schedulingResource($resourceId);
        if ($resource === null) {
            throw BusinessError::notFound('Ressource de l\'agenda introuvable dans ce cabinet.');
        }
        if ($command->expectedVersion === null || (int) $resource->version !== $command->expectedVersion) {
            throw new BusinessError('VERSION_CONFLICT', 'La ressource a changé depuis l\'affichage : actualisez avant de modifier ses horaires.', 409, [], null, [], ['current_version' => (int) $resource->version]);
        }

        /** @var list<array<string, mixed>> $hours */
        $hours = array_values($command->payload['hours'] ?? []);
        $errors = [];
        $rows = [];
        $ids = [];
        $existing = DB::table('resource_opening_hours')->where('clinic_id', $context->clinicId)->where('resource_id', $resourceId)->pluck('id')->all();
        foreach ($hours as $index => $hour) {
            $id = (string) $hour['id'];
            $opens = SchedulingInput::localTime($hour['opens_at'] ?? null, "hours.$index.opens_at", $errors);
            $closes = SchedulingInput::localTime($hour['closes_at'] ?? null, "hours.$index.closes_at", $errors);
            if (isset($ids[$id])) {
                $errors["hours.$index.id"][] = 'Identifiant de créneau en double dans la liste.';
            } elseif (! in_array($id, $existing, true) && DB::table('resource_opening_hours')->where('id', $id)->exists()) {
                $errors["hours.$index.id"][] = 'Cet identifiant de créneau est déjà utilisé.';
            }
            $ids[$id] = true;
            if ($opens !== null && $closes !== null && $closes <= $opens) {
                $errors["hours.$index.closes_at"][] = 'L\'heure de fermeture doit suivre l\'heure d\'ouverture.';
            }
            $rows[] = ['index' => $index, 'id' => $id, 'weekday' => (int) $hour['weekday'], 'opens' => $opens, 'closes' => $closes];
        }
        foreach ($rows as $a) {
            foreach ($rows as $b) {
                if ($a['index'] < $b['index'] && $a['weekday'] === $b['weekday'] && $a['opens'] !== null && $a['closes'] !== null
                    && $b['opens'] !== null && $b['closes'] !== null && $a['opens'] < $b['closes'] && $b['opens'] < $a['closes']) {
                    $errors["hours.{$b['index']}.opens_at"][] = 'Ce créneau chevauche un autre créneau du même jour.';
                }
            }
        }
        SchedulingInput::throwIfAny($errors);

        $stamp = SchedulingInput::stamp($context->now->utc());
        DB::table('resource_opening_hours')->where('clinic_id', $context->clinicId)->where('resource_id', $resourceId)->delete();
        foreach ($rows as $row) {
            DB::table('resource_opening_hours')->insert([
                'id' => $row['id'],
                'clinic_id' => $context->clinicId,
                'resource_id' => $resourceId,
                'weekday' => $row['weekday'],
                'opens_at' => SchedulingInput::formatMinutes((int) $row['opens']).':00',
                'closes_at' => SchedulingInput::formatMinutes((int) $row['closes']).':00',
                'valid_from' => null,
                'valid_to' => null,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        }
        $version = (int) $resource->version + 1;
        DB::table('scheduling_resources')->where('clinic_id', $context->clinicId)->where('id', $resourceId)->update([
            'version' => $version,
            'updated_at' => $stamp,
        ]);

        return new CommandOutcome(
            result: ['resource_id' => $resourceId, 'version' => $version],
            resultType: 'scheduling_resources',
            resultId: $resourceId,
            audit: [new AuditEntry('agenda.opening_hours_replaced', 'scheduling_resources', $resourceId, null, [
                'before' => count($existing), 'after' => count($rows), 'version' => $version,
            ])],
            changes: [new ChangeRecord('scheduling_resources', $resourceId, $version, ChangeVisibility::Administrative, ChangeKind::Updated)],
        );
    }
}
