<?php

namespace App\Domain\Access\Approvals;

/**
 * Opérations qu'une approbation EXACTE peut autoriser (B06 ; fichier 03 : « Approbation : demandeur, opération cible,
 * contenu exact ou empreinte, motif, montant, décision gérante, date et expiration » ; fichier 09 : « Modifier
 * prix/remise : caissière sur approbation exacte uniquement », « Avoir/remboursement : exécution après approbation
 * explicite », « Annuler/corriger facture ou paiement : demande seulement »).
 *
 * Chaque nature fixe :
 *  - `actionCode()` : la permission du référentiel que l'approbation remplace pour CETTE opération. La gérante qui
 *    décide doit la détenir elle-même : les permissions prix, examen, remise et correction restent distinctes
 *    (`approval.decide` seule ne suffit pas) ;
 *  - `targetType()` : la table de la cible (`approvals.target_type`) ;
 *  - le contenu exact lié par l'empreinte (`ApprovalSubject`).
 *
 * Aucune nature « générale » : une approbation n'autorise jamais qu'une opération précise, une fois. Le stock initial
 * reste une exécution directe de la gérante (`DirectApproval`) : le fichier 09 ne prévoit pas de demande pour lui.
 */
enum ApprovalKind: string
{
    /** Dispense des frais d'examen d'UNE visite (fichier 02 : « dérogation gérante enregistrée avec motif »). */
    case ExamFeeWaiver = 'EXAM_FEE_WAIVER';

    /** Prix hors catalogue, ligne libre ou remise au-delà du plafond d'un brouillon de facture (AT07). */
    case DraftLineRights = 'DRAFT_LINE_RIGHTS';

    /** Contre-écriture d'un paiement enregistré à tort (AT13). */
    case PaymentReversal = 'PAYMENT_REVERSAL';

    /** Avoir lié aux lignes d'une facture émise (AT14). */
    case CreditNote = 'CREDIT_NOTE';

    /** Remboursement réel d'un crédit (AT14). */
    case Refund = 'REFUND';

    public function actionCode(): string
    {
        return match ($this) {
            self::ExamFeeWaiver => 'exam_fee.collect',
            self::DraftLineRights => 'catalog.manage',
            self::PaymentReversal => 'payment.reverse',
            self::CreditNote => 'credit_note.issue',
            self::Refund => 'refund.execute',
        };
    }

    public function targetType(): string
    {
        return match ($this) {
            self::ExamFeeWaiver => 'visits',
            self::PaymentReversal => 'payments',
            self::DraftLineRights, self::CreditNote, self::Refund => 'invoices',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ExamFeeWaiver => 'Dispense des frais d\'examen',
            self::DraftLineRights => 'Prix ou remise d\'un brouillon de facture',
            self::PaymentReversal => 'Contre-écriture d\'un paiement',
            self::CreditNote => 'Avoir sur une facture émise',
            self::Refund => 'Remboursement d\'un crédit',
        };
    }

    /**
     * Nature d'une approbation enregistrée, d'après son code d'action et sa cible ; null pour une autorisation qui n'est
     * pas une demande de ce registre (stock initial exécuté directement par la gérante, par exemple).
     */
    public static function fromStored(string $actionCode, string $targetType): ?self
    {
        foreach (self::cases() as $kind) {
            if ($kind->actionCode() === $actionCode && $kind->targetType() === $targetType) {
                return $kind;
            }
        }

        return null;
    }
}
