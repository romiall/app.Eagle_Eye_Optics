<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Http\Resources\DevicePresenter;
use App\Models\User;
use App\Offline\DeviceDirectory;
use App\Offline\DeviceResources;
use App\Offline\GrantClaims;
use App\Offline\ServerSigningKey;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Postes préparés pour le hors-ligne (O02), module `offline`, gérante (`offline.enroll`). Les écritures passent par le
 * chemin central (EnrollDevice, IssueOfflineGrant, RevokeDevice, RevokeOfflineGrant), toutes avec réauthentification
 * récente (middleware de la route) et clé d'idempotence. Rien ici n'active le travail hors-ligne : le coffre, le
 * journal et la synchronisation (O03 à O05) consommeront ces grants.
 */
final class DeviceController extends CommandController
{
    public function index(Request $request): JsonResponse
    {
        $clinicId = $this->actor($request)->clinic_id;
        $active = $this->activeGrantCounts($clinicId);

        return new JsonResponse(['data' => array_map(
            static fn (\stdClass $row): array => DevicePresenter::device($row, $active[(string) $row->id] ?? 0),
            DeviceDirectory::devices($clinicId),
        )]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return new JsonResponse(['data' => $this->presentDevice($request, $id)]);
    }

    public function enroll(Request $request): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if (! $present || $expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null à l\'enrôlement (champ obligatoire).']]);
        }

        $result = $this->dispatchCommand($request, new Command('EnrollDevice', $payload, null, null));

        return new JsonResponse(['data' => $this->presentDevice($request, (string) $result->resultId)], 201);
    }

    public function grants(Request $request, string $id): JsonResponse
    {
        $clinicId = $this->actor($request)->clinic_id;
        if (DeviceDirectory::device($clinicId, $id) === null) {
            throw BusinessError::notFound('Poste introuvable.');
        }
        $now = CarbonImmutable::now('UTC');

        return new JsonResponse(['data' => array_map(
            static fn (\stdClass $row): array => DevicePresenter::grant($row, $now),
            DeviceDirectory::grants($clinicId, $id),
        )]);
    }

    public function issueGrant(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if (! $present || $expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null à l\'émission d\'un grant (champ obligatoire).']]);
        }

        $result = $this->dispatchCommand($request, new Command('IssueOfflineGrant', $payload, $id, null));

        return new JsonResponse(['data' => $this->presentGrant($request, (string) $result->resultId)], 201);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        [$payload] = $this->directBody($request);
        $this->dispatchCommand($request, new Command('RevokeDevice', $payload, $id, null));

        return new JsonResponse(['data' => $this->presentDevice($request, $id)]);
    }

    /**
     * Remplacement d'un poste par un poste neuf déjà enrôlé (O07) : ancien poste REPLACED, ses grants révoqués, ses
     * ressources CLOSING ; aucun droit transféré.
     */
    public function replace(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if (! $present || $expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null pour un remplacement (champ obligatoire).']]);
        }
        $result = $this->dispatchCommand($request, new Command('ReplaceDevice', $payload, $id, null));

        return new JsonResponse(['data' => $result->result]);
    }

    /** Réconciliation d'un poste remplacé après la reprise complète de son journal exporté (O07). */
    public function reconcileReplacement(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if (! $present || $expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null pour une réconciliation (champ obligatoire).']]);
        }
        $result = $this->dispatchCommand($request, new Command('ReconcileReplacement', $payload, $id, null));

        return new JsonResponse(['data' => $result->result]);
    }

    public function revokeGrant(Request $request, string $id): JsonResponse
    {
        [$payload] = $this->directBody($request);
        $this->dispatchCommand($request, new Command('RevokeOfflineGrant', $payload, $id, null));

        return new JsonResponse(['data' => $this->presentGrant($request, $id)]);
    }

    /**
     * Ressources du poste (O03) : plages de numéros, propriétés de facture et quotas de stock, puis la préparation
     * complète que le poste conserve (grants actifs, plages et formats, quotas, factures, catalogue figé).
     */
    public function prepare(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if (! $present || $expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null à la préparation (champ obligatoire).']]);
        }
        $this->dispatchCommand($request, new Command('PrepareDevice', $payload, $id, null));

        return new JsonResponse(['data' => $this->presentPreparation($request, $id)]);
    }

    /** Préparation actuelle du poste (relue par le poste à la reconnexion, ou par la gérante). */
    public function preparation(Request $request, string $id): JsonResponse
    {
        return new JsonResponse(['data' => $this->presentPreparation($request, $id)]);
    }

    /** Clôture des ressources après reprise complète du journal du poste (409 sinon). */
    public function release(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if (! $present || $expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null à la clôture (champ obligatoire).']]);
        }
        $result = $this->dispatchCommand($request, new Command('ReleaseDevice', $payload, $id, null));

        return new JsonResponse(['data' => $result->result]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPreparation(Request $request, string $id): array
    {
        $clinicId = $this->actor($request)->clinic_id;
        if (DeviceDirectory::device($clinicId, $id) === null) {
            throw BusinessError::notFound('Poste introuvable.');
        }

        return DeviceResources::preparation(
            $clinicId,
            $id,
            CarbonImmutable::now('UTC'),
            static fn (\stdClass $row, CarbonImmutable $now): array => DevicePresenter::grant($row, $now),
        );
    }

    /**
     * Clés publiques du serveur à épingler par un poste pour vérifier ses grants (courante puis retirées). Aucune
     * donnée privée. 409 tant que l'exploitant n'a pas installé la clé.
     */
    public function signingKeys(ServerSigningKey $key): JsonResponse
    {
        if (! $key->exists()) {
            throw BusinessError::stateRefused('La clé de signature des grants n\'est pas installée sur le serveur.', 'SIGNING_KEY_MISSING');
        }
        try {
            $keys = $key->keySet();
        } catch (RuntimeException) {
            throw BusinessError::stateRefused('La clé de signature des grants est illisible sur le serveur.', 'SIGNING_KEY_MISSING');
        }

        return new JsonResponse(['data' => ['algorithm' => 'ES256', 'grant_type' => GrantClaims::TYPE, 'keys' => $keys]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDevice(Request $request, string $id): array
    {
        $clinicId = $this->actor($request)->clinic_id;
        $row = DeviceDirectory::device($clinicId, $id);
        if ($row === null) {
            throw BusinessError::notFound('Poste introuvable.');
        }

        return DevicePresenter::device($row, $this->activeGrantCounts($clinicId)[$id] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentGrant(Request $request, string $id): array
    {
        $row = DeviceDirectory::grant($this->actor($request)->clinic_id, $id);
        if ($row === null) {
            throw BusinessError::notFound('Grant introuvable.');
        }

        return DevicePresenter::grant($row, CarbonImmutable::now('UTC'));
    }

    /**
     * @return array<string, int>
     */
    private function activeGrantCounts(string $clinicId): array
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

        return DB::table('offline_grants')
            ->where('clinic_id', $clinicId)
            ->whereNull('revoked_at')
            ->where('valid_until', '>', $now)
            ->groupBy('device_id')
            ->selectRaw('device_id, COUNT(*) as total')
            ->pluck('total', 'device_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
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
