<?php

namespace App\Domain\Patients;

use App\Domain\Patients\Rules\PatientIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Fusion manuelle de deux fiches d'un même patient (L1D08 ; EE001, EE003, EE017 ; fichier 02 : identité durable,
 * historique jamais écrasé). JAMAIS automatique : la gérante compare les deux identités, voit ce qui est rattaché à la
 * fiche absorbée et confirme avec un motif et son mot de passe.
 *
 * Aucune réécriture de l'historique : visites, consultations, prescriptions, factures, reçus et avoirs gardent leur
 * fiche d'origine (`patient_id`) et leurs snapshots ; une facture émise garde l'identité imprimée à son émission. La
 * fiche absorbée garde son identifiant et son numéro de dossier, elle pointe vers la fiche conservée
 * (`merged_into_patient_id`), disparaît de la recherche et refuse toute nouvelle arrivée, facture ou rendez-vous.
 * L'activité de la fiche conservée réunit la sienne et celle des fiches absorbées (`lineage`).
 *
 * Garde-fous (`blockers`) : une fiche déjà fusionnée ne se fusionne plus ; la fiche conservée n'est ni fusionnée ni
 * archivée ; la fiche absorbée n'a ni visite ou examen en cours, ni brouillon de facture, ni rendez-vous à venir (ils
 * changeraient de fiche en silence : on les termine, les abandonne ou les annule d'abord). Un devis (L1D07), même
 * brouillon, n'est pas un garde-fou : il n'est ni une pièce financière ni abandonnable, il reste sur sa fiche d'origine.
 *
 * Lecture seule ici (aperçu et garde-fous) ; l'écriture est dans `Handlers/MergePatientsHandler`.
 */
final class MergePatients
{
    public const REASON_MIN_LENGTH = 5;

    public const REASON_MAX_LENGTH = 1000;

    /** Visites encore ouvertes : arrivée, attente des frais, prête, en examen. */
    public const OPEN_VISIT_STATUSES = ['ARRIVED', 'AWAITING_EXAM_FEE', 'READY_FOR_EXAM', 'IN_CONSULTATION'];

    /** Rendez-vous encore attendus. */
    public const UPCOMING_APPOINTMENT_STATUSES = ['PENDING_CONFIRMATION', 'CONFIRMED'];

    /** Profondeur maximale d'une chaîne de fusions (A dans B, puis B dans C…) relue pour l'activité. */
    private const LINEAGE_DEPTH = 20;

    /**
     * Garde-fous de la fusion de `$source` dans `$survivor`, dans l'ordre d'affichage. Liste vide : fusion possible.
     *
     * @return list<array{code: string, message: string}>
     */
    public static function blockers(string $clinicId, stdClass $source, stdClass $survivor, CarbonImmutable $now): array
    {
        $blockers = [];
        $sourceId = (string) $source->id;

        if ($source->merged_into_patient_id !== null) {
            $blockers[] = ['code' => 'SOURCE_ALREADY_MERGED', 'message' => 'Cette fiche est déjà fusionnée dans une autre.'];
        }
        if ($survivor->merged_into_patient_id !== null) {
            $blockers[] = ['code' => 'SURVIVOR_MERGED', 'message' => 'La fiche à conserver est elle-même fusionnée : choisissez la fiche vers laquelle elle pointe.'];
        }
        if ($survivor->archived_at !== null) {
            $blockers[] = ['code' => 'SURVIVOR_ARCHIVED', 'message' => 'La fiche à conserver est archivée.'];
        }
        if (DB::table('visits')->where('clinic_id', $clinicId)->where('patient_id', $sourceId)->whereIn('status', self::OPEN_VISIT_STATUSES)->exists()) {
            $blockers[] = ['code' => 'VISIT_IN_PROGRESS', 'message' => 'Une visite est en cours sur la fiche à absorber : terminez-la ou annulez-la d\'abord.'];
        }
        if (DB::table('consultations')->where('clinic_id', $clinicId)->where('patient_id', $sourceId)->whereIn('status', ['DRAFT', 'IN_PROGRESS'])->exists()) {
            $blockers[] = ['code' => 'CONSULTATION_IN_PROGRESS', 'message' => 'Un examen n\'est pas terminé sur la fiche à absorber : il doit être finalisé d\'abord.'];
        }
        if (DB::table('invoices')->where('clinic_id', $clinicId)->where('patient_id', $sourceId)->where('status', 'DRAFT')->exists()) {
            $blockers[] = ['code' => 'INVOICE_DRAFT', 'message' => 'Un brouillon de facture existe sur la fiche à absorber : émettez-le ou abandonnez-le d\'abord.'];
        }
        if (self::upcomingAppointments($clinicId, $sourceId, $now) > 0) {
            $blockers[] = ['code' => 'APPOINTMENT_UPCOMING', 'message' => 'Un rendez-vous à venir est pris sur la fiche à absorber : annulez-le ou reprenez-le sur la fiche conservée d\'abord.'];
        }

        return $blockers;
    }

