<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Billing\Catalog\CatalogSearch;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Http\Resources\CatalogPresenter;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue du cabinet (B01) : recherche pour les lignes de facture et gestion des articles par la gérante
 * (`catalog.manage`). Les écritures passent par le chemin central de commandes (version attendue, audit des prix) ;
 * les lectures sont limitées au cabinet. Le coût d'achat n'est rendu qu'à qui détient `catalog.purchase_cost.read`.
 *
 * Module `sales`. Aucun prix réel n'est connu du dépôt : le catalogue vient de la gérante (Q04) ou, en
 * développement, de `eeo:demo:sales` (données FICTIVES).
 */
final class CatalogController extends CommandController
{
    public function index(Request $request, CatalogSearch $search): JsonResponse
    {
        $actor = $this->actor($request);
        // Les articles désactivés ne se listent que pour qui gère le catalogue (jamais pour le sélecteur de lignes).
        $includeInactive = $actor->can('catalog.manage') && $request->query('include_inactive') === 'true';
        $page = $search((string) $actor->clinic_id, $request->query('query'), $request->query('page'), $includeInactive);
        $withCost = $actor->can('catalog.purchase_cost.read');

        return new JsonResponse([
            'data' => array_map(static fn (\stdClass $row): array => CatalogPresenter::item($row, $withCost), $page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if (! $present || $expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null à la création (champ obligatoire).']]);
        }

        $result = $this->dispatchCommand($request, new Command('CreateCatalogItem', $payload, null, null));

        return new JsonResponse(['data' => $this->present($request, (string) $result->resultId)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        [$payload, $expected] = $this->directBody($request);

        $result = $this->dispatchCommand($request, new Command('UpdateCatalogItem', $payload, $id, $expected));

        return new JsonResponse(['data' => $this->present($request, (string) $result->resultId)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, string $itemId): array
    {
        $actor = $this->actor($request);
        $row = DB::table('catalog_items')->where('clinic_id', $actor->clinic_id)->where('id', $itemId)->first();
        if ($row === null) {
            throw BusinessError::notFound();
        }

        return CatalogPresenter::item($row, $actor->can('catalog.purchase_cost.read'));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
