<?php

namespace App\Domain\Billing\Rules;

use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;
use Brick\Math\BigInteger;

/**
 * Taxe comprise dans un montant TTC (convention A08 : prix catalogue TTC). Le taux vient d'une règle fiscale
 * validée par la gérante ; le code n'en fournit aucun.
 *
 * Ventilation par ligne, arrondie au FCFA, demi supérieur : taxe = arrondi(net × t / (100 + t)). C'est une
 * convention technique proposée tant que le régime réel n'est pas connu (Q03) ; une autre méthode exigée par le
 * comptable prendra une nouvelle version de règles.
 */
final class IncludedTax
{
    public static function of(int $netGross, FixedDecimal $ratePercent): int
    {
        Money::amount($netGross, 'line_total_gross');
        $rate = Rate::of($ratePercent->canonical());
        // t/100 = unités / 10^6 (échelle 4) : taxe = net × unités / (10^6 + unités), en fraction exacte.
        $denominator = BigInteger::of(1_000_000 + $rate->units);
        $numerator = BigInteger::of($netGross)->multipliedBy($rate->units);

        return $numerator->multipliedBy(2)->plus($denominator)->quotient($denominator->multipliedBy(2))->toInt();
    }
}
