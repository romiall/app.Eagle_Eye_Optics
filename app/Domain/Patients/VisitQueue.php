<?php

namespace App\Domain\Patients;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\DayWindow;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\Instant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * File du jour (P03, fichier 02) : les visites d'un jour civil du cabinet, de la plus ancienne arrivée à la
 * plus récente, avec l'identité administrative du patient. Aucune donnée clinique. Toujours limitée au cabinet.
 * Le jour est celui du fuseau du cabinet (jamais minuit UTC) ; par défaut, aujourd'hui.
 */
final class VisitQueue
{
    public const PER_PAGE = 50;

    /**
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function __invoke(string $clinicId, string $timezone, CarbonImmutable $now, mixed $date, mixed $page = null): LengthAwarePaginator
    {
        $day = $this->day($timezone, $now, $date);
        $pageNumber = 1;
        if ($page !== null) {
            if ((! is_string($page) && ! is_int($page)) || filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw BusinessError::validation(['page' => ['La page est un entier supérieur ou égal à 1.']]);
            }
            $pageNumber = (int) $page;
        }
        [$start, $end] = DayWindow::utc($day, $timezone);

        return DB::table('visits')
            ->join('patient_records', static function ($join): void {
                $join->on('patient_records.clinic_id', '=', 'visits.clinic_id')->on('patient_records.id', '=', 'visits.patient_id');
            })
            ->where('visits.clinic_id', $clinicId)
            ->where('visits.occurred_at', '>=', $start)
            ->where('visits.occurred_at', '<', $end)
            ->orderBy('visits.occurred_at')
            ->orderBy('visits.id')
            ->select('visits.*', ...self::patientColumns())
            ->paginate(self::PER_PAGE, ['*'], 'page', $pageNumber);
    }

    /**
     * Une visite et l'identité de son patient, dans le cabinet.
     */
    public static function find(string $clinicId, string $visitId): ?stdClass
    {
        return DB::table('visits')
            ->join('patient_records', static function ($join): void {
                $join->on('patient_records.clinic_id', '=', 'visits.clinic_id')->on('patient_records.id', '=', 'visits.patient_id');
            })
            ->where('visits.clinic_id', $clinicId)
            ->where('visits.id', $visitId)
            ->select('visits.*', ...self::patientColumns())
            ->first();
    }

    /**
     * @return list<string>
     */
    private static function patientColumns(): array
    {
        return [
            'patient_records.record_number as patient_record_number',
            'patient_records.last_name as patient_last_name',
            'patient_records.first_names as patient_first_names',
            'patient_records.sex as patient_sex',
            'patient_records.birth_date as patient_birth_date',
        ];
    }

    private function day(string $timezone, CarbonImmutable $now, mixed $date): CivilDate
    {
        if ($date === null || $date === '') {
            return Instant::fromEpochMilliseconds((int) $now->format('Uv'))->civilDate($timezone);
        }
        try {
            return CivilDate::fromString(is_string($date) ? $date : '', 'date');
        } catch (RuleViolation $violation) {
            throw BusinessError::validation(['date' => [$violation->getMessage()]]);
        }
    }
}
