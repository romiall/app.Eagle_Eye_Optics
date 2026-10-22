<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Cash\CashSessionDirectory;
use App\Domain\Cash\CashSessionReport;
use App\Domain\Cash\CashSessions;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Http\Resources\CashPresenter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Caisse et sessions de caisse (B05, EE030) : la caisse principale, la session ouverte du compte, ouverture avec fond
 * et clôture avec compté, attendu recalculé et écart motivé. Les écritures passent par le chemin central de commandes.
 * Module `sales`. Tout encaissement en espèces exige une session ouverte de l'auteur.
 */
final class CashSessionController extends CommandController
{
    public function registers(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        return new JsonResponse(['data' => array_map(CashPresenter::register(...), CashSessions::registers((string) $actor->clinic_id))]);
    }

    public function current(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $session = CashSessions::current((string) $actor->clinic_id, (string) $actor->getKey());

        return new JsonResponse(['data' => $session === null ? null : CashPresenter::session($session)]);
    }

    /**
     * Sessions du cabinet pour la gérante (`cash.manage`) : ouvertes d'abord (tous postes, toutes caissières), puis
     * clôturées ; filtre `state` (`open`, `closed`). Elle peut alors clôturer la session d'une autre caissière.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $page = CashSessionDirectory::page((string) $actor->clinic_id, $request->query('state'), $request->query('page'));

        return new JsonResponse([
            'data' => array_map(static fn (\stdClass $row): array => CashPresenter::entry($row), array_values($page->items())),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Relevé d'une session : la sienne pour une caissière (`cash.own_session`), toute session pour la gérante
     * (`cash.manage`) ; une autre caissière reçoit 403 (fichier 09 : « sa propre session »).
     */
    public function report(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $session = CashSessionDirectory::find((string) $actor->clinic_id, $id);
        if ($session === null) {
            throw BusinessError::notFound('Session de caisse introuvable dans ce cabinet.');
        }
        if ((string) $session->cashier_id !== (string) $actor->getKey() && ! $actor->can('cash.manage')) {
            throw BusinessError::forbidden('Cette session appartient à une autre caissière : seule la gérante lit son relevé.');
        }
        $now = CarbonImmutable::now('UTC');

        return new JsonResponse(['data' => CashPresenter::report(CashSessionReport::build($session, $now), $now)]);
    }

    public function open(Request $request): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if (! $present || $expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null à l\'ouverture (champ obligatoire).']]);
        }

        $sessionId = is_string($payload['cash_session_id'] ?? null) ? $payload['cash_session_id'] : null;
        $result = $this->dispatchCommand($request, new Command('OpenCashSession', $payload, $sessionId, null));

        return new JsonResponse(['data' => $this->present($request, (string) $result->resultId)], 201);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        $errors = [];
        if (($payload['cash_session_id'] ?? null) !== $id) {
            $errors['cash_session_id'] = ['L\'identifiant de la session du corps doit être celui de l\'adresse.'];
        }
        if (! $present || $expected !== null) {
            $errors['expected_version'] = ['La version attendue vaut null pour une clôture (champ obligatoire).'];
        }
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }

        $result = $this->dispatchCommand($request, new Command('CloseCashSession', $payload, $id, null));

        return new JsonResponse(['data' => $this->present($request, (string) $result->resultId)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, string $sessionId): array
    {
        $session = CashSessions::find((string) $this->actor($request)->clinic_id, $sessionId);
        if ($session === null) {
            throw BusinessError::notFound();
        }

        return CashPresenter::session($session);
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
