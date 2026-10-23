<?php

namespace App\Domain\Scheduling;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\DayWindow;
use App\Domain\Shared\ValueObjects\CivilDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Disponibilités d'une ressource de l'agenda (S01, fichier 02 « Rendez-vous et contrôle ») : créneaux hebdomadaires
 * (`resource_opening_hours`, heure locale du cabinet, 1 = lundi … 7 = dimanche, période de validité facultative),
 * moins les fermetures datées (`resource_exceptions.is_available = 0`), les indisponibilités (`calendar_blocks`) et
 * les rendez-vous qui occupent déjà le créneau ; plus les ouvertures exceptionnelles (`is_available = 1`).
 *
 * Aucun horaire n'est inventé : une ressource sans créneau hebdomadaire n'a aucune disponibilité. La réservation
 * revérifie SOUS le verrou exclusif du cabinet (pris par le chemin central avant toute mutation) : deux réservations
 * concurrentes du même créneau sont sérialisées, la seconde est refusée (409 `SLOT_UNAVAILABLE`).
 */
final class AvailabilityService
{
    /** Statuts qui occupent un créneau ; une annulation ou une absence le libère. */
    public const OCCUPYING = ['PENDING_CONFIRMATION', 'CONFIRMED', 'ARRIVED', 'COMPLETED'];

    /** Durée la plus longue d'un rendez-vous (une journée de travail). */
    public const MAX_DURATION_MINUTES = 480;

    /**
     * Fenêtres d'ouverture d'un jour civil du cabinet, en instants UTC triés et fusionnés.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function openWindows(string $clinicId, string $resourceId, CivilDate $day, string $timezone): array
    {
        $date = $day->toString();
        $weekday = CarbonImmutable::parse($date, $timezone)->dayOfWeekIso;
        $windows = [];
        $hours = DB::table('resource_opening_hours')
            ->where('clinic_id', $clinicId)
            ->where('resource_id', $resourceId)
            ->where('weekday', $weekday)
            ->where(static fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(static fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->orderBy('opens_at')
            ->get(['opens_at', 'closes_at']);
        foreach ($hours as $hour) {
            $windows[] = [
                CarbonImmutable::parse($date.' '.substr((string) $hour->opens_at, 0, 5), $timezone)->utc(),
                CarbonImmutable::parse($date.' '.substr((string) $hour->closes_at, 0, 5), $timezone)->utc(),
            ];
        }

        [$dayStart, $dayEnd] = DayWindow::utc($day, $timezone);
        $openings = DB::table('resource_exceptions')
            ->where('clinic_id', $clinicId)
            ->where('resource_id', $resourceId)
            ->where('is_available', true)
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->get(['starts_at', 'ends_at']);
        $start = CarbonImmutable::parse($dayStart, 'UTC');
        $end = CarbonImmutable::parse($dayEnd, 'UTC');
        foreach ($openings as $opening) {
            $windows[] = [
                self::max(CarbonImmutable::parse((string) $opening->starts_at, 'UTC'), $start),
                self::min(CarbonImmutable::parse((string) $opening->ends_at, 'UTC'), $end),
            ];
        }

        return self::merge($windows);
    }

    /**
     * Périodes indisponibles d'une ressource qui chevauchent [from, to[ : fermetures, indisponibilités et rendez-vous
     * qui occupent le créneau (sauf `$ignoreAppointmentId`, pour un déplacement).
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}>
     */
    public function busy(string $clinicId, string $resourceId, CarbonImmutable $from, CarbonImmutable $to, ?string $ignoreAppointmentId = null): array
    {
        $lower = SchedulingInput::stamp($from);
        $upper = SchedulingInput::stamp($to);
        $periods = [];

        $appointments = DB::table('appointments')
            ->where('clinic_id', $clinicId)
            ->where('resource_id', $resourceId)
            ->whereIn('status', self::OCCUPYING)
            ->where('starts_at', '<', $upper)
            ->where('ends_at', '>', $lower);
        if ($ignoreAppointmentId !== null) {
            $appointments->where('id', '<>', $ignoreAppointmentId);
        }
        foreach ($appointments->get(['starts_at', 'ends_at']) as $row) {
            $periods[] = [CarbonImmutable::parse((string) $row->starts_at, 'UTC'), CarbonImmutable::parse((string) $row->ends_at, 'UTC'), 'APPOINTMENT'];
        }

        $blocks = DB::table('calendar_blocks')
            ->where('clinic_id', $clinicId)
            ->where('resource_id', $resourceId)
            ->where('starts_at', '<', $upper)
            ->where('ends_at', '>', $lower)
            ->get(['starts_at', 'ends_at']);
        foreach ($blocks as $row) {
            $periods[] = [CarbonImmutable::parse((string) $row->starts_at, 'UTC'), CarbonImmutable::parse((string) $row->ends_at, 'UTC'), 'BLOCK'];
        }

        $closures = DB::table('resource_exceptions')
            ->where('clinic_id', $clinicId)
            ->where('resource_id', $resourceId)
            ->where('is_available', false)
            ->where('starts_at', '<', $upper)
            ->where('ends_at', '>', $lower)
            ->get(['starts_at', 'ends_at']);
        foreach ($closures as $row) {
            $periods[] = [CarbonImmutable::parse((string) $row->starts_at, 'UTC'), CarbonImmutable::parse((string) $row->ends_at, 'UTC'), 'CLOSURE'];
        }

        return $periods;
    }