    /**
     * Éléments rattachés à la fiche absorbée, comptés selon ce que le compte peut voir : visites et rendez-vous à
     * venir toujours ; consultations et prescriptions seulement avec la lecture clinique, pièces émises seulement
     * avec la lecture des factures (null sinon : ni le compte ni l'existence ne sortent).
     *
     * @return array{visits: int, appointments_upcoming: int, consultations: int|null, prescriptions: int|null, invoices: int|null}
     */
    public static function linked(string $clinicId, string $sourceId, bool $clinical, bool $financial, CarbonImmutable $now): array
    {
        return [
            'visits' => DB::table('visits')->where('clinic_id', $clinicId)->where('patient_id', $sourceId)->count(),
            'appointments_upcoming' => self::upcomingAppointments($clinicId, $sourceId, $now),
            'consultations' => $clinical ? DB::table('consultations')->where('clinic_id', $clinicId)->where('patient_id', $sourceId)->count() : null,
            'prescriptions' => $clinical ? DB::table('optical_prescriptions')->where('clinic_id', $clinicId)->where('patient_id', $sourceId)->count() : null,
            'invoices' => $financial ? DB::table('invoices')->where('clinic_id', $clinicId)->where('patient_id', $sourceId)->where('status', 'ISSUED')->count() : null,
        ];
    }

    /**
     * Identité figée de la fiche absorbée au moment de la fusion (`patient_merges.snapshot`) : administrative
     * seulement, jamais de donnée clinique.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(stdClass $source, stdClass $survivor): array
    {
        return [
            'source' => [
                'id' => (string) $source->id,
                'record_number' => (string) $source->record_number,
                'legacy_record_number' => $source->legacy_record_number,
                'version' => (int) $source->version,
                'archived_at' => $source->archived_at,
                'created_at' => (string) $source->created_at,
                ...PatientIdentity::fromRow($source),
            ],
            'survivor' => [
                'id' => (string) $survivor->id,
                'record_number' => (string) $survivor->record_number,
                'version' => (int) $survivor->version,
            ],
        ];
    }

    /**
     * La fiche et toutes celles qui y ont été fusionnées, directement ou par une chaîne de fusions (A dans B, puis B
     * dans C : l'activité de C réunit A, B et C). La fiche elle-même vient en premier.
     *
     * @return list<string>
     */
    public static function lineage(string $clinicId, string $patientId): array
    {
        $ids = [$patientId];
        $frontier = [$patientId];
        for ($depth = 0; $depth < self::LINEAGE_DEPTH && $frontier !== []; $depth++) {
            $next = DB::table('patient_records')
                ->where('clinic_id', $clinicId)
                ->whereIn('merged_into_patient_id', $frontier)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();
            $frontier = array_values(array_diff($next, $ids));
            array_push($ids, ...$frontier);
        }

        return $ids;
    }

    /**
     * Fiches fusionnées directement dans celle-ci (numéro et date), de la plus ancienne fusion à la plus récente.
     *
     * @return list<array{patient_id: string, record_number: string, merged_at: string}>
     */
    public static function absorbed(string $clinicId, string $survivorId): array
    {
        $merges = DB::table('patient_merges')
            ->join('patient_records', static function (JoinClause $join): void {
                $join->on('patient_records.clinic_id', '=', 'patient_merges.clinic_id')
                    ->on('patient_records.id', '=', 'patient_merges.source_patient_id');
            })
            ->where('patient_merges.clinic_id', $clinicId)
            ->where('patient_merges.survivor_patient_id', $survivorId)
            ->orderBy('patient_merges.merged_at')
            ->orderBy('patient_merges.id')
            ->get(['patient_merges.source_patient_id', 'patient_records.record_number', 'patient_merges.merged_at'])
            ->map(static fn (stdClass $row): array => [
                'patient_id' => (string) $row->source_patient_id,
                'record_number' => (string) $row->record_number,
                'merged_at' => (string) $row->merged_at,
            ])
            ->all();

        return array_values($merges);
    }

    private static function upcomingAppointments(string $clinicId, string $sourceId, CarbonImmutable $now): int
    {
        return DB::table('appointments')
            ->where('clinic_id', $clinicId)
            ->where('patient_id', $sourceId)
            ->whereIn('status', self::UPCOMING_APPOINTMENT_STATUSES)
            ->where('starts_at', '>=', $now->utc()->format('Y-m-d H:i:s.u'))
            ->count();
    }
}
