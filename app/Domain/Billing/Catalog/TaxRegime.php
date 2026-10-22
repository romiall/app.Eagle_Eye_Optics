<?php

namespace App\Domain\Billing\Catalog;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Règles fiscales APPLICABLES à une facture (fichier 03 : « La gérante fixe taxe et remise ; ne pas inventer de
 * taux »). Une règle n'est appliquée que si elle est active ET validée par la gérante (CHECK `ck_tax_rules_02` : un
 * taux, un validateur et une date). Sans règle applicable, la ligne porte `NO_TAX_CONFIGURED` et aucune taxe n'est
 * ventilée : la taxe est `null`, jamais zéro.
 */
final class TaxRegime
{
    /** Code porté par une ligne sans règle fiscale applicable (`invoice_lines.tax_code_snapshot`). */
    public const NO_TAX_CONFIGURED = 'NO_TAX_CONFIGURED';

    /**
     * @param  list<string>  $ruleIds
     * @return array<string, stdClass> règles applicables par identifiant
     */
    public static function applicable(string $clinicId, array $ruleIds): array
    {
        $ids = array_values(array_unique(array_filter($ruleIds, is_string(...))));
        if ($ids === []) {
            return [];
        }

        /** @var array<string, stdClass> */
        return DB::table('tax_rules')
            ->where('clinic_id', $clinicId)
            ->whereIn('id', $ids)
            ->where('active', 1)
            ->whereNotNull('rate_percent')
            ->whereNotNull('validated_by')
            ->whereNotNull('validated_at')
            ->get()
            ->keyBy('id')
            ->all();
    }
}
