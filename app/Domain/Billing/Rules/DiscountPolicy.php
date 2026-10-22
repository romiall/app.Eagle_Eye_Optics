<?php

namespace App\Domain\Billing\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;
use Brick\Math\BigInteger;

/**
 * Plafond de remise de ligne selon le droit de l'auteur (fichier 09 : « Modifier prix/remise : gérante oui ;
 * caissière sur approbation exacte uniquement » ; fichier 03 : « la gérante fixe taxe et remise »).
 *
 *   remise_maximale = arrondi_inférieur(brut_de_ligne × plafond_% / 100)
 *
 * Un compte qui détient l'AUTORITÉ DE PRIX (`catalog.manage`) peut remiser jusqu'au brut de la ligne (100 %, la
 * borne du CHECK ck_invoice_lines_03). Tout autre compte est limité par un plafond configurable
 * (`eeo.billing.discount_cap_percent`), à ZÉRO par défaut : sans approbation exacte (B06, non ouverte), il ne remise
 * pas. La remise est un montant TTC en FCFA, jamais une réduction masquée sur le prix : le prix unitaire d'une
 * ligne de catalogue reste celui du catalogue pour qui n'a pas l'autorité de prix.
 *
 * Fonction pure, en entiers exacts (Brick\Math) : aucun flottant. Elle n'est PAS un vecteur partagé avec le client
 * (le client affiche, le serveur décide) ; le brut et le net, eux, viennent de `LineAmounts` (vecteurs communs).
 */
final class DiscountPolicy
{
    public const FULL = '100.0000';

    /**
     * Plafond applicable à l'auteur. Une valeur de configuration illisible vaut ZÉRO (échec fermé).
     */
    public static function capFor(bool $priceAuthority, ?string $configuredCapPercent): FixedDecimal
    {
        if ($priceAuthority) {
            return Rate::of(self::FULL);
        }

        try {
            return Rate::parse($configuredCapPercent, 'discount_cap_percent') ?? Rate::of('0');
        } catch (RuleViolation) {
            return Rate::of('0');
        }
    }

    /**
     * Remise maximale admise, en FCFA, pour un brut de ligne donné (arrondi à l'inférieur).
     */
    public static function maxDiscount(int $gross, FixedDecimal $capPercent): int
    {
        Money::amount($gross, 'gross');

        // plafond % = units / 10^4 ; remise = brut × (units / 10^4) / 100 = brut × units / 10^6.
        return BigInteger::of($gross)->multipliedBy($capPercent->units)->quotient(1_000_000)->toInt();
    }

    public static function allows(int $gross, int $discount, FixedDecimal $capPercent): bool
    {
        return $discount <= self::maxDiscount($gross, $capPercent);
    }
}
