<?php

namespace App\Domain\Billing;

use App\Application\Locking\TransactionLocks;
use App\Domain\Access\Approvals\ApprovalSubject;
use App\Domain\Access\Approvals\ExactApprovals;
use App\Domain\Billing\Rules\LedgerBalance;
use App\Domain\Billing\Rules\Settlement;
use App\Domain\Clinical\ExamFeeControl;
use App\Domain\Clinical\ExamFeeDecision;
use App\Domain\Patients\Rules\VisitStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Contrôle RÉEL du paiement de l'examen (B04, B06, P03, AT05, AT06), implémentation de `ExamFeeControl` branchée par
 * `eeo.billing.exam_fee_control = billed` (variable `EEO_EXAM_FEE_CONTROL`). INACTIVE par défaut : la valeur
 * `off` laisse `UncontrolledExamFee` répondre « non contrôlé », sans rien changer au premier jalon clinique.
 *
 * Le démarrage de l'examen d'une visite est admis si, et seulement si :
 *  - la facture de frais d'examen de CETTE visite (même visite, même patient, émise, contenant l'article « frais
 *    d'examen » configuré) est RÉGLÉE : reste dû nul et montant non crédité entièrement couvert par l'encaissé net
 *    conservé (paiements confirmés − contre-écritures − remboursements). Le paiement d'une monture sur une autre
 *    facture, ou d'un autre patient, ou d'une autre visite, ne prouve jamais le règlement de l'examen ; une facture de
 *    frais seulement en partie payée, ou créditée puis remboursée, non plus ;
 *  - ou une DISPENSE exacte de la gérante existe : approbation `APPROVED` liée à cette visite et à ce patient
 *    (`ApprovalSubject::examFeeWaiver`, même empreinte que `ExamFeeWaiver::payloadHash`), non expirée, non consommée,
 *    décidée ; elle est CONSOMMÉE dans la transaction du démarrage. Désignée (`exam_waiver_approval_id`) ou, à défaut,
 *    retrouvée par son contenu exact (B06 : la demande et la décision passent par `POST /approvals`).
 *
 * Sinon : refus 422 `EXAM_PAYMENT_REQUIRED` (aucune consultation, la transaction est annulée). Une dispense désignée
 * invalide, expirée ou déjà utilisée : 422 `APPROVAL_REQUIRED_OR_EXPIRED`. La preuve retenue (facture réglée ou
 * dispense) est consignée sur la visite (`exam_invoice_id`, `exam_waiver_approval_id`) par ce contrôle, dans la
 * transaction de l'action de démarrage et sous son verrou de visite ; l'audit du démarrage porte `PAID` ou `WAIVED`.
 *
 * Une visite sans examen (achat, retrait) n'est pas concernée (`NOT_APPLICABLE`). Aucune règle de gratuité n'est
 * inventée : un contrôle de suivi se règle comme un examen tant que la cliente n'a pas dit le contraire.
 */
final class BilledExamFeeControl implements ExamFeeControl
{
    public const PAID = 'PAID';

    public const WAIVED = 'WAIVED';

    public const UNPAID = 'UNPAID';

    /** Dispense demandée, en attente de la décision de la gérante (file du jour seulement). */
    public const WAIVER_REQUESTED = 'WAIVER_REQUESTED';

    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    public function __construct(private readonly ExactApprovals $approvals) {}

    /**
     * Vrai si le contrôle réel est branché (`eeo.billing.exam_fee_control` = `billed` exactement ; toute autre valeur
     * laisse le contrôle inactif : échec fermé).
     */
    public static function isActive(): bool
    {
        return config('eeo.billing.exam_fee_control') === 'billed';
    }

