<?php

namespace App\Domain\Communication;

use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Domain\Scheduling\FollowUpCalculator;
use App\Domain\Scheduling\SchedulingInput;
use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\CivilDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Tâches de contact INTERNES (S02 ; fichier 02 « Rappels DP », fichier 10) : la liste quotidienne de qui appeler ou
 * relancer, jamais un envoi. En L0 aucun SMS ni WhatsApp réel ne part : le canal d'une tâche est la préférence du
 * patient, que la personne à l'accueil utilise elle-même ; L2 activera les transmissions par l'outbox.
 *
 * Une tâche est UNIQUE par source et par échéance (`dedupe_key`, clé unique du schéma) : un rejeu ou un nouveau calcul
 * ne crée jamais deux relances pour le même événement. Quand la source change (rendez-vous déplacé, contrôle
 * reprogrammé), les tâches ouvertes de l'ancienne programmation passent `CANCELLED` (jamais supprimées) et la nouvelle
 * est créée : la précédente reste dans l'historique.
 *
 * Sources en L0 :
 * - contrôle recommandé avec rappel activé : une tâche à la date de rappel (date recommandée moins l'avance choisie,
 *   ou la date recommandée sans avance), à minuit du cabinet ;
 * - rendez-vous à venir : une tâche par politique de rappel active « APPOINTMENT » du cabinet (`reminder_policies`,
 *   par exemple la veille à 18 h ou deux heures avant), si son échéance n'est pas déjà passée.
 *
 * Toutes ces écritures ont lieu dans la transaction de la commande qui les déclenche (verrou exclusif du cabinet
 * déjà tenu) : elles sont atomiques avec elle.
 */
final class ContactTasks
{
    /** Statuts d'une tâche encore à traiter. */
    public const OPEN = ['PENDING', 'NO_RESPONSE', 'POSTPONED'];

    /**
     * Tâche de rappel d'un contrôle recommandé (rappel activé) ; annule les tâches ouvertes d'une autre échéance.
     *
     * @return list<ChangeRecord>
     */
    public function forFollowUp(string $clinicId, string $timezone, stdClass $plan, CarbonImmutable $now): array
    {
        $changes = [];
        $keep = null;
        if ((bool) $plan->reminder_enabled && in_array((string) $plan->status, ['ACTIVE'], true)) {
            $due = FollowUpCalculator::reminderDate(
                CivilDate::fromString(substr((string) $plan->recommended_date, 0, 10), 'recommended_date'),
                $plan->advance_value === null ? null : (int) $plan->advance_value,
                $plan->advance_unit === null ? null : (string) $plan->advance_unit,
            );
            $keep = 'FOLLOW_UP:'.$plan->id.':'.$due->toString();
            $dueAt = CarbonImmutable::parse($due->toString().' 00:00', $timezone)->utc();
            $created = $this->create($clinicId, $keep, [
                'patient_id' => (string) $plan->patient_id,
                'follow_up_id' => (string) $plan->id,
                'source_version' => (int) $plan->version,
                'due_at' => $dueAt,
                'channel' => self::channel((string) $plan->preferred_channel),
            ], $now);
            if ($created !== null) {
                $changes[] = $created;
            }
        }

        return [...$changes, ...$this->closeOpen($clinicId, 'follow_up_id', (string) $plan->id, 'CANCELLED', $now, $keep)];
    }

    /**
     * Tâches de rappel d'un rendez-vous à venir (politiques actives « APPOINTMENT ») ; annule celles d'une autre heure.
     *
     * @return list<ChangeRecord>
     */
    public function forAppointment(string $clinicId, string $timezone, stdClass $appointment, CarbonImmutable $now): array
    {
        $starts = CarbonImmutable::parse((string) $appointment->starts_at, 'UTC');
        $keep = [];
        $changes = [];
        if (in_array((string) $appointment->status, ['CONFIRMED', 'PENDING_CONFIRMATION'], true) && $appointment->patient_id !== null) {
            $policies = DB::table('reminder_policies')
                ->where('clinic_id', $clinicId)
                ->where('purpose', 'APPOINTMENT')
                ->where('active', true)
                ->orderBy('code')
                ->get();
            foreach ($policies as $policy) {
                $due = self::appointmentDue($starts, $policy, $timezone);
                if ($due === null || ! $due->greaterThan($now)) {
                    continue;
                }
                $key = 'APPOINTMENT:'.$appointment->id.':'.$policy->code.':'.$starts->format('Y-m-d\TH:i');
                $keep[] = $key;
                $created = $this->create($clinicId, $key, [
                    'patient_id' => (string) $appointment->patient_id,
                    'appointment_id' => (string) $appointment->id,
                    'policy_id' => (string) $policy->id,
                    'source_version' => (int) $appointment->version,
                    'due_at' => $due,
                    'channel' => self::channel((string) $policy->channel),
                ], $now);
                if ($created !== null) {
                    $changes[] = $created;
                }
            }
        }

        return [...$changes, ...$this->closeOpenExcept($clinicId, 'appointment_id', (string) $appointment->id, 'CANCELLED', $now, $keep)];
    }

