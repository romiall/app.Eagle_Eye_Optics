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
use App\Domain\Communication\ContactTasks;
use App\Domain\Scheduling\FollowUpCalculator;
use App\Domain\Scheduling\SchedulingInput;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Contrôle recommandé depuis une consultation (S02, AT22 ; fichier 02 « Un contrôle recommandé possède un délai, une
 * date de départ, une date calculée, un canal souhaité, une politique de rappel et des observations ») :
 *
 * - origine de la date choisie EXPLICITEMENT : consultation effective (la date de départ est alors celle de la
 *   consultation, au fuseau du cabinet), début ou fin de traitement (date fournie par le professionnel), date
 *   manuelle ;
 * - date CALCULÉE (départ + délai en jours, mois ou années au calendrier civil : 31 janvier + 1 mois = dernier jour de
 *   février) recalculée par le serveur et refusée si elle diffère ; date MANUELLE avec motif obligatoire ;
 * - aucune décision médicale automatique : le délai est celui du professionnel ;
 * - rappel interne activé ou non, canal souhaité, avance facultative : la tâche de contact interne est créée dans la
 *   même transaction (aucun SMS ni WhatsApp réel en L0).
 *
 * Le motif est clinique : `clinical.read` est exigé en plus de `follow_up.write` (403 sinon). Ce n'est jamais une
 * réservation implicite : un rendez-vous se programme à part. Verrous : cabinet, idempotence, consultation (47).
 */
final class CreateFollowUpHandler implements CommandHandler
{
    public const COMMAND = 'CreateFollowUp';

    public function __construct(private readonly ContactTasks $tasks) {}

    public function permissions(): array
    {
        return ['follow_up.write'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('Un contrôle recommandé se programme en ligne dans cette version.');
        }
        if ($context->user === null || ! $context->user->can('clinical.read')) {
            throw BusinessError::forbidden('Le motif d\'un contrôle recommandé est clinique : le droit de lire le dossier médical est exigé.');
        }
        $payload = $command->payload;
        $planId = (string) $payload['follow_up_plan_id'];
        $timezone = (string) $locks->clinic()->timezone;
        $errors = [];

        $consultation = $locks->consultation((string) $payload['consultation_id']);
        if ($consultation === null) {
            throw BusinessError::notFound('Consultation introuvable dans ce cabinet.');
        }
        if ((string) $consultation->patient_id !== (string) $payload['patient_id']) {
            $errors['patient_id'][] = 'Ce patient n\'est pas celui de la consultation.';
        }
        if (DB::table('follow_up_plans')->where('id', $planId)->exists()) {
            $errors['follow_up_plan_id'][] = 'Cet identifiant de contrôle est déjà utilisé.';
        }

        $anchorKind = (string) $payload['anchor_kind'];
        $anchor = SchedulingInput::date($payload['anchor_date'] ?? null, 'anchor_date', $errors);
        $consultationDay = CivilDate::fromString(
            CarbonImmutable::parse((string) $consultation->effective_at, 'UTC')->setTimezone($timezone)->format('Y-m-d'),
            'anchor_date',
        );
        if ($anchor !== null && $anchorKind === 'CONSULTATION' && $anchor->compare($consultationDay) !== 0) {
            $errors['anchor_date'][] = 'Le départ « consultation » est la date effective de la consultation ('.$consultationDay->toString().').';
        }

        $intervalValue = $payload['interval_value'] ?? null;
        $intervalUnit = $payload['interval_unit'] ?? null;
        if (($intervalValue === null) !== ($intervalUnit === null)) {
            $errors['interval_unit'][] = 'Le délai se donne avec son nombre et son unité.';
        }
        $advanceValue = $payload['advance_value'] ?? null;
        $advanceUnit = $payload['advance_unit'] ?? null;
        if (($advanceValue === null) !== ($advanceUnit === null)) {
            $errors['advance_unit'][] = 'L\'avance du rappel se donne avec son nombre et son unité.';
        }

        $origin = (string) $payload['date_origin'];
        $recommended = SchedulingInput::date($payload['recommended_date'] ?? null, 'recommended_date', $errors);
        $manualReason = SchedulingInput::text($payload['manual_reason'] ?? null, 4000, 'manual_reason', $errors);
        $notes = SchedulingInput::text($payload['notes'] ?? null, 4000, 'notes', $errors);
        if ($origin === 'MANUAL' && ! isset($errors['manual_reason']) && ($manualReason === null || mb_strlen($manualReason) < 3)) {
            $errors['manual_reason'][] = 'Une date saisie à la main porte son motif (au moins 3 caractères).';
        }
        if ($origin === 'CALCULATED' && $anchor !== null && $recommended !== null && ! isset($errors['interval_unit'])) {
            try {
                $expected = FollowUpCalculator::expected($origin, $anchor, $intervalValue === null ? null : (int) $intervalValue, $intervalUnit === null ? null : (string) $intervalUnit);
                if ($expected !== null && $expected->compare($recommended) !== 0) {
                    $errors['recommended_date'][] = 'La date recommandée ne correspond pas au calcul (départ + délai = '.$expected->toString().').';
                }
            } catch (RuleViolation $violation) {
                $errors['interval_value'][] = $violation->getMessage();
            }
        }
        if ($anchor !== null && $recommended !== null && $recommended->compare($anchor) < 0) {
            $errors['recommended_date'][] = 'La date recommandée ne précède pas la date de départ.';
        }
        SchedulingInput::throwIfAny($errors);
        assert($anchor !== null && $recommended !== null);

        $stamp = SchedulingInput::stamp($context->now->utc());
        DB::table('follow_up_plans')->insert([
            'id' => $planId,
            'clinic_id' => $context->clinicId,
            'consultation_id' => (string) $consultation->id,
            'patient_id' => (string) $consultation->patient_id,
            'reason' => (string) $payload['reason'],
            'anchor_kind' => $anchorKind,
            'anchor_date' => $anchor->toString(),
            'interval_value' => $intervalValue === null ? null : (int) $intervalValue,
            'interval_unit' => $intervalUnit,
            'recommended_date' => $recommended->toString(),
            'date_origin' => $origin,
            'manual_reason' => $manualReason,
            'reminder_enabled' => (bool) $payload['reminder_enabled'],
            'preferred_channel' => (string) $payload['preferred_channel'],
            'advance_value' => $advanceValue === null ? null : (int) $advanceValue,
            'advance_unit' => $advanceUnit,
            'notes' => $notes,
            'status' => 'ACTIVE',
            'version' => 1,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
        $plan = DB::table('follow_up_plans')->where('clinic_id', $context->clinicId)->where('id', $planId)->first();
        assert($plan !== null);

        return new CommandOutcome(
            result: ['follow_up_plan_id' => $planId, 'version' => 1],
            resultType: 'follow_up_plans',
            resultId: $planId,
            // Audit sans texte clinique : dates, origine et canal seulement.
            audit: [new AuditEntry('follow_up.created', 'follow_up_plans', $planId, null, [
                'consultation_id' => (string) $consultation->id,
                'recommended_date' => $recommended->toString(),
                'date_origin' => $origin,
                'reminder_enabled' => (bool) $payload['reminder_enabled'],
                'preferred_channel' => (string) $payload['preferred_channel'],
            ])],
            changes: [
                new ChangeRecord('follow_up_plans', $planId, 1, ChangeVisibility::Clinical, ChangeKind::Created),
                ...$this->tasks->forFollowUp($context->clinicId, $timezone, $plan, $context->now->utc()),
            ],
        );
    }
}
