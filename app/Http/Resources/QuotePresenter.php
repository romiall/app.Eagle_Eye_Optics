<?php

namespace App\Http\Resources;

use App\Domain\Billing\Quotes;
use App\Domain\Shared\ValueObjects\CivilDate;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * Représentation des devis pour l'API (schémas `Quote`, `QuoteSummary`, `QuoteRevision` de
 * `schemas/resources/billing.json`). Instants en UTC à la milliseconde, dates civiles telles quelles, montants en
 * entiers FCFA, quantités en chaînes exactes.
 *
 * `effective_status` est le statut À L'INSTANT : EXPIRED pour un devis émis ou accepté dont la validité est passée (au
 * jour du cabinet), sans écriture. Le destinataire d'un devis émis est celui FIGÉ à l'émission ou à la dernière
 * révision (`recipient_snapshot`) ; celui d'un brouillon est lu sur la fiche actuelle. Chaque révision rend son
 * snapshot figé, source de son document : une ancienne révision se réimprime à l'identique.
 */
final class QuotePresenter
{
    /**
     * @param  array{quote: stdClass, lines: list<stdClass>, revisions: list<stdClass>, documents: array<int, stdClass>, invoices: list<stdClass>, patient: ?stdClass, prospect: ?stdClass}  $detail
     * @return array<string, mixed>
     */
    public static function quote(array $detail, CivilDate $today): array
    {
        $quote = $detail['quote'];
        $revisions = $detail['revisions'];

        return [
            'id' => $quote->id,
            'number' => $quote->number,
            'status' => $quote->status,
            'effective_status' => Quotes::effectiveStatus($quote, $today),
            'valid_until' => $quote->valid_until,
            'total_gross' => (int) $quote->total_gross,
            'recipient' => self::recipient($quote, $detail['patient'], $detail['prospect']),
            'issued_at' => self::nullableInstant($quote->issued_at),
            'created_by' => $quote->created_by,
            'created_by_name' => isset($quote->created_by_name) && is_string($quote->created_by_name) ? $quote->created_by_name : null,
            'version' => (int) $quote->version,
            'current_revision' => $revisions === [] ? 0 : (int) $revisions[0]->revision_no,
            'lines' => array_map(static fn (stdClass $line): array => [
                'id' => $line->id,
                'position' => (int) $line->position,
                'catalog_item_id' => $line->catalog_item_id,
                'description_snapshot' => $line->description_snapshot,
                'unit_code' => $line->unit_code,
                'quantity' => (string) $line->quantity,
                'unit_price_gross' => (int) $line->unit_price_gross,
                'discount_gross' => (int) $line->discount_gross,
                'line_total_gross' => (int) $line->line_total_gross,
            ], $detail['lines']),
            'revisions' => array_map(static function (stdClass $revision) use ($detail): array {
                $document = $detail['documents'][(int) $revision->revision_no] ?? null;

                return [
                    'id' => $revision->id,
                    'revision_no' => (int) $revision->revision_no,
                    'recorded_at' => self::instant((string) $revision->recorded_at),
                    'authored_by' => $revision->authored_by,
                    'authored_by_name' => is_string($revision->authored_by_name ?? null) ? $revision->authored_by_name : null,
                    'reason' => $revision->reason,
                    'snapshot' => json_decode((string) $revision->snapshot, false, 512, JSON_THROW_ON_ERROR),
                    'document' => $document === null ? null : [
                        'document_id' => $document->id,
                        'document_status' => $document->status,
                    ],
                ];
            }, $revisions),
            'invoices' => array_map(static fn (stdClass $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
            ], $detail['invoices']),
            'created_at' => self::instant((string) $quote->created_at),
            'updated_at' => self::instant((string) $quote->updated_at),
        ];
    }

    /**
     * Ligne de la liste (`QuoteSummary`) : destinataire lu dans les colonnes jointes (`patient_*`, `prospect_*`) ou,
     * pour un devis émis, dans son destinataire figé.
     *
     * @return array<string, mixed>
     */
    public static function summary(stdClass $quote, CivilDate $today): array
    {
        $patient = isset($quote->patient_record_number) ? (object) [
            'id' => $quote->patient_id,
            'record_number' => $quote->patient_record_number,
            'last_name' => $quote->patient_last_name,
            'first_names' => $quote->patient_first_names,
        ] : null;
        $prospect = isset($quote->prospect_name) ? (object) [
            'id' => $quote->prospect_contact_id,
            'name' => $quote->prospect_name,
            'phone' => $quote->prospect_phone ?? null,
        ] : null;

        return [
            'id' => $quote->id,
            'number' => $quote->number,
            'status' => $quote->status,
            'effective_status' => Quotes::effectiveStatus($quote, $today),
            'valid_until' => $quote->valid_until,
            'total_gross' => (int) $quote->total_gross,
            'recipient' => self::recipient($quote, $patient, $prospect),
            'issued_at' => self::nullableInstant($quote->issued_at),
            'current_revision' => (int) ($quote->current_revision ?? 0),
            'version' => (int) $quote->version,
            'created_at' => self::instant((string) $quote->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function recipient(stdClass $quote, ?stdClass $patient, ?stdClass $prospect): array
    {
        if (is_string($quote->recipient_snapshot)) {
            /** @var array<string, mixed> $frozen */
            $frozen = json_decode($quote->recipient_snapshot, true, 512, JSON_THROW_ON_ERROR);

            return $frozen;
        }

        if ($quote->patient_id !== null) {
            return [
                'kind' => 'PATIENT',
                'patient_id' => $quote->patient_id,
                'prospect_contact_id' => null,
                'record_number' => $patient === null ? null : (string) $patient->record_number,
                'last_name' => $patient === null ? 'Patient' : (string) $patient->last_name,
                'first_names' => $patient === null || $patient->first_names === null ? null : (string) $patient->first_names,
                'phone' => null,
            ];
        }

        return [
            'kind' => 'PROSPECT',
            'patient_id' => null,
            'prospect_contact_id' => $quote->prospect_contact_id,
            'record_number' => null,
            'last_name' => $prospect === null ? 'Prospect' : (string) $prospect->name,
            'first_names' => null,
            'phone' => $prospect === null || $prospect->phone === null ? null : (string) $prospect->phone,
        ];
    }

    private static function instant(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function nullableInstant(mixed $stored): ?string
    {
        return $stored === null ? null : self::instant((string) $stored);
    }
}
