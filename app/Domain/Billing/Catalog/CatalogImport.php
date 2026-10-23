<?php

namespace App\Domain\Billing\Catalog;

use App\Domain\Billing\Catalog\Rules\CatalogItemInput;
use App\Domain\Billing\Rules\FieldErrors;
use Illuminate\Support\Facades\DB;
use Normalizer;

/**
 * Import contrôlé du catalogue (X01, « prévisualiser / rejeter les lignes invalides sans écriture partielle ») : chaque
 * ligne passe EXACTEMENT les règles d'un article saisi à l'écran (`CatalogItemInput`), une référence apparaît une seule
 * fois dans le fichier et n'existe pas déjà au catalogue (comparaison de la base : casse et accents ignorés). Le même
 * contrôle sert l'aperçu (aucune écriture) et l'import (une seule transaction : une ligne refusée, rien n'est importé).
 * Aucun prix n'est fourni par le code : tout vient du fichier du cabinet.
 */
final class CatalogImport
{
    /** Lignes au plus par import (un catalogue plus grand se découpe en plusieurs fichiers). */
    public const MAX_ROWS = 500;

    /**
     * @param  list<mixed>  $rows  lignes du fichier, chacune avec son numéro de ligne (`line`) et les champs d'un article
     * @return array{accepted: list<array{index: int, line: int, columns: array<string, mixed>}>, rejected: list<array{index: int, line: int, field: string|null, message: string}>}
     */
    public static function check(string $clinicId, array $rows): array
    {
        $units = CatalogLookups::units();
        $categories = CatalogLookups::categories();
        $taxRuleIds = CatalogLookups::taxRuleIds($clinicId);

        $accepted = [];
        $rejected = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $line = is_array($row) && is_int($row['line'] ?? null) ? $row['line'] : $index + 2;
            if (! is_array($row)) {
                $rejected[] = ['index' => $index, 'line' => $line, 'field' => null, 'message' => 'Ligne illisible.'];

                continue;
            }
            $payload = $row;
            unset($payload['line']);

            $errors = new FieldErrors;
            $columns = CatalogItemInput::columns($payload, $units, $categories, $taxRuleIds, $errors);
            foreach ($errors->all() as $field => $messages) {
                foreach ($messages as $message) {
                    $rejected[] = ['index' => $index, 'line' => $line, 'field' => $field === '' ? null : $field, 'message' => $message];
                }
            }
            if ($columns === null) {
                continue;
            }

            $key = self::referenceKey((string) $columns['reference']);
            if (isset($seen[$key])) {
                $rejected[] = ['index' => $index, 'line' => $line, 'field' => 'reference',
                    'message' => 'Référence déjà présente à la ligne '.$seen[$key].' du fichier.'];

                continue;
            }
            $seen[$key] = $line;
            $accepted[] = ['index' => $index, 'line' => $line, 'columns' => $columns];
        }

        if ($accepted !== []) {
            $existing = DB::table('catalog_items')
                ->where('clinic_id', $clinicId)
                ->whereIn('reference', array_map(static fn (array $item): string => (string) $item['columns']['reference'], $accepted))
                ->pluck('reference')
                ->map(static fn (mixed $reference): string => self::referenceKey((string) $reference))
                ->all();
            $taken = array_fill_keys($existing, true);
            $kept = [];
            foreach ($accepted as $item) {
                if (isset($taken[self::referenceKey((string) $item['columns']['reference'])])) {
                    $rejected[] = ['index' => $item['index'], 'line' => $item['line'], 'field' => 'reference',
                        'message' => 'Cette référence existe déjà dans le catalogue.'];

                    continue;
                }
                $kept[] = $item;
            }
            $accepted = $kept;
        }

        usort($rejected, static fn (array $a, array $b): int => [$a['line'], (string) $a['field']] <=> [$b['line'], (string) $b['field']]);

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * Clé de comparaison d'une référence, comme la collation de la base (`utf8mb4_0900_ai_ci`) : casse et accents
     * ignorés, ponctuation conservée (« MON-01 » et « MON 01 » restent deux références).
     */
    private static function referenceKey(string $reference): string
    {
        $decomposed = Normalizer::normalize(mb_strtolower(trim($reference), 'UTF-8'), Normalizer::FORM_D);

        return (string) preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $reference : $decomposed);
    }
}
