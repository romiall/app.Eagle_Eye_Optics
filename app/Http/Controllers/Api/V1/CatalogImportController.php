<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Billing\Catalog\CatalogImport;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Import contrôlé du catalogue (X01), module `sales`, gérante (`catalog.manage`, réauthentification récente) :
 * POST /api/v1/imports/catalog. Le fichier CSV est lu sur le poste ; le serveur revérifie chaque ligne.
 *
 *   dry_run = true   aperçu : rapport des lignes acceptées et refusées, AUCUNE écriture (ni commande, ni audit) ;
 *   dry_run = false  commande centrale `ImportCatalog` : toutes les lignes créées dans une transaction, ou aucune
 *                    (422 ligne par ligne) ; la même clé d'idempotence rejoue le même import.
 */
final class CatalogImportController extends CommandController
{
    public function store(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = $request->json()->all();
        $rows = $body['rows'] ?? null;
        if (! is_bool($body['dry_run'] ?? null)) {
            throw BusinessError::validation(['dry_run' => ['Indiquez s\'il s\'agit d\'un aperçu (true) ou de l\'import (false).']]);
        }
        if (! is_array($rows) || ! array_is_list($rows) || $rows === [] || count($rows) > CatalogImport::MAX_ROWS) {
            throw BusinessError::validation(['rows' => ['Entre 1 et '.CatalogImport::MAX_ROWS.' lignes sont attendues.']]);
        }

        if ($body['dry_run'] === true) {
            $check = CatalogImport::check((string) $this->actor($request)->clinic_id, $rows);

            return new JsonResponse(['data' => [
                'dry_run' => true,
                'rows' => count($rows),
                'accepted' => count($check['accepted']),
                'rejected' => array_map(static fn (array $rejection): array => [
                    'line' => $rejection['line'],
                    'field' => $rejection['field'],
                    'message' => $rejection['message'],
                ], $check['rejected']),
                'created_ids' => [],
            ]]);
        }

        $result = $this->dispatchCommand($request, new Command('ImportCatalog', $body, null, null));
        /** @var list<string> $created */
        $created = array_values(array_map('strval', (array) ($result->result['created_ids'] ?? [])));

        return new JsonResponse(['data' => [
            'dry_run' => false,
            'rows' => count($rows),
            'accepted' => count($created),
            'rejected' => [],
            'created_ids' => $created,
        ]], 201);
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
