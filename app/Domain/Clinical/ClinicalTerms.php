<?php

namespace App\Domain\Clinical;

use Illuminate\Support\Facades\DB;

/**
 * Référentiel `clinical_terms` (codes cliniques du modèle DP, global à l'installation) : seuls les codes ACTIFS
 * d'un groupe sont admis à l'enregistrement. Un code désactivé reste lisible dans les consultations déjà
 * saisies (clé étrangère) mais ne peut plus être choisi.
 */
final class ClinicalTerms
{
    /**
     * Codes actifs d'un groupe, dans l'ordre d'affichage.
     *
     * @return list<string>
     */
    public function activeCodes(string $group): array
    {
        $codes = DB::table('clinical_terms')
            ->where('group_code', $group)
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->pluck('code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();

        return array_values($codes);
    }
}
