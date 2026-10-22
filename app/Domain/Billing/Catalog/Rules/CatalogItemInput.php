<?php

namespace App\Domain\Billing\Catalog\Rules;

use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Quantity;
use App\Domain\Shared\ValueObjects\Text;

/**
 * Validation d'un article du catalogue (B01, EE020, EE027) : colonnes de `catalog_items` calculées à partir de la
 * charge de `POST /catalog/items` (`CatalogItemRequest`), toutes les erreurs rendues ensemble. Aucun prix réel
 * n'est connu du code : les prix viennent de la gérante (Q04) et le dépôt ne contient que des données fictives.
 *
 * Règles du schéma de base, vérifiées ici pour répondre 422 plutôt qu'une erreur SQL :
 *  - un collyre exige une date d'expiration (`requires_expiry`), et seul un article géré en stock peut en exiger une ;
 *  - une prestation n'a aucun mouvement de stock (mode « sans stock ») ;
 *  - une unité qui ne se divise pas (paire, unité) impose une quantité entière : une paire n'est jamais convertie
 *    automatiquement en deux unités ;
 *  - seuil de stock bas et délai d'alerte de péremption n'ont de sens que pour un article stocké, respectivement
 *    périssable.
 */
final class CatalogItemInput
{
    public const STOCK_MODES = ['NONE', 'STOCKED', 'MADE_TO_ORDER'];

    /** Caractères de contrôle interdits dans une référence, un nom, une marque ou un modèle. */
    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/u';

