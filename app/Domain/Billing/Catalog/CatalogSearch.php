<?php

namespace App\Domain\Billing\Catalog;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\ValueObjects\Text;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Recherche dans le catalogue du cabinet (`GET /catalog`, B01) : référence, désignation, marque ou modèle, DEUX
 * caractères au moins (comme la recherche de patients), insensible à la casse et aux accents (collation de la
 * base). Sans texte, la liste des articles ACTIFS (le sélecteur de lignes de facture), 25 par page. Les articles
 * désactivés ne se voient qu'avec `include_inactive` ET le droit de gérer le catalogue (décidé par l'appelant).
 * Les jokers de `LIKE` sont échappés. Toujours limitée au cabinet ; le coût d'achat est filtré à la présentation.
 */
final class CatalogSearch
{
    public const PER_PAGE = 25;

    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 100;

    /**
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function __invoke(string $clinicId, mixed $query, mixed $page, bool $includeInactive): LengthAwarePaginator
    {
        $builder = DB::table('catalog_items')->where('clinic_id', $clinicId);
        if (! $includeInactive) {
            $builder->where('active', 1);
        }

        if ($query !== null && $query !== '') {
            if (! is_string($query)) {
                throw BusinessError::validation(['query' => ['La recherche est un texte.']]);
            }
            $term = Text::trim($query);
            $length = mb_strlen($term, 'UTF-8');
            if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
                throw BusinessError::validation(['query' => ['La recherche comporte de '.self::MIN_LENGTH.' à '.self::MAX_LENGTH.' caractères.']]);
            }
            $like = '%'.addcslashes($term, '\\%_').'%';
            $builder->where(static function ($where) use ($like): void {
                $where->where('reference', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('brand', 'like', $like)
                    ->orWhere('model', 'like', $like);
            });
        }

        $pageNumber = 1;
        if ($page !== null) {
            if ((! is_string($page) && ! is_int($page)) || filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw BusinessError::validation(['page' => ['La page est un entier supérieur ou égal à 1.']]);
            }
            $pageNumber = (int) $page;
        }

        return $builder->orderBy('name')->orderBy('reference')->orderBy('id')->paginate(self::PER_PAGE, ['*'], 'page', $pageNumber);
    }
}