    /**
     * Créneaux libres d'un jour : départs au pas de la ressource à partir de chaque ouverture, de la durée de la
     * prestation, entièrement dans une ouverture, sans chevauchement, jamais dans le passé.
     *
     * @return list<array{starts_at: string, ends_at: string}>
     */
    public function slots(string $clinicId, string $timezone, stdClass $resource, stdClass $service, CivilDate $day, CarbonImmutable $now): array
    {
        if (! (bool) $resource->active || ! (bool) $service->active) {
            return [];
        }
        $step = max(1, (int) $resource->slot_duration_minutes);
        $duration = (int) $service->duration_minutes;
        $windows = $this->openWindows($clinicId, (string) $resource->id, $day, $timezone);
        if ($windows === []) {
            return [];
        }
        $busy = $this->busy($clinicId, (string) $resource->id, $windows[0][0], $windows[count($windows) - 1][1]);

        $slots = [];
        foreach ($windows as [$open, $close]) {
            for ($start = $open; $start->addMinutes($duration)->lessThanOrEqualTo($close); $start = $start->addMinutes($step)) {
                $end = $start->addMinutes($duration);
                if ($start->lessThan($now) || self::overlapsAny($start, $end, $busy)) {
                    continue;
                }
                $slots[] = ['starts_at' => $start->format('Y-m-d\TH:i:s.v\Z'), 'ends_at' => $end->format('Y-m-d\TH:i:s.v\Z')];
            }
        }

        return $slots;
    }

    /**
     * Contrôle d'une réservation SOUS verrou : ressource et prestation actives, durée plausible, créneau entièrement
     * dans une ouverture du jour (422 sinon), aucun chevauchement (409 `SLOT_UNAVAILABLE`).
     */
    public function assertBookable(
        string $clinicId,
        string $timezone,
        stdClass $resource,
        stdClass $service,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $ignoreAppointmentId = null,
    ): void {
        $errors = [];
        if (! (bool) $resource->active) {
            $errors['resource_id'][] = 'Cette ressource de l\'agenda est désactivée.';
        }
        if (! (bool) $service->active) {
            $errors['service_id'][] = 'Cette prestation n\'est plus proposée.';
        }
        $minutes = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
        if (! $end->greaterThan($start)) {
            $errors['ends_at'][] = 'La fin du rendez-vous doit suivre son début.';
        } elseif ($minutes > self::MAX_DURATION_MINUTES) {
            $errors['ends_at'][] = 'Un rendez-vous dure au plus '.self::MAX_DURATION_MINUTES.' minutes.';
        }
        SchedulingInput::throwIfAny($errors);

        $day = CivilDate::fromString($start->setTimezone($timezone)->format('Y-m-d'), 'starts_at');
        $inside = false;
        foreach ($this->openWindows($clinicId, (string) $resource->id, $day, $timezone) as [$open, $close]) {
            if ($start->greaterThanOrEqualTo($open) && $end->lessThanOrEqualTo($close)) {
                $inside = true;
                break;
            }
        }
        if (! $inside) {
            throw BusinessError::validation(['starts_at' => ['Ce créneau est en dehors des horaires d\'ouverture de la ressource.']]);
        }

        $conflicts = $this->busy($clinicId, (string) $resource->id, $start, $end, $ignoreAppointmentId);
        if ($conflicts !== []) {
            throw new BusinessError(
                'SLOT_UNAVAILABLE',
                'Ce créneau n\'est plus libre : un autre rendez-vous, une fermeture ou une indisponibilité l\'occupe. Choisissez un autre créneau.',
                409,
                [],
                null,
                [],
                ['conflicts' => count($conflicts)],
            );
        }
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}>  $periods
     */
    private static function overlapsAny(CarbonImmutable $start, CarbonImmutable $end, array $periods): bool
    {
        foreach ($periods as [$from, $to]) {
            if ($start->lessThan($to) && $end->greaterThan($from)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $windows
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private static function merge(array $windows): array
    {
        $windows = array_values(array_filter($windows, static fn (array $window): bool => $window[1]->greaterThan($window[0])));
        usort($windows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($windows as [$from, $to]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $from->lessThanOrEqualTo($merged[$last][1])) {
                $merged[$last] = [$merged[$last][0], self::max($merged[$last][1], $to)];
            } else {
                $merged[] = [$from, $to];
            }
        }

        return $merged;
    }

    private static function max(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->greaterThan($b) ? $a : $b;
    }

    private static function min(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->lessThan($b) ? $a : $b;
    }
}
