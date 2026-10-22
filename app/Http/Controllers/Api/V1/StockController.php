<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Inventory\StockAvailabilityReader;
use App\Domain\Inventory\StockLocations;
use App\Http\Controllers\CommandController;
use App\Http\Resources\StockPresenter;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stock (I00) : disponibilités par position et lot, et stock initial validé par la gérante. Module `stock`. La
 * réserve de stock n'a PAS de route : c'est un service interne de l'émission d'une facture. Le stock initial passe par
 * le chemin central de commandes (version de la requête = aucune, clé d'idempotence obligatoire).
 */
final class StockController extends CommandController
{
    public function availability(Request $request, StockAvailabilityReader $reader): JsonResponse
    {
        $actor = $this->actor($request);
        $clinicId = (string) $actor->clinic_id;
        $page = $reader->page($clinicId, $request->query('item_id'), $request->query('location_id'), $request->query('page'));
        $positions = array_values($page->items());
        $lots = $reader->lots($clinicId, array_map(static fn (\stdClass $row): string => (string) $row->id, $positions));

        return new JsonResponse([
            'data' => array_map(static fn (\stdClass $row): array => StockPresenter::availability($row, $lots[(string) $row->id] ?? []), $positions),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Emplacements de stock actifs du cabinet (choix de l'emplacement du stock initial, filtre des disponibilités).
     */
    public function locations(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        return new JsonResponse(['data' => array_map(StockPresenter::location(...), StockLocations::list((string) $actor->clinic_id))]);
    }

    public function opening(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $result = $this->dispatchCommand($request, new Command('RegisterOpeningStock', $payload, null, null));

        /** @var list<string> $movementIds */
        $movementIds = array_values(array_map('strval', (array) ($result->result['movement_ids'] ?? [])));
        $rows = DB::table('stock_movements')
            ->where('clinic_id', $this->actor($request)->clinic_id)
            ->whereIn('id', $movementIds)
            ->orderBy('movement_position')
            ->orderBy('id')
            ->get();

        return new JsonResponse(['data' => ['movements' => array_map(static fn (\stdClass $row): array => StockPresenter::movement($row), array_values($rows->all()))]], 201);
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
