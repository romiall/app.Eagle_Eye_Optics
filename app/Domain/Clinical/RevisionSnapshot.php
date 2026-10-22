<?php

namespace App\Domain\Clinical;

use Carbon\CarbonImmutable;
use stdClass;

/**
 * Snapshot complet d'une révision clinique (`contracts/snapshots/clinical-revision.json`) : la consultation
 * figée à la finalisation (révision 1) ou à une correction (révision N ≥ 2), avec son auteur et son patient
 * TELS QU'À CE MOMENT (nom de l'auteur à la date de l'action, identité administrative de base du patient).
 *
 * Construit sous les verrous du chemin central, à partir des tables vivantes déjà à jour, et écrit dans la même
 * transaction que la révision : un snapshot ne se recalcule jamais ensuite, il se relit.
 */
final class RevisionSnapshot
{
    /**
     * @param  array<string, array<string, mixed>>  $steps  les sept rubriques, telles que lues dans les tables vivantes
     * @param  array<string, mixed>  $patient  identité de base (`ConsultationReader::patient`)
     * @return array<string, mixed>
     */
    public static function build(
        stdClass $consultation,
        array $steps,
        array $patient,
        int $revisionNo,
        CarbonImmutable $recordedAt,
        string $authorId,
        string $authorName,
        ?string $missingDataReason,
        ?string $previousRevisionId,
        ?string $amendmentReason,
    ): array {
        return [
            'consultation_id' => (string) $consultation->id,
            'revision_no' => $revisionNo,
            'recorded_at' => self::instant($recordedAt),
            'author' => ['user_id' => $authorId, 'display_name' => $authorName],
            'patient' => [
                'patient_id' => (string) $patient['id'],
                'record_number' => (string) $patient['record_number'],
                'last_name' => (string) $patient['last_name'],
                'first_names' => $patient['first_names'],
            ],
            'visit_id' => (string) $consultation->visit_id,
            'effective_at' => self::instant(CarbonImmutable::parse((string) $consultation->effective_at, 'UTC')),
            'steps' => $steps,
            'finalization_missing_data_reason' => $missingDataReason,
            'previous_revision_id' => $previousRevisionId,
            'amendment_reason' => $amendmentReason,
        ];
    }

    /** Instant du contrat : UTC, milliseconde, suffixe Z. */
    public static function instant(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Empreinte du CONTENU des rubriques seul (SHA-256 de JCS(steps)) : celle que le poste calcule sur ce qu'il a lu
     * (`snapshot_sha256` de CompleteConsultation) et que le serveur recalcule. Distincte de l'empreinte de la
     * révision (`RevisionHash::hash($snapshot)`), qui couvre aussi l'instant, l'auteur et le patient.
     *
     * @param  array<string, array<string, mixed>>  $steps
     */
    public static function stepsHash(array $steps): string
    {
        return RevisionHash::hash($steps);
    }
}
