<?php

namespace App\Domain\Billing\Rules;

/**
 * État de règlement d'une facture, DÉRIVÉ de son solde projeté (fichier 03 : « ne pas mettre un statut PAID
 * inexistant » ; contrat 23 : « l'état payé/partiellement payé/dette/crédit est dérivé du solde »). Aucun statut de
 * règlement n'est jamais stocké : `invoices.status` ne vaut que `DRAFT`, `ISSUED` ou `ABANDONED`.
 *
 *   NOT_ISSUED      brouillon ou abandonnée : aucune créance
 *   UNPAID          émise, reste dû > 0, rien encaissé net
 *   PARTIALLY_PAID  émise, reste dû > 0, une part encaissée net (acompte)
 *   SETTLED         émise, solde nul (reste dû = 0, crédit = 0)
 *   IN_CREDIT       émise, crédit à rembourser > 0 (jamais une dette négative cachée)
 *
 * « Soldé » ne dit PAS « payé par le client » : une facture entièrement créditée par avoirs est aussi à solde nul. Un
 * écran qui écrit « payé » le justifie par `paid_net`, jamais par ce seul état.
 */
final class Settlement
{
    public const NOT_ISSUED = 'NOT_ISSUED';

    public const UNPAID = 'UNPAID';

    public const PARTIALLY_PAID = 'PARTIALLY_PAID';

    public const SETTLED = 'SETTLED';

    public const IN_CREDIT = 'IN_CREDIT';

    public static function of(string $invoiceStatus, ?LedgerBalance $balance): string
    {
        if ($invoiceStatus !== 'ISSUED' || $balance === null) {
            return self::NOT_ISSUED;
        }

        return match (true) {
            $balance->signedBalance() < 0 => self::IN_CREDIT,
            $balance->signedBalance() === 0 => self::SETTLED,
            self::paidNet($balance) > 0 => self::PARTIALLY_PAID,
            default => self::UNPAID,
        };
    }

    /** Encaissé net : paiements confirmés moins contre-écritures. */
    public static function paidNet(LedgerBalance $balance): int
    {
        return $balance->paymentsConfirmed - $balance->paymentReversals;
    }

    /**
     * Objet `InvoiceBalance` du contrat (`schemas/resources/billing.json`). Un brouillon n'a aucune créance : son
     * total est un projet, son reste dû vaut 0.
     *
     * @return array{total_gross: int, paid_net: int, credited: int, refunded: int, balance_due: int, credit_available: int, settlement: string}
     */
    public static function balanceArray(string $invoiceStatus, int $totalGross, ?LedgerBalance $balance): array
    {
        if ($invoiceStatus !== 'ISSUED' || $balance === null) {
            return [
                'total_gross' => $totalGross,
                'paid_net' => 0,
                'credited' => 0,
                'refunded' => 0,
                'balance_due' => 0,
                'credit_available' => 0,
                'settlement' => self::NOT_ISSUED,
            ];
        }

        return [
            'total_gross' => $balance->totalGross,
            'paid_net' => self::paidNet($balance),
            'credited' => $balance->creditsIssued,
            'refunded' => $balance->refundsConfirmed,
            'balance_due' => $balance->signedBalance(),
            'credit_available' => $balance->refundableCredit(),
            'settlement' => self::of($invoiceStatus, $balance),
        ];
    }
}