    public function decide(stdClass $visit, array $charge, ?TransactionLocks $locks = null): ExamFeeDecision
    {
        if (! in_array((string) $visit->reason, VisitStatus::EXAM_REASONS, true)) {
            return new ExamFeeDecision(self::NOT_APPLICABLE);
        }

        $clinicId = (string) $visit->clinic_id;
        $visitId = (string) $visit->id;
        $patientId = (string) $visit->patient_id;
        $proof = is_string($charge['exam_invoice_id'] ?? null) ? $charge['exam_invoice_id'] : null;
        $waiverId = is_string($charge['exam_waiver_approval_id'] ?? null) ? $charge['exam_waiver_approval_id'] : null;
        $now = CarbonImmutable::now('UTC');

        $paid = $this->paidInvoices($clinicId, $visitId, $patientId);
        if ($proof !== null && ! in_array($proof, $paid, true)) {
            throw BillingErrors::examPaymentRequired(
                ['exam_invoice_id' => ['Cette facture n\'est pas la facture de frais d\'examen réglée de cette visite.']],
                'La preuve de paiement fournie ne concerne pas les frais d\'examen réglés de cette visite.',
            );
        }
        if ($paid !== []) {
            $invoiceId = $proof ?? $paid[0];
            if ((string) ($visit->exam_invoice_id ?? '') !== $invoiceId) {
                DB::table('visits')->where('clinic_id', $clinicId)->where('id', $visitId)->update(['exam_invoice_id' => $invoiceId]);
            }

            return new ExamFeeDecision(self::PAID);
        }

        $subject = ApprovalSubject::examFeeWaiver($visitId, $patientId);
        if ($waiverId !== null) {
            $this->consumeWaiver($locks, $clinicId, $now, $waiverId, $subject);
            DB::table('visits')->where('clinic_id', $clinicId)->where('id', $visitId)->update(['exam_waiver_approval_id' => $waiverId]);

            return new ExamFeeDecision(self::WAIVED);
        }

        // Aucune dispense désignée : celle accordée pour CETTE visite et CE patient, s'il en existe une (B06).
        if ($locks !== null) {
            $found = $this->approvals->consumeMatching($locks, $clinicId, $now, $subject);
            if ($found !== null) {
                DB::table('visits')->where('clinic_id', $clinicId)->where('id', $visitId)->update(['exam_waiver_approval_id' => $found]);

                return new ExamFeeDecision(self::WAIVED);
            }
        }

        $reason = ExamFeeScope::configuredReference() === null
            ? ' (l\'article « frais d\'examen » n\'est pas configuré : EEO_EXAM_FEE_ITEM_REFERENCE)'
            : '';

        throw BillingErrors::examPaymentRequired(
            ['exam_invoice_id' => ['Les frais d\'examen de cette visite ne sont pas réglés.']],
            'Les frais d\'examen de cette visite ne sont pas réglés : encaissez leur facture, ou obtenez la dispense de la gérante'.$reason.'.',
        );
    }

    /**
     * État du paiement de l'examen d'une visite, en LECTURE (aucune écriture) : pour la file du jour et l'écran, une
     * fois le contrôle actif. `UNPAID` n'est jamais « gratuit » ; sans contrôle actif, ce statut n'existe pas
     * (« non contrôlé », voir `VisitStatus::examFeeControl`).
     */
    public function statusFor(stdClass $visit): string
    {
        if (! in_array((string) $visit->reason, VisitStatus::EXAM_REASONS, true)) {
            return self::NOT_APPLICABLE;
        }
        if (($visit->exam_waiver_approval_id ?? null) !== null) {
            return self::WAIVED;
        }

        return $this->paidInvoices((string) $visit->clinic_id, (string) $visit->id, (string) $visit->patient_id) !== [] ? self::PAID : self::UNPAID;
    }

    /**
     * Marqueur de la file du jour pour une visite (P03), en LECTURE : statut du paiement de l'examen, numéro du dernier
     * reçu de la facture de frais réglée, et statut de visite à AFFICHER.
     *
     * Les statuts `AWAITING_EXAM_FEE` et `READY_FOR_EXAM` sont écrits aux événements (arrivée, encaissement,
     * correction, décision de la gérante : `ExamFeeVisits::refresh`) ; seule l'échéance d'une dispense accordée passe
     * sans événement. Le statut affiché est donc recalculé ici pour ces deux valeurs : une dispense expirée ne laisse
     * jamais « Prêt pour l'examen » à l'écran.
     *
     * @return array{control: string, receipt_number: ?string, status: string}
     */
    public function queueMarker(stdClass $visit, CarbonImmutable $now): array
    {
        $stored = (string) $visit->status;
        if (! in_array((string) $visit->reason, VisitStatus::EXAM_REASONS, true)) {
            return ['control' => self::NOT_APPLICABLE, 'receipt_number' => null, 'status' => $stored];
        }

        $clinicId = (string) $visit->clinic_id;
        $receipt = null;
        if (($visit->exam_waiver_approval_id ?? null) !== null) {
            $control = self::WAIVED;
        } else {
            $paid = $this->paidInvoices($clinicId, (string) $visit->id, (string) $visit->patient_id);
            if ($paid !== []) {
                $control = self::PAID;
                $invoiceId = in_array((string) ($visit->exam_invoice_id ?? ''), $paid, true) ? (string) $visit->exam_invoice_id : $paid[0];
                $number = DB::table('receipts')->where('clinic_id', $clinicId)->where('invoice_id', $invoiceId)
                    ->orderByDesc('issued_at')->orderByDesc('id')->value('number');
                $receipt = is_string($number) ? $number : null;
            } else {
                $subject = ApprovalSubject::examFeeWaiver((string) $visit->id, (string) $visit->patient_id);
                $control = match (true) {
                    ExactApprovals::findValid($clinicId, $subject, $now) !== null => self::WAIVED,
                    ExactApprovals::findPending($clinicId, $subject, $now) !== null => self::WAIVER_REQUESTED,
                    default => self::UNPAID,
                };
            }
        }

        $status = $stored;
        if (in_array($stored, [VisitStatus::AWAITING_EXAM_FEE, VisitStatus::READY_FOR_EXAM], true)) {
            $status = in_array($control, [self::PAID, self::WAIVED], true) ? VisitStatus::READY_FOR_EXAM : VisitStatus::AWAITING_EXAM_FEE;
        }

        return ['control' => $control, 'receipt_number' => $receipt, 'status' => $status];
    }

