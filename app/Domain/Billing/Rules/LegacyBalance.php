<?php

namespace App\Domain\Billing\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Money;

/**
 * Reprise d'une créance papier (AT26) : la pièce `LEGACY_BALANCE` porte le seul reste dû à la bascule
 * (`legacy_opening_balance = total_gross`, ck_invoices_03). Elle n'est ni une vente ni un encaissement de la
 * période de reprise : la vente et l'acompte d'origine appartiennent au carnet papier.
 */
final class LegacyBalance
{
    public static function openingBalance(int $originalTotal, int $alreadyPaid): int
    {
        Money::positive($originalTotal, 'original_total');
        Money::amount($alreadyPaid, 'already_paid');
        if ($alreadyPaid > $originalTotal) {
            throw RuleViolation::invalid('already_paid', 'legacy.paid_exceeds_total', 'Le montant déjà payé dépasse le total de la pièce papier.');
        }
        if ($alreadyPaid === $originalTotal) {
            throw RuleViolation::invalid('already_paid', 'legacy.nothing_due', 'Cette pièce papier est soldée : aucune créance à reprendre.');
        }

        return $originalTotal - $alreadyPaid;
    }

    /**
     * Chiffre d'affaires porté par une facture émise : son total si elle est native, zéro pour une reprise.
     */
    public static function revenue(string $origin, int $totalGross): int
    {
        return match ($origin) {
            'NATIVE' => Money::amount($totalGross, 'total_gross'),
            'LEGACY_BALANCE' => 0,
            default => throw RuleViolation::invalid('origin', 'invoice.origin_unknown', 'Origine de facture inconnue.'),
        };
    }
}
