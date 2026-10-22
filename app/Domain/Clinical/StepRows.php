<?php

namespace App\Domain\Clinical;

use App\Domain\Clinical\Rules\FieldErrors;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Outils communs aux écritures de lignes d'une rubrique clinique (C02, C04) : liste de lignes brutes, identifiants
 * de ligne valides, refus d'un identifiant déjà utilisé AILLEURS, forme comparable.
 *
 * Toutes les requêtes sont paramétrées ; les noms de table sont des constantes des appelants.
 */
final class StepRows
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $value): array
    {
        $rows = [];
        if (is_array($value)) {
            foreach ($value as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Identifiants ULID valides d'une liste de lignes brutes, par position.
     *
     * @return array<int, string>
     */
    public static function idsAt(mixed $items, string $field = 'id'): array
    {
        $ids = [];
        if (is_array($items) && array_is_list($items)) {
            foreach ($items as $index => $item) {
                if (is_array($item) && is_string($item[$field] ?? null) && Ulid::isValid($item[$field])) {
                    $ids[$index] = $item[$field];
                }
            }
        }

        return $ids;
    }

    /**
     * Identifiants des lignes d'une table rattachées à cette consultation (réutilisables).
     *
     * @return list<string>
     */
    public static function ownIds(string $table, stdClass $consultation): array
    {
        return DB::table($table)
            ->where('clinic_id', $consultation->clinic_id)
            ->where('consultation_id', $consultation->id)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * Un identifiant de ligne déjà utilisé AILLEURS (autre consultation, autre cabinet) est refusé par champ : la clé
     * primaire est globale, et le refus doit être un 422 lisible, jamais une erreur SQL.
     *
     * @param  array<int|string, string>  $incoming  identifiant de ligne par clé de chemin (« 2 » ou « 1.terms.0 »)
     * @param  array<array-key, string>  $own  identifiants des lignes de cette consultation (réutilisables)
     * @param  string  $suffix  champ désigné sous le chemin de la ligne (« id » pour une liste, vide pour un champ direct)
     */
    public static function flagTaken(string $table, array $incoming, array $own, string $path, FieldErrors $errors, string $suffix = '.id'): void
    {
        if ($incoming === []) {
            return;
        }
        $present = DB::table($table)->whereIn('id', array_values(array_unique($incoming)))->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $taken = array_diff($present, $own);
        foreach ($incoming as $key => $id) {
            if (in_array($id, $taken, true)) {
                $errors->add($path.'.'.$key.$suffix, 'Cet identifiant est déjà utilisé : générez un identifiant neuf pour une nouvelle ligne.');
            }
        }
    }

    /**
     * Forme comparable : les listes de lignes (portant `id`) sont triées par identifiant, à tous les niveaux.
     * L'ordre d'une liste dont l'ordre COMPTE (médicaments) se compare sans cette fonction.
     */
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $mapped = array_map(self::canonical(...), $value);
        if (array_is_list($mapped) && $mapped !== [] && is_array($mapped[0]) && isset($mapped[0]['id'])) {
            usort($mapped, static fn (mixed $a, mixed $b): int => strcmp((string) (is_array($a) ? $a['id'] : ''), (string) (is_array($b) ? $b['id'] : '')));
        }

        return $mapped;
    }
}
