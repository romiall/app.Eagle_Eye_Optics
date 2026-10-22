<?php

namespace App\Domain\Access\Approvals;

use App\Domain\Shared\BusinessError;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Lectures des approbations du cabinet (B06) : la liste de la gérante (toutes les demandes) et le suivi d'une demandeuse
 * (ses propres demandes seulement). Toujours limitées au cabinet ; seules les natures du registre (`ApprovalKind`) sont
 * listées (une autorisation directe de stock initial n'est pas une demande).
 *
 * Le contenu exact d'une demande est relu dans l'audit de sa demande (`approval.requested`, aucune colonne ne le porte)
 * puis REVÉRIFIÉ contre l'empreinte enregistrée : un contenu qui ne la reproduit pas n'est jamais affiché comme exact
 * (null). Le motif d'une décision vient de l'audit `approval.decided`. La cible est résumée pour la décision : identité
 * administrative du patient, numéro de facture, heure de la visite, paiement visé. Aucune donnée clinique.
 */
final class ApprovalReader
{
    public const PER_PAGE = 25;

    /** Filtres de statut admis (statut effectif : une demande échue est `EXPIRED`). */
    public const FILTERS = ['REQUESTED', 'APPROVED', 'REFUSED', 'EXPIRED', 'CONSUMED'];

    /**
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function page(string $clinicId, ?string $requestedBy, mixed $status, mixed $page, CarbonImmutable $now): LengthAwarePaginator
    {
        $pageNumber = 1;
        if ($page !== null) {
            if ((! is_string($page) && ! is_int($page)) || filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw BusinessError::validation(['page' => ['La page est un entier supérieur ou égal à 1.']]);
            }
            $pageNumber = (int) $page;
        }
        if ($status !== null && $status !== '' && (! is_string($status) || ! in_array($status, self::FILTERS, true))) {
            throw BusinessError::validation(['status' => ['Statut inconnu : REQUESTED, APPROVED, REFUSED, EXPIRED ou CONSUMED.']]);
        }

        $query = $this->base($clinicId);
        if ($requestedBy !== null) {
            $query->where('requested_by', $requestedBy);
        }
        $stored = $now->utc()->format('Y-m-d H:i:s.u');
        if ($status === 'REQUESTED' || $status === 'APPROVED') {
            $query->where('status', $status)->where(static function (Builder $open) use ($stored): void {
                $open->whereNull('expires_at')->orWhere('expires_at', '>', $stored);
            });
        } elseif ($status === 'EXPIRED') {
            $query->whereIn('status', ['REQUESTED', 'APPROVED'])->whereNotNull('expires_at')->where('expires_at', '<=', $stored);
        } elseif ($status === 'REFUSED' || $status === 'CONSUMED') {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate(self::PER_PAGE, ['*'], 'page', $pageNumber);
    }

    public function find(string $clinicId, string $approvalId): ?stdClass
    {
        $row = $this->base($clinicId)->where('id', $approvalId)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * Détails d'affichage par identifiant : contenu exact vérifié, motif de décision, noms à la date, résumé de cible.
     *
     * @param  list<stdClass>  $rows
     * @return array<string, array{subject: ?array<string, mixed>, decision_reason: ?string, requested_by_name: string, decided_by_name: ?string, target: array<string, mixed>}>
     */
    public function details(string $clinicId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);

        $events = DB::table('audit_events')
            ->where('clinic_id', $clinicId)
            ->where('target_type', 'approvals')
            ->whereIn('target_id', $ids)
            ->whereIn('action', ['approval.requested', 'approval.decided'])
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['target_id', 'action', 'actor_name_snapshot', 'reason', 'sanitized_diff']);
        $requested = [];
        $decided = [];
        foreach ($events as $event) {
            if ((string) $event->action === 'approval.requested') {
                $requested[(string) $event->target_id] ??= $event;
            } else {
                $decided[(string) $event->target_id] ??= $event;
            }
        }

        $userIds = [];
        foreach ($rows as $row) {
            $userIds[] = (string) $row->requested_by;
            if ($row->decided_by !== null) {
                $userIds[] = (string) $row->decided_by;
            }
        }
        $names = DB::table('users')->where('clinic_id', $clinicId)->whereIn('id', array_values(array_unique($userIds)))->pluck('display_name', 'id')->all();

        $details = [];
        foreach ($rows as $row) {
            $id = (string) $row->id;
            $subject = null;
            $request = $requested[$id] ?? null;
            if ($request !== null && is_string($request->sanitized_diff)) {
                $diff = json_decode($request->sanitized_diff, true);
                if (is_array($diff) && is_array($diff['subject'] ?? null)) {
                    $subject = ApprovalSubject::verified($diff['subject'], (string) $row->payload_hash);
                }
            }
            $decision = $decided[$id] ?? null;
            $details[$id] = [
                'subject' => $subject,
                'decision_reason' => $decision !== null && is_string($decision->reason) ? $decision->reason : null,
                'requested_by_name' => $request !== null ? (string) $request->actor_name_snapshot : (string) ($names[(string) $row->requested_by] ?? 'Compte inconnu'),
                'decided_by_name' => $row->decided_by === null ? null : ($decision !== null ? (string) $decision->actor_name_snapshot : (string) ($names[(string) $row->decided_by] ?? 'Compte inconnu')),
                'target' => $this->target($clinicId, $row, $subject),
            ];
        }

        return $details;
    }

    /**
     * Résumé de la cible pour la décision.
     *
     * @param  array<string, mixed>|null  $subject
     * @return array{patient: ?array<string, mixed>, invoice_number: ?string, visit_occurred_at: ?string, payment: ?array<string, mixed>}
     */
    private function target(string $clinicId, stdClass $row, ?array $subject): array
    {
        $target = ['patient' => null, 'invoice_number' => null, 'visit_occurred_at' => null, 'payment' => null];
        $patientId = is_string($subject['patient_id'] ?? null) ? $subject['patient_id'] : null;
        $targetId = (string) $row->target_id;

        switch ((string) $row->target_type) {
            case 'visits':
                $visit = DB::table('visits')->where('clinic_id', $clinicId)->where('id', $targetId)->first(['patient_id', 'occurred_at']);
                if ($visit !== null) {
                    $patientId = (string) $visit->patient_id;
                    $target['visit_occurred_at'] = self::instant((string) $visit->occurred_at);
                }
                break;
            case 'invoices':
                $invoice = DB::table('invoices')->where('clinic_id', $clinicId)->where('id', $targetId)->first(['patient_id', 'number']);
                if ($invoice !== null) {
                    $patientId = (string) $invoice->patient_id;
                    $target['invoice_number'] = $invoice->number === null ? null : (string) $invoice->number;
                }
                break;
            case 'payments':
                $payment = DB::table('payments')
                    ->join('invoices', static function ($join): void {
                        $join->on('invoices.clinic_id', '=', 'payments.clinic_id')->on('invoices.id', '=', 'payments.invoice_id');
                    })
                    ->where('payments.clinic_id', $clinicId)
                    ->where('payments.id', $targetId)
                    ->first(['payments.amount', 'payments.method', 'payments.effective_at', 'invoices.number', 'invoices.patient_id']);
                if ($payment !== null) {
                    $patientId = (string) $payment->patient_id;
                    $target['invoice_number'] = $payment->number === null ? null : (string) $payment->number;
                    $target['payment'] = ['amount' => (int) $payment->amount, 'method' => (string) $payment->method, 'effective_at' => self::instant((string) $payment->effective_at)];
                }
                break;
        }

        if ($patientId !== null) {
            $patient = DB::table('patient_records')->where('clinic_id', $clinicId)->where('id', $patientId)->first(['id', 'record_number', 'last_name', 'first_names']);
            if ($patient !== null) {
                $target['patient'] = [
                    'id' => (string) $patient->id,
                    'record_number' => (string) $patient->record_number,
                    'last_name' => (string) $patient->last_name,
                    'first_names' => $patient->first_names === null ? null : (string) $patient->first_names,
                ];
            }
        }

        return $target;
    }

    private function base(string $clinicId): Builder
    {
        return DB::table('approvals')
            ->where('clinic_id', $clinicId)
            ->where(static function (Builder $kinds): void {
                foreach (ApprovalKind::cases() as $kind) {
                    $kinds->orWhere(static function (Builder $pair) use ($kind): void {
                        $pair->where('action_code', $kind->actionCode())->where('target_type', $kind->targetType());
                    });
                }
            });
    }

    private static function instant(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
