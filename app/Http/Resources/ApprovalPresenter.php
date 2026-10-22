<?php

namespace App\Http\Resources;

use App\Domain\Access\Approvals\ApprovalKind;
use App\Domain\Access\Approvals\ExactApprovals;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * Représentation d'une approbation pour l'API (`schemas/resources/billing.json#/$defs/Approval`, B06) : nature, cible,
 * empreinte, contenu exact VÉRIFIÉ contre l'empreinte (null sinon), motif de la demande, statut enregistré et statut
 * EFFECTIF (une demande ou un accord échus sont `EXPIRED`), noms à la date de la demande et de la décision, motif de la
 * décision, échéance et consommation. Aucune donnée clinique.
 */
final class ApprovalPresenter
{
    /**
     * @param  array{subject: ?array<string, mixed>, decision_reason: ?string, requested_by_name: string, decided_by_name: ?string, target: array<string, mixed>}|null  $details
     * @return array<string, mixed>
     */
    public static function approval(stdClass $row, ?array $details, CarbonImmutable $now): array
    {
        $kind = ApprovalKind::fromStored((string) $row->action_code, (string) $row->target_type);

        return [
            'id' => (string) $row->id,
            'kind' => $kind?->value,
            'action_code' => (string) $row->action_code,
            'target_type' => (string) $row->target_type,
            'target_id' => (string) $row->target_id,
            'payload_hash' => (string) $row->payload_hash,
            'subject' => $details['subject'] ?? null,
            'reason' => (string) $row->reason,
            'status' => (string) $row->status,
            'effective_status' => ExactApprovals::effectiveStatus($row, $now),
            'requested_by' => (string) $row->requested_by,
            'requested_by_name' => $details['requested_by_name'] ?? 'Compte inconnu',
            'requested_at' => self::instant((string) $row->created_at),
            'decided_by' => $row->decided_by === null ? null : (string) $row->decided_by,
            'decided_by_name' => $details['decided_by_name'] ?? null,
            'decided_at' => $row->decided_at === null ? null : self::instant((string) $row->decided_at),
            'decision_reason' => $details['decision_reason'] ?? null,
            'expires_at' => $row->expires_at === null ? null : self::instant((string) $row->expires_at),
            'consumed_at' => $row->consumed_at === null ? null : self::instant((string) $row->consumed_at),
            'target' => $details['target'] ?? ['patient' => null, 'invoice_number' => null, 'visit_occurred_at' => null, 'payment' => null],
        ];
    }

    private static function instant(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
