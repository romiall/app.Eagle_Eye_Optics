<?php

namespace App\Domain\Billing\Rules;

use App\Domain\Shared\ValueObjects\FixedDecimal;

/**
 * Ligne de brouillon validée et calculée : saisie de l'auteur (désignation, quantité exacte, prix TTC unitaire,
 * remise TTC) et valeurs reprises du catalogue au moment du calcul (référence, catégorie, unité, règle fiscale
 * applicable). Les montants viennent de `LineAmounts` (vecteurs communs PHP/TypeScript).
 */
final readonly class DraftLine
{
    public function __construct(
        public string $id,
        public int $position,
        public ?string $catalogItemId,
        public string $description,
        public FixedDecimal $quantity,
        public int $unitPriceGross,
        public int $discountGross,
        public ?string $prescriptionId,
        public ?string $referenceSnapshot,
        public string $categorySnapshot,
        public string $unitSnapshot,
        public string $taxCodeSnapshot,
        public ?FixedDecimal $taxRate,
        public LineAmounts $amounts,
        public ?string $taxRuleId,
        public bool $stocked,
        public bool $requiresExpiry,
    ) {}
}
