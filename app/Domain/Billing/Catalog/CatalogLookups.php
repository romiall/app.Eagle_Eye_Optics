<?php

namespace App\Domain\Billing\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * Référentiels lus pour valider un article : catégories et unités (référentiels globaux seedés, pas de conversion
 * implicite d'une unité à l'autre) et règles fiscales du cabinet.
 */
final class CatalogLookups
{
    /**
     * @return array<string, bool> code d'unité → quantité entière obligatoire
     */
    public static function units(): array
    {
        $units = [];
        foreach (DB::table('units')->get(['code', 'integer_only']) as $row) {
            $units[(string) $row->code] = (bool) $row->integer_only;
        }

        return $units;
    }

    /**
     * @return list<string>
     */
    public static function categories(): array
    {
        return array_values(array_map('strval', DB::table('catalog_categories')->orderBy('code')->pluck('code')->all()));
    }

    /**
     * @return list<string>
     */
    public static function taxRuleIds(string $clinicId): array
    {
        return array_values(array_map('strval', DB::table('tax_rules')->where('clinic_id', $clinicId)->pluck('id')->all()));
    }
}
