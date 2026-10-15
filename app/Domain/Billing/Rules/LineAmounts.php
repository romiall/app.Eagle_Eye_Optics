<?php

namespace App\Domain\Billing\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Quantity;
use Brick\Math\BigInteger;

/**
 * Montants d'une ligne de facture (fichier 03, CHECK de `invoice_lines`) :
 *
 *   brut  = arrondi_FCFA(quantité × prix_unitaire_TTC), demi supérieur  (ck_invoice_lines_04 : ROUND(q × p, 0))
 *   net   = brut - remise, avec 0 ≤ remise ≤ brut                        (ck_invoice_lines_03)
 *   taxe  = part de taxe comprise dans le net, si un taux validé existe, sinon null (non ventilée)
 *
 * Le produit est calculé en entier exact (millièmes de FCFA), jamais en flottant.
 */
final readonly class LineAmounts
{
    private function __construct(
        public int $gross,
        public int $discount,
        public int $net,
        public ?int $taxAmount,
    ) {}

    public static function compute(FixedDecimal $quantity, int $unitPrice, int $discount, ?FixedDecimal $taxRatePercent, bool $integerOnly): self
    {
        Quantity::forLine($quantity, $integerOnly);
        Money::amount($unitPrice, 'unit_price_gross');
        Money::amount($discount, 'discount_gross');

        $thousandths = BigInteger::of($quantity->units)->multipliedBy($unitPrice);
        $gross = $thousandths->plus(500)->quotient(1000);
        if ($gross->isGreaterThan(Money::MAX)) {
            throw RuleViolation::invalid('unit_price_gross', 'line.amount_out_of_range', 'Le montant de la ligne dépasse 1 000 milliards de FCFA.');
        }
        $grossAmount = $gross->toInt();
        if ($discount > $grossAmount) {
            throw RuleViolation::invalid('discount_gross', 'line.discount_exceeds_gross', 'La remise ne peut pas dépasser le montant brut de la ligne.', ['gross' => $grossAmount]);
        }
        $net = $grossAmount - $discount;

        return new self($grossAmount, $discount, $net, $taxRatePercent === null ? null : IncludedTax::of($net, $taxRatePercent));
    }

    /**
     * Total d'une facture : somme des nets de ligne (`invoices.total_gross`), bornée comme la colonne.
     *
     * @param  list<self>  $lines
     */
    public static function invoiceTotal(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += $line->net;
            if ($total > Money::MAX) {
                throw RuleViolation::invalid('lines', 'invoice.total_out_of_range', 'Le total de la facture dépasse 1 000 milliards de FCFA.');
            }
        }

        return $total;
    }
}
