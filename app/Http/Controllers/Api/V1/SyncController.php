<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\Ulid;
use App\Http\Controllers\CommandController;
use App\Http\Middleware\AssignCorrelationId;
use App\Models\User;
use App\Offline\ConflictDirectory;
use App\Offline\PullChanges;
use App\Offline\PushCommands;
use App\Offline\SyncDrain;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Synchronisation des postes préparés (O05), module `offline` :
 *
 *   POST /api/v1/sync/push                      lot ordonné de commandes signées (`PushCommands`) ; un ACK par commande
 *   GET  /api/v1/sync/pull?device_id&cursor     changements autorisés depuis un curseur signé (`PullChanges`)
 *   GET  /api/v1/sync/conflicts                 conflits à revoir (gérante, `approval.decide`)
 *   POST /api/v1/sync/conflicts/{id}/resolution décision motivée (gérante, réauthentification) par le chemin central
 *   POST /api/v1/devices/{id}/recovered-push    reprise (O07) du journal exporté d'un poste REMPLACÉ, par la gérante
 *   GET  /api/v1/sync/compatibility             versions reprises par la release du serveur (O06 : mise à jour sûre)
 *
 * Le contrôleur n'écrit rien lui-même : l'inbox et l'application ordonnée passent par `TransactionRunner` et le
 * chemin central de commandes, la résolution par `ResolveSyncConflict`. Après une résolution committée, l'inbox du poste
 * est reprise (ACCEPT_LOCAL rejoué, rangs suivants appliqués) ; un échec de cette reprise n'annule pas la décision :
 * le prochain envoi du poste la refera.
 */
final class SyncController extends CommandController
{
    public function push(Request $request, PushCommands $push): JsonResponse
    {
        return new JsonResponse($push->push($this->actor($request), (string) $request->getContent(), AssignCorrelationId::current($request)));
    }

    /**
     * Reprise d'un poste remplacé (O07) : mêmes vérifications que le push (empreintes, signatures de la clé enrôlée de
     * l'ancien poste, chaînage), puis application normale ; mêmes identifiants et numéros, jamais renumérotés.
     */
    public function recoveredPush(Request $request, PushCommands $push, string $id): JsonResponse
    {
        return new JsonResponse($push->push($this->actor($request), (string) $request->getContent(), AssignCorrelationId::current($request), $id));
    }

    /**
     * Déclaration de compatibilité de la release (`contracts/compatibility.json`, O06) : versions de commandes, de
     * règles, de coffre et de schéma local que ce serveur sait encore reprendre. Le poste la compare à ses commandes en
     * attente avant d'accepter une mise à jour.
     */
    public function compatibility(): JsonResponse
    {
        $declaration = json_decode((string) file_get_contents(base_path('contracts/compatibility.json')), true, 512, JSON_THROW_ON_ERROR);

        return new JsonResponse(['data' => $declaration]);
    }

    public function pull(Request $request, PullChanges $pull): JsonResponse
    {
        $deviceId = $request->query('device_id');
        if (! is_string($deviceId) || ! Ulid::isValid($deviceId)) {
            throw BusinessError::validation(['device_id' => ['Identifiant du poste attendu (ULID).']]);
        }
        $cursor = $request->query('cursor');
        if ($cursor !== null && (! is_string($cursor) || strlen($cursor) > 1024)) {
            throw BusinessError::validation(['cursor' => ['Curseur de synchronisation invalide.']]);
        }

        return new JsonResponse($pull->page($this->actor($request), $deviceId, $cursor));
    }

    public function conflicts(Request $request): JsonResponse
    {
        return new JsonResponse(ConflictDirectory::page((string) $this->actor($request)->clinic_id, $request->query('status'), $request->query('page')));
    }

    public function resolve(Request $request, SyncDrain $drain, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        [$payload, $expected, $present] = $this->directBody($request);
        if (! $present || $expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null pour une résolution (champ obligatoire).']]);
        }

        $result = $this->dispatchCommand($request, new Command('ResolveSyncConflict', $payload, $id, null));

        $deviceId = $result->result['device_id'] ?? null;
        if (is_string($deviceId)) {
            try {
                $drain->drain((string) $actor->clinic_id, $deviceId, AssignCorrelationId::current($request));
            } catch (Throwable $error) {
                report($error);
            }
        }

        $conflict = ConflictDirectory::find((string) $actor->clinic_id, $id);
        if ($conflict === null) {
            throw BusinessError::notFound('Conflit de synchronisation introuvable.');
        }

        return new JsonResponse(['data' => $conflict]);
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