    /**
     * Ferme les tâches ouvertes d'une source (rendez-vous annulé, arrivé ou manqué ; contrôle programmé).
     *
     * @return list<ChangeRecord>
     */
    public function closeOpen(string $clinicId, string $column, string $sourceId, string $status, CarbonImmutable $now, ?string $exceptKey = null): array
    {
        return $this->closeOpenExcept($clinicId, $column, $sourceId, $status, $now, $exceptKey === null ? [] : [$exceptKey]);
    }

    /**
     * @param  list<string>  $exceptKeys
     * @return list<ChangeRecord>
     */
    private function closeOpenExcept(string $clinicId, string $column, string $sourceId, string $status, CarbonImmutable $now, array $exceptKeys): array
    {
        $query = DB::table('contact_tasks')
            ->where('clinic_id', $clinicId)
            ->where($column, $sourceId)
            ->whereIn('status', self::OPEN);
        if ($exceptKeys !== []) {
            $query->whereNotIn('dedupe_key', $exceptKeys);
        }
        $rows = $query->orderBy('id')->get(['id', 'version']);
        $changes = [];
        foreach ($rows as $row) {
            $version = (int) $row->version + 1;
            DB::table('contact_tasks')->where('clinic_id', $clinicId)->where('id', $row->id)->update([
                'status' => $status,
                'next_attempt_at' => null,
                'version' => $version,
                'updated_at' => SchedulingInput::stamp($now),
            ]);
            $changes[] = new ChangeRecord('contact_tasks', (string) $row->id, $version, ChangeVisibility::Administrative, ChangeKind::Updated);
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function create(string $clinicId, string $key, array $source, CarbonImmutable $now): ?ChangeRecord
    {
        if (DB::table('contact_tasks')->where('clinic_id', $clinicId)->where('dedupe_key', $key)->exists()) {
            return null;
        }
        $id = Ulid::generate();
        $stamp = SchedulingInput::stamp($now);
        /** @var CarbonImmutable $due */
        $due = $source['due_at'];
        DB::table('contact_tasks')->insert([
            'id' => $id,
            'clinic_id' => $clinicId,
            'patient_id' => $source['patient_id'],
            'prospect_contact_id' => null,
            'follow_up_id' => $source['follow_up_id'] ?? null,
            'appointment_id' => $source['appointment_id'] ?? null,
            'invoice_id' => null,
            'workshop_order_id' => null,
            'policy_id' => $source['policy_id'] ?? null,
            'source_version' => $source['source_version'],
            'due_at' => SchedulingInput::stamp($due),
            'channel' => $source['channel'],
            'status' => 'PENDING',
            'next_attempt_at' => null,
            'assigned_to' => null,
            'dedupe_key' => $key,
            'administrative_notes' => null,
            'version' => 1,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        return new ChangeRecord('contact_tasks', $id, 1, ChangeVisibility::Administrative, ChangeKind::Created);
    }

    /**
     * Échéance d'un rappel de rendez-vous : `offset_value` heures avant, ou `offset_value` jours / mois avant à l'heure
     * locale `local_send_time` (à défaut, à l'heure du rendez-vous).
     */
    private static function appointmentDue(CarbonImmutable $starts, stdClass $policy, string $timezone): ?CarbonImmutable
    {
        $value = (int) $policy->offset_value;
        if ($value < 0) {
            return null;
        }

        return match ((string) $policy->offset_unit) {
            'HOUR' => $starts->subHours($value),
            'DAY', 'MONTH' => self::atLocalTime(
                (string) $policy->offset_unit === 'DAY'
                    ? $starts->setTimezone($timezone)->subDays($value)
                    : $starts->setTimezone($timezone)->subMonthsNoOverflow($value),
                $policy->local_send_time === null ? null : (string) $policy->local_send_time,
                $timezone,
            ),
            default => null,
        };
    }

    private static function atLocalTime(CarbonImmutable $local, ?string $time, string $timezone): CarbonImmutable
    {
        if ($time === null) {
            return $local->utc();
        }

        return CarbonImmutable::parse($local->format('Y-m-d').' '.substr($time, 0, 5), $timezone)->utc();
    }

    /** Canal de la tâche : celui du patient ou de la politique (aucun envoi en L0). */
    private static function channel(string $channel): string
    {
        return in_array($channel, ['INTERNAL', 'CALL', 'SMS', 'WHATSAPP'], true) ? $channel : 'INTERNAL';
    }
}
