<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Billing\LegacyBalances\LegacyBalanceImport;
use App\Domain\Documents\DocumentFiles;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Models\Clinic;
use App\Models\User;
use App\Support\Files\PrivateFiles;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reprise des créances papier (X01), module `sales`, gérante (`finance.global.read`, réauthentification récente) :
 *
 *   POST /api/v1/legacy-balances/evidence   corps brut (PDF, JPEG ou PNG, en-tête facultatif X-Content-Sha256) : le
 *                                           scan de la pièce papier, contrôlé (type reconnu aux premiers octets,
 *                                           taille, empreinte) puis écrit sur le disque privé ; la commande
 *                                           StoreLegacyEvidence le rattache au cabinet ;
 *   POST /api/v1/imports/legacy-balances    { dry_run, evidence_file_id, rows } : aperçu sans écriture (dry_run =
 *                                           true), ou commande ImportLegacyBalances (toutes les créances ou aucune).
 */
final class LegacyBalanceController extends CommandController
{
    public function evidence(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $bytes = (string) $request->getContent();
        $announced = $request->header('X-Content-Sha256');
        $checked = DocumentFiles::inspectEvidence($bytes, is_string($announced) && $announced !== '' ? $announced : null);
        $path = DocumentFiles::evidencePathFor((string) $actor->clinic_id, $checked['sha256'], $checked['extension']);
        PrivateFiles::write($path, $bytes);

        $result = $this->dispatchCommand($request, new Command('StoreLegacyEvidence', [
            'sha256' => $checked['sha256'],
            'size_bytes' => $checked['size'],
            'mime_type' => $checked['mime_type'],
            'private_path' => $path,
        ], null, null));

        return new JsonResponse(['data' => [
            'file_id' => (string) ($result->result['file_id'] ?? ''),
            'sha256' => $checked['sha256'],
            'mime_type' => $checked['mime_type'],
            'size_bytes' => $checked['size'],
        ]], 201);
    }

    public function import(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = $request->json()->all();
        $rows = $body['rows'] ?? null;
        if (! is_bool($body['dry_run'] ?? null)) {
            throw BusinessError::validation(['dry_run' => ['Indiquez s\'il s\'agit d\'un aperçu (true) ou de la reprise (false).']]);
        }
        if (! is_array($rows) || ! array_is_list($rows) || $rows === [] || count($rows) > LegacyBalanceImport::MAX_ROWS) {
            throw BusinessError::validation(['rows' => ['Entre 1 et '.LegacyBalanceImport::MAX_ROWS.' créances sont attendues.']]);
        }

        if ($body['dry_run'] === true) {
            $actor = $this->actor($request);
            $clinic = Clinic::query()->findOrFail($actor->clinic_id);
            $check = LegacyBalanceImport::check((string) $actor->clinic_id, (string) $clinic->timezone, CarbonImmutable::now('UTC'), $rows);

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

        $result = $this->dispatchCommand($request, new Command('ImportLegacyBalances', $body, null, null));
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