    /**
     * Factures de frais d'examen RÉGLÉES de la visite, de la plus ancienne à la plus récente.
     *
     * @return list<string>
     */
    public function paidInvoices(string $clinicId, string $visitId, string $patientId): array
    {
        $itemId = ExamFeeScope::itemId($clinicId);
        if ($itemId === null) {
            return [];
        }

        $candidates = DB::table('invoices')
            ->where('clinic_id', $clinicId)
            ->where('visit_id', $visitId)
            ->where('patient_id', $patientId)
            ->where('status', 'ISSUED')
            ->whereExists(static function ($query) use ($itemId): void {
                $query->select(DB::raw('1'))
                    ->from('invoice_lines')
                    ->whereColumn('invoice_lines.clinic_id', 'invoices.clinic_id')
                    ->whereColumn('invoice_lines.invoice_id', 'invoices.id')
                    ->where('invoice_lines.catalog_item_id', $itemId);
            })
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get(['id', 'total_gross']);

        $paid = [];
        foreach ($candidates as $candidate) {
            if (self::settledByMoney(InvoiceLedger::load($clinicId, (string) $candidate->id, (int) $candidate->total_gross))) {
                $paid[] = (string) $candidate->id;
            }
        }

        return $paid;
    }

    /**
     * Règle pure du « réglé » d'une facture de frais : reste dû nul, une part non créditée (la facture n'est pas
     * annulée en entier par avoirs) et l'encaissé net CONSERVÉ (paiements − contre-écritures − remboursements) couvre
     * toute cette part. Une facture entièrement créditée puis remboursée, ou payée puis contre-passée, ne prouve rien.
     */
    public static function settledByMoney(LedgerBalance $ledger): bool
    {
        $invoiced = $ledger->totalGross - $ledger->creditsIssued;
        $kept = Settlement::paidNet($ledger) - $ledger->refundsConfirmed;

        return $ledger->amountDue() === 0 && $invoiced > 0 && $kept >= $invoiced;
    }

    private function consumeWaiver(?TransactionLocks $locks, string $clinicId, CarbonImmutable $now, string $approvalId, ApprovalSubject $subject): void
    {
        $message = 'La dispense de la gérante n\'est pas valable pour cette visite : demandez-en une nouvelle.';
        $field = 'exam_waiver_approval_id';

        if ($locks !== null) {
            $this->approvals->consume($locks, $clinicId, $now, $approvalId, $subject, $field, $message);

            return;
        }

        // Hors chemin central (appel direct d'un test) : même règle, verrou de ligne pris ici.
        $approval = DB::table('approvals')->where('clinic_id', $clinicId)->where('id', $approvalId)->lockForUpdate()->first();
        if ($approval === null || ! ExactApprovals::authorizes($approval, $subject, $now)) {
            throw BillingErrors::approvalRequired([$field => ['Dispense introuvable, expirée, déjà utilisée ou ne concernant pas cette visite.']], $message);
        }
        $stored = $now->format('Y-m-d H:i:s.u');
        DB::table('approvals')->where('clinic_id', $clinicId)->where('id', $approvalId)->update(['status' => ExactApprovals::CONSUMED, 'consumed_at' => $stored, 'updated_at' => $stored]);
    }
}
