<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use stdClass;

/**
 * Représentation du catalogue et des règles fiscales pour l'API (`CatalogItem`, `TaxRule` de
 * `schemas/resources/billing.json`). Le prix de vente est visible des profils autorisés à lire le catalogue ; le
 * coût d'achat n'est présent QUE pour qui détient `catalog.purchase_cost.read` (la gérante) : pour tout autre compte
 * la clé est ABSENTE de la réponse (jamais `null`, qui dirait « coût inconnu »).
 */
final class CatalogPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function item(stdClass $row, bool $withPurchaseCost): array
    {
        $item = [
            'id' => $row->id,
            'reference' => $row->reference,
            'category_code' => $row->category_code,
            'name' => $row->name,
            'brand' => $row->brand,
            'model' => $row->model,
            'unit_code' => $row->unit_code,
            'quantity_integer_only' => (bool) $row->quantity_integer_only,
            'sale_price' => (int) $row->sale_price,
            'tax_rule_id' => $row->tax_rule_id,
            'stock_mode' => $row->stock_mode,
            'requires_expiry' => (bool) $row->requires_expiry,
            'low_stock_threshold' => $row->low_stock_threshold === null ? null : (string) $row->low_stock_threshold,
            'expiry_alert_days' => $row->expiry_alert_days === null ? null : (int) $row->expiry_alert_days,
            'active' => (bool) $row->active,
            'public_visibility' => (bool) $row->public_visibility,
            'version' => (int) $row->version,
        ];
        if ($withPurchaseCost) {
            $item['purchase_cost'] = $row->purchase_cost === null ? null : (int) $row->purchase_cost;
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    public static function taxRule(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'label' => $row->label,
            'rate_percent' => $row->rate_percent === null ? null : (string) $row->rate_percent,
            'active' => (bool) $row->active,
            'validated_by' => $row->validated_by,
            'validated_at' => $row->validated_at === null ? null : CarbonImmutable::parse((string) $row->validated_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'legal_note' => $row->legal_note,
            'version' => (int) $row->version,
        ];
    }
}
