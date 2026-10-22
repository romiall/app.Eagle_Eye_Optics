<?php

namespace App\Domain\Billing\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Instant;

/**
 * Règles d'un encaissement qui ne dépendent d'aucune lecture en base (fichier 03, « Encaissement et reçu »).
 * L'encaissement est un fait CONSTATÉ par un opérateur : aucune vérification automatique auprès d'un opérateur
 * télécom ni d'une banque n'existe en L0. Le montant, lui, est contrôlé contre le reste dû par `LedgerBalance`.
 */
final class PaymentRules
{
    public const CASH = 'CASH';

    /** Modes du catalogue `payments.method`. */
    public const METHODS = ['CASH', 'MTN_MOMO', 'ORANGE_MONEY', 'BANK_TRANSFER', 'CHEQUE', 'OTHER'];

    /** Modes dont la référence est obligatoire par défaut (« mobile money/virement selon paramétrage »). */
    public const DEFAULT_REFERENCE_REQUIRED = ['MTN_MOMO', 'ORANGE_MONEY', 'BANK_TRANSFER'];

    public const DEPOSIT = 'DEPOSIT';

    public const BALANCE_SETTLEMENT = 'BALANCE_SETTLEMENT';

    /**
     * @param  list<string>  $requiredMethods  modes qui exigent une référence (paramétrage du cabinet)
     */
    public static function referenceRequired(string $method, array $requiredMethods): bool
    {
        return in_array($method, $requiredMethods, true);
    }

    /**
     * Nature du reçu, CALCULÉE et jamais saisie (fichier 11 : « Acompte » ou « Règlement du solde » selon calcul) :
     * le règlement du solde est celui après lequel il ne reste rien à payer ; tout autre encaissement est un acompte.
     */
    public static function receiptKind(int $balanceAfter): string
    {
        return $balanceAfter === 0 ? self::BALANCE_SETTLEMENT : self::DEPOSIT;
    }

    /**
     * Date effective d'un encaissement : jamais dans le futur au-delà de la tolérance d'horloge (voir
     * `eeo.offline.max_clock_skew_seconds`). Une date passée est admise (saisie différée d'une pièce papier).
     */
    public static function assertNotInFuture(Instant $effective, Instant $now, int $skewSeconds, string $field = 'effective_at'): void
    {
        if ($effective->epochMilliseconds > $now->epochMilliseconds + $skewSeconds * 1000) {
            throw RuleViolation::invalid($field, 'payment.effective_in_future', 'La date de l\'encaissement ne peut pas être dans le futur.');
        }
    }

    /**
     * Un encaissement daté d'un JOUR CIVIL antérieur à celui de la saisie (fuseau du cabinet) est rétroactif : son
     * motif est obligatoire et reste tracé. Seuil retenu = le jour civil, faute de seuil donné par les sources (à
     * confirmer avec la gérante) ; aucune tolérance n'est inventée.
     */
    public static function isRetroactive(Instant $effective, Instant $now, string $timezone): bool
    {
        return $effective->civilDate($timezone)->compare($now->civilDate($timezone)) < 0;
    }
}