    /**
     * @param  array<string, mixed>  $payload  charge sans `expected_version`
     * @param  array<string, bool>  $units  code d'unité → `integer_only`
     * @param  list<string>  $categories  codes de catégorie
     * @param  list<string>  $taxRuleIds  règles fiscales du cabinet
     * @return array<string, mixed>|null les colonnes (sans identifiant ni version), null si des erreurs ont été relevées
     */
    public static function columns(array $payload, array $units, array $categories, array $taxRuleIds, FieldErrors $errors): ?array
    {
        $before = $errors->all();

        $reference = self::line($payload['reference'] ?? null, 64, 'reference', true, $errors);
        $name = self::line($payload['name'] ?? null, 180, 'name', true, $errors);
        $brand = self::line($payload['brand'] ?? null, 120, 'brand', false, $errors);
        $model = self::line($payload['model'] ?? null, 120, 'model', false, $errors);

        $category = is_string($payload['category_code'] ?? null) ? $payload['category_code'] : '';
        if (! in_array($category, $categories, true)) {
            $errors->add('category_code', 'Catégorie inconnue du référentiel.');
        }
        $unit = is_string($payload['unit_code'] ?? null) ? $payload['unit_code'] : '';
        if (! array_key_exists($unit, $units)) {
            $errors->add('unit_code', 'Unité inconnue du référentiel.');
        }
        $stockMode = is_string($payload['stock_mode'] ?? null) ? $payload['stock_mode'] : '';
        if (! in_array($stockMode, self::STOCK_MODES, true)) {
            $errors->add('stock_mode', 'Mode de stock inconnu : sans stock, stocké ou sur mesure.');
        }

        $integerOnly = ($payload['quantity_integer_only'] ?? null) === true;
        $requiresExpiry = ($payload['requires_expiry'] ?? null) === true;

        $salePrice = self::money($payload['sale_price'] ?? null, 'sale_price', $errors);
        $purchaseCost = null;
        if (($payload['purchase_cost'] ?? null) !== null) {
            $purchaseCost = self::money($payload['purchase_cost'], 'purchase_cost', $errors);
        }

        $taxRuleId = $payload['tax_rule_id'] ?? null;
        if ($taxRuleId !== null && (! is_string($taxRuleId) || ! in_array($taxRuleId, $taxRuleIds, true))) {
            $errors->add('tax_rule_id', 'Règle fiscale introuvable dans ce cabinet.');
        }

        $threshold = null;
        if (($payload['low_stock_threshold'] ?? null) !== null) {
            try {
                $threshold = Quantity::parse((string) $payload['low_stock_threshold'], 'low_stock_threshold')?->canonical();
            } catch (RuleViolation $violation) {
                $errors->addViolation('', $violation);
            }
        }
        $alertDays = $payload['expiry_alert_days'] ?? null;
        if ($alertDays !== null && ! is_int($alertDays)) {
            $errors->add('expiry_alert_days', 'Le délai d\'alerte est un nombre entier de jours.');
        }

        // Règles croisées (CHECK ck_catalog_items_01 et _02, et cohérence métier).
        if ($category === 'COLLYRE' && ! $requiresExpiry) {
            $errors->add('requires_expiry', 'Un collyre exige un numéro de lot et une date d\'expiration.');
        }
        if ($requiresExpiry && $stockMode !== 'STOCKED') {
            $errors->add('requires_expiry', 'Seul un article géré en stock peut avoir une date d\'expiration.');
        }
        if ($category === 'SERVICE' && in_array($stockMode, ['STOCKED', 'MADE_TO_ORDER'], true)) {
            $errors->add('stock_mode', 'Une prestation n\'a aucun mouvement de stock.');
        }
        if (($units[$unit] ?? false) === true && ! $integerOnly) {
            $errors->add('quantity_integer_only', 'Cette unité ne se divise pas : la quantité doit être entière.');
        }
        if ($threshold !== null && $stockMode !== 'STOCKED') {
            $errors->add('low_stock_threshold', 'Un seuil de stock bas n\'a de sens que pour un article géré en stock.');
        }
        if ($alertDays !== null && ! $requiresExpiry) {
            $errors->add('expiry_alert_days', 'Un délai d\'alerte de péremption n\'a de sens que pour un article périssable.');
        }

        if ($errors->all() !== $before || $reference === null || $name === null || $salePrice === null) {
            return null;
        }

        return [
            'reference' => $reference,
            'category_code' => $category,
            'name' => $name,
            'brand' => $brand,
            'model' => $model,
            'unit_code' => $unit,
            'quantity_integer_only' => $integerOnly,
            'sale_price' => $salePrice,
            'purchase_cost' => $purchaseCost,
            'tax_rule_id' => is_string($taxRuleId) ? $taxRuleId : null,
            'stock_mode' => $stockMode,
            'requires_expiry' => $requiresExpiry,
            'low_stock_threshold' => $threshold,
            'expiry_alert_days' => is_int($alertDays) ? $alertDays : null,
            'active' => ($payload['active'] ?? null) === true,
            'public_visibility' => ($payload['public_visibility'] ?? null) === true,
        ];
    }

    private static function line(mixed $value, int $maxLength, string $field, bool $required, FieldErrors $errors): ?string
    {
        if ($value === null) {
            if ($required) {
                $errors->add($field, 'Ce champ est obligatoire.');
            }

            return null;
        }
        if (! is_string($value)) {
            $errors->add($field, 'Texte attendu.');

            return null;
        }
        try {
            $text = Text::optional($value, $maxLength, $field);
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);

            return null;
        }
        if ($text === null) {
            if ($required) {
                $errors->add($field, 'Ce champ est obligatoire.');
            }

            return null;
        }
        if (preg_match(self::CONTROL_CHARACTERS, $text) === 1) {
            $errors->add($field, 'Ce champ ne peut contenir ni retour à la ligne ni caractère de contrôle.');

            return null;
        }

        return $text;
    }

    private static function money(mixed $value, string $field, FieldErrors $errors): ?int
    {
        if (! is_int($value)) {
            $errors->add($field, 'Un nombre entier de FCFA est attendu.');

            return null;
        }
        try {
            return Money::amount($value, $field);
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);

            return null;
        }
    }
}
