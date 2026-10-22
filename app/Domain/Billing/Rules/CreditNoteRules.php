<?php

namespace App\Domain\Billing\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Money;
use Brick\Math\BigInteger;

/**
 * Règles pures d'un avoir lié aux lignes d'origine (B07, AT14 ; fichier 03 : « Une facture émise est corrigée par avoir
 * partiel ou total lié aux lignes d'origine » ; fichier 21 : « Cumul valeur/quantité ≤ ligne originale »). Aucune
 * lecture en base, aucun flottant : entiers FCFA et décimaux exacts.
 *
 * Un avoir réduit la CRÉANCE ; il ne touche ni au stock (un retour physique est une autre opération), ni à la facture,
 * ni à ses reçus, qui restent tels qu'émis.
 */
final class CreditNoteRules
{
    /**
     * Plafonds d'une ligne d'avoir sur sa ligne d'origine : montant cumulé ≤ total TTC de la ligne ; quantité cumulée
     * (quand elle est indiquée) ≤ quantité facturée. Refus 422 sur le champ, avec ce qui reste créditable.
     */
    public static function assertLine(int $lineTotal, FixedDecimal $lineQuantity, int $alreadyCredited, FixedDecimal $alreadyQuantity, int $amount, ?FixedDecimal $quantity): void
    {
        Money::amount($lineTotal, 'line_total_gross');
        Money::amount($alreadyCredited, 'already_credited');
        Money::positive($amount, 'amount_gross');

        $remaining = max(0, $lineTotal - $alreadyCredited);
        if ($amount > $remaining) {
            throw RuleViolation::invalid('amount_gross', 'credit_note.exceeds_line', 'L\'avoir dépasse le montant de la ligne d\'origine non encore crédité.', ['creditable' => $remaining]);
        }
        if ($quantity !== null) {
            if ($quantity->units <= 0) {
                throw RuleViolation::invalid('quantity', 'quantity.positive_required', 'La quantité doit être strictement positive.');
            }
            if ($alreadyQuantity->plus($quantity)->compare($lineQuantity) > 0) {
                throw RuleViolation::invalid('quantity', 'credit_note.exceeds_quantity', 'L\'avoir dépasse la quantité facturée non encore créditée.', ['creditable_quantity' => $lineQuantity->minus($alreadyQuantity)->canonical()]);
            }
        }
    }

    /**
     * Taxe comprise dans la part créditée d'une ligne (régime figé de la facture : aucune taxe inventée). Ligne sans
     * taxe : null. Sinon la part proportionnelle de la taxe de la ligne, arrondie au FCFA (demi supérieur), jamais au-delà
     * de la taxe encore non créditée ni du montant crédité ; la part qui solde la ligne reçoit exactement le reste, pour
     * que les avoirs successifs d'une ligne ne créditent jamais plus de taxe que la ligne n'en portait.
     */
    public static function includedTax(int $amount, int $lineTotal, ?int $lineTax, int $alreadyCredited, int $alreadyTax): ?int
    {
        if ($lineTax === null) {
            return null;
        }
        Money::positive($amount, 'amount_gross');
        $remainingTax = max(0, $lineTax - $alreadyTax);
        if ($lineTotal <= 0 || $alreadyCredited + $amount >= $lineTotal) {
            return min($remainingTax, $amount);
        }

        // arrondi(taxe × part / total), demi supérieur, en fraction exacte.
        $numerator = BigInteger::of($lineTax)->multipliedBy($amount);
        $denominator = BigInteger::of($lineTotal);
        $share = $numerator->multipliedBy(2)->plus($denominator)->quotient($denominator->multipliedBy(2))->toInt();

        return min($share, $remainingTax, $amount);
    }

    /**
     * Le montant de l'avoir est la somme exacte de ses lignes.
     *
     * @param  list<int>  $lineAmounts
     */
    public static function assertTotal(int $amountGross, array $lineAmounts): void
    {
        $sum = 0;
        foreach ($lineAmounts as $value) {
            $sum += Money::positive($value, 'amount_gross');
        }
        if ($sum !== $amountGross) {
            throw RuleViolation::invalid('amount_gross', 'credit_note.total_mismatch', 'Le montant de l\'avoir doit être la somme de ses lignes.', ['expected_total' => $sum]);
        }
    }
}
