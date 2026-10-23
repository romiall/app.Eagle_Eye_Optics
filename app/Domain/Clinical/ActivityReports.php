<?php

namespace App\Domain\Clinical;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\DayWindow;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Support\Modules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques du dossier patient (R02, fichier 02 « Historique et statistiques ») sur une période de jours civils
 * du cabinet (bornes incluses, fuseau du cabinet, jamais minuit UTC). Deux tableaux SÉPARÉS par leurs droits :
 *
 *   activité administrative (`statistics.activity`) : visites non annulées, patients distincts, nouveaux et anciens,
 *   sexe, tranches d'âge ; aucune donnée clinique ni financière ;
 *   statistiques cliniques (`statistics.clinical`) : consultations, motifs, prescriptions optiques validées,
 *   ordonnances de médicaments, orientations, amétropies ; aucun patient identifiable.
 *
 * Définitions (rendues aussi à l'écran) :
 * - patients de la période = patients distincts ayant au moins une visite non annulée dans la période ;
 * - nouveau = dont la PREMIÈRE visite connue au cabinet tombe dans la période (la date de première visite d'une
 *   reprise historique, `first_known_visit_on`, compte si elle est plus ancienne) ; ancien = les autres ;
 * - âge = âge révolu au dernier jour de la période ; date de naissance absente = « âge non renseigné » ;
 * - une consultation compte une fois par motif, une fois si elle a au moins une prescription optique validée,
 *   une fois si elle a au moins un médicament prescrit ; elle appartient à la période par sa date effective.
 *
 * Jamais d'agrégation de mesures : aucune acuité n'est additionnée ni moyennée (échelles différentes). Rendez-vous
 * programmés (non annulés, commençant dans la période) et patients à revoir (patients distincts dont un contrôle
 * recommandé non annulé tombe dans la période) : comptés si le module agenda est ouvert, sinon null (jamais un zéro
 * inventé).
 */
final class ActivityReports
{
    /** Période la plus longue acceptée (une année bissextile complète). */
    public const MAX_DAYS = 366;

    /**
     * Bornes inférieures des tranches d'âge proposées par le fichier 02 (0–17, 18–39, 40–59, 60 et plus), à confirmer
     * par le cabinet.
     */
    public const AGE_BANDS = [0, 18, 40, 60];

    private const SEXES = ['F', 'M', 'UNKNOWN'];

    /**
     * Période demandée : deux dates civiles AAAA-MM-JJ, début ≤ fin, au plus MAX_DAYS jours.
     *
     * @return array{0: CivilDate, 1: CivilDate}
     */
    public static function period(mixed $from, mixed $to): array
    {
        $errors = [];
        $start = self::date($from, 'from', $errors);
        $end = self::date($to, 'to', $errors);
        if ($start !== null && $end !== null) {
            if ($end->compare($start) < 0) {
                $errors['to'][] = 'La fin de la période précède son début.';
            } elseif ($end->dayNumber() - $start->dayNumber() + 1 > self::MAX_DAYS) {
                $errors['to'][] = 'La période couvre au plus '.self::MAX_DAYS.' jours.';
            }
        }
        if ($errors !== [] || $start === null || $end === null) {
            throw BusinessError::validation($errors);
        }

        return [$start, $end];
    }

    /**
     * @return array<string, mixed>
     */
    public function activity(string $clinicId, string $timezone, CivilDate $from, CivilDate $to): array
    {
        [$start, $end] = self::bounds($from, $to, $timezone);
        $visits = fn (): Builder => DB::table('visits')
            ->where('clinic_id', $clinicId)
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->where('status', '<>', 'CANCELLED');

        $byReason = $visits()
            ->select('reason', DB::raw('COUNT(*) as total'))
            ->groupBy('reason')
            ->orderBy('reason')
            ->get()
            ->map(static fn (\stdClass $row): array => ['reason' => (string) $row->reason, 'count' => (int) $row->total])
            ->all();

        $firstVisits = DB::table('visits')
            ->select('patient_id', DB::raw('MIN(occurred_at) as first_at'))
            ->where('clinic_id', $clinicId)
            ->where('status', '<>', 'CANCELLED')
            ->groupBy('patient_id');

        $patients = DB::table('patient_records as p')
            ->joinSub($visits()->select('patient_id')->distinct(), 'pp', static function ($join): void {
                $join->on('pp.patient_id', '=', 'p.id');
            })
            ->joinSub($firstVisits, 'fv', static function ($join): void {
                $join->on('fv.patient_id', '=', 'p.id');
            })
            ->where('p.clinic_id', $clinicId)
            ->orderBy('p.id')
            ->get(['p.sex', 'p.birth_date', 'p.first_known_visit_on', 'fv.first_at']);

        $sex = array_fill_keys(self::SEXES, 0);
        $bands = array_fill(0, count(self::AGE_BANDS), 0);
        $ageUnknown = 0;
        $new = 0;
        foreach ($patients as $patient) {
            $code = in_array((string) $patient->sex, self::SEXES, true) ? (string) $patient->sex : 'UNKNOWN';
            $sex[$code]++;

            $age = self::ageAt($patient->birth_date, $to);
            if ($age === null) {
                $ageUnknown++;
            } else {
                $bands[self::bandOf($age)]++;
            }

            $first = CivilDate::fromString(
                CarbonImmutable::parse((string) $patient->first_at, 'UTC')->setTimezone($timezone)->format('Y-m-d'),
                'first_visit',
            );
            if ($patient->first_known_visit_on !== null) {
                $legacy = CivilDate::fromString(substr((string) $patient->first_known_visit_on, 0, 10), 'first_known_visit_on');
                if ($legacy->compare($first) < 0) {
                    $first = $legacy;
                }
            }
            if ($first->compare($from) >= 0) {
                $new++;
            }
        }

        $total = count($patients);

        return [
            'from' => $from->toString(),
            'to' => $to->toString(),
            'timezone' => $timezone,
            'visits' => array_sum(array_column($byReason, 'count')),
            'visits_by_reason' => $byReason,
            'patients' => $total,
            'new_patients' => $new,
            'returning_patients' => $total - $new,
            'sex' => array_map(static fn (string $code): array => ['sex' => $code, 'count' => $sex[$code]], self::SEXES),
            'age_bands' => array_map(
                static fn (int $index): array => [
                    'min_age' => self::AGE_BANDS[$index],
                    'max_age' => isset(self::AGE_BANDS[$index + 1]) ? self::AGE_BANDS[$index + 1] - 1 : null,
                    'count' => $bands[$index],
                ],
                array_keys(self::AGE_BANDS),
            ),
            'age_unknown' => $ageUnknown,
            // Agenda interne (S01) et patients à revoir (S02) : seulement si le module agenda est ouvert ; sinon aucune
            // valeur, jamais un zéro inventé.
            'appointments' => Modules::isEnabled('agenda')
                ? DB::table('appointments')
                    ->where('clinic_id', $clinicId)
                    ->where('starts_at', '>=', $start)
                    ->where('starts_at', '<', $end)
                    ->where('status', '<>', 'CANCELLED')
                    ->count()
                : null,
            'follow_ups_due' => Modules::isEnabled('agenda')
                ? DB::table('follow_up_plans')
                    ->where('clinic_id', $clinicId)
                    ->where('status', '<>', 'CANCELLED')
                    ->where('recommended_date', '>=', $from->toString())
                    ->where('recommended_date', '<=', $to->toString())
                    ->distinct()
                    ->count('patient_id')
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function clinical(string $clinicId, string $timezone, CivilDate $from, CivilDate $to): array
    {
        [$start, $end] = self::bounds($from, $to, $timezone);
        $consultations = fn (): Builder => DB::table('consultations')
            ->where('clinic_id', $clinicId)
            ->where('effective_at', '>=', $start)
            ->where('effective_at', '<', $end);
        $ids = fn (): Builder => $consultations()->select('id');

        $perConsultation = fn (string $table, string $column = 'term_code'): array => DB::table($table)
            ->select($column, DB::raw('COUNT(DISTINCT consultation_id) as total'))
            ->where('clinic_id', $clinicId)
            ->whereIn('consultation_id', $ids())
            ->groupBy($column)
            ->orderByDesc('total')
            ->orderBy($column)
            ->get()
            ->map(static fn (\stdClass $row): array => ['term_code' => (string) $row->{$column}, 'count' => (int) $row->total])
            ->all();

        $distinctConsultations = fn (Builder $query): int => (int) $query
            ->where('clinic_id', $clinicId)
            ->whereIn('consultation_id', $ids())
            ->distinct()
            ->count('consultation_id');

        $byUrgency = $consultations()
            ->where('referral', true)
            ->select('referral_urgency', DB::raw('COUNT(*) as total'))
            ->groupBy('referral_urgency')
            ->orderBy('referral_urgency')
            ->get()
            ->map(static fn (\stdClass $row): array => [
                'urgency' => $row->referral_urgency === null ? null : (string) $row->referral_urgency,
                'count' => (int) $row->total,
            ])
            ->all();

        return [
            'from' => $from->toString(),
            'to' => $to->toString(),
            'timezone' => $timezone,
            'consultations' => $consultations()->count(),
            'consultations_completed' => $consultations()->where('status', 'COMPLETED')->count(),
            'complaints' => $perConsultation('consultation_complaints'),
            'consultations_without_complaint' => $consultations()
                ->whereNotExists(static function ($query): void {
                    $query->select(DB::raw(1))
                        ->from('consultation_complaints as cc')
                        ->whereColumn('cc.clinic_id', 'consultations.clinic_id')
                        ->whereColumn('cc.consultation_id', 'consultations.id');
                })
                ->count(),
            'prescriptions_validated' => $distinctConsultations(
                DB::table('optical_prescriptions')->where('status', 'VALIDATED')->where('origin', 'INTERNAL'),
            ),
            'medication_orders' => $distinctConsultations(DB::table('medication_orders')),
            'referrals' => array_sum(array_column($byUrgency, 'count')),
            'referrals_by_urgency' => $byUrgency,
            'referral_unknown' => $consultations()->whereNull('referral')->count(),
            'ametropias' => $perConsultation('consultation_ametropias'),
        ];
    }

    /**
     * @return array{0: string, 1: string} début inclus et fin exclue (UTC)
     */
    private static function bounds(CivilDate $from, CivilDate $to, string $timezone): array
    {
        return [DayWindow::utc($from, $timezone)[0], DayWindow::utc($to, $timezone)[1]];
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private static function date(mixed $value, string $field, array &$errors): ?CivilDate
    {
        if (! is_string($value) || $value === '') {
            $errors[$field][] = 'La date est obligatoire (AAAA-MM-JJ).';

            return null;
        }
        try {
            return CivilDate::fromString($value, $field);
        } catch (RuleViolation $violation) {
            $errors[$field][] = $violation->getMessage();

            return null;
        }
    }

    private static function ageAt(mixed $birthDate, CivilDate $reference): ?int
    {
        if ($birthDate === null || $birthDate === '') {
            return null;
        }
        $birth = CivilDate::fromString(substr((string) $birthDate, 0, 10), 'birth_date');
        if ($reference->compare($birth) < 0) {
            return null;
        }

        return $birth->ageOn($reference);
    }

    private static function bandOf(int $age): int
    {
        $band = 0;
        foreach (self::AGE_BANDS as $index => $minimum) {
            if ($age >= $minimum) {
                $band = $index;
            }
        }

        return $band;
    }
}
