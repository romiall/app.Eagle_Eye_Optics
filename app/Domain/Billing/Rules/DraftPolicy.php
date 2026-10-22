<?php

namespace App\Domain\Billing\Rules;

use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Rate;

/**
 * Droits de l'auteur qui écrit les lignes d'un brouillon (fichier 09 : « modifier prix/remise : gérante oui,
 * caissière sur approbation exacte uniquement » ; « la permission spéciale d'encaissement examen permet de créer la
 * ligne de prestation fixe, émettre la pièce et prendre le règlement ; elle ne permet pas d'ajouter un article, de
 * changer un tarif »).
 *
 * Les droits s'appliquent à l'ÉCRITURE des lignes (création et modification du brouillon). À l'émission, les lignes
 * sont figées telles qu'une personne habilitée les a écrites : la caissière qui émet le brouillon remisé par la
 * gérante n'a pas besoin du droit de remise (`trusted()`).
 */
final readonly class DraftPolicy
{
    public function __construct(
        public bool $enforceRights,
        public bool $priceAuthority,
        public FixedDecimal $discountCapPercent,
        public ?string $examFeeItemId,
    ) {}

    /** Émission : aucune règle de droit, seulement le recalcul des montants et l'état du catalogue. */
    public static function trusted(): self
    {
        return new self(false, true, Rate::of(DiscountPolicy::FULL), null);
    }
}
