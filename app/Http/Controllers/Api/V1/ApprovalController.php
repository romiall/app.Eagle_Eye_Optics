<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Access\Approvals\ApprovalReader;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Http\Resources\ApprovalPresenter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Approbations exactes (B06) : demande depuis l'action bloquée, liste et décision de la gérante. Module `settings`
 * (« Paramètres », gestion gérante, fichier 04). Toute écriture passe par le chemin central de commandes (droits relus
 * sous verrou, idempotence, audit) ; la décision exige en plus une réauthentification récente (route `reauthenticated`).
 *
 * Visibilité : la gérante (`approval.decide`) lit toutes les demandes du cabinet ; un autre compte ne lit que les siennes
 * (une demande d'un autre compte répond 404, comme si elle n'existait pas).
 */
final class ApprovalController extends CommandController
{
    public function __construct(private readonly ApprovalReader $reader) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $now = CarbonImmutable::now('UTC');
        $page = $this->reader->page((string) $actor->clinic_id, $this->ownOnly($actor), $request->query('status'), $request->query('page'), $now);
        /** @var list<\stdClass> $rows */
        $rows = array_values($page->items());
        $details = $this->reader->details((string) $actor->clinic_id, $rows);

        return new JsonResponse([
            'data' => array_map(static fn (\stdClass $row): array => ApprovalPresenter::approval($row, $details[(string) $row->id] ?? null, $now), $rows),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return new JsonResponse(['data' => $this->present($request, $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if (! $present || $expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null à la création (champ obligatoire).']]);
        }

        $approvalId = is_string($payload['approval_id'] ?? null) ? $payload['approval_id'] : null;
        $result = $this->dispatchCommand($request, new Command('RequestApproval', $payload, $approvalId, null));

        return new JsonResponse(['data' => $this->present($request, (string) $result->resultId)], 201);
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        $errors = [];
        if (($payload['approval_id'] ?? null) !== $id) {
            $errors['approval_id'] = ['L\'identifiant de l\'approbation du corps doit être celui de l\'adresse.'];
        }
        if (! $present || $expected !== null) {
            $errors['expected_version'] = ['La version attendue vaut null (transition d\'état contrôlée par le serveur).'];
        }
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }

        $result = $this->dispatchCommand($request, new Command('DecideApproval', $payload, $id, null));

        return new JsonResponse(['data' => $this->present($request, (string) $result->resultId)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, string $approvalId): array
    {
        $actor = $this->actor($request);
        $row = $this->reader->find((string) $actor->clinic_id, $approvalId);
        $own = $this->ownOnly($actor);
        if ($row === null || ($own !== null && (string) $row->requested_by !== $own)) {
            throw BusinessError::notFound();
        }
        $details = $this->reader->details((string) $actor->clinic_id, [$row]);

        return ApprovalPresenter::approval($row, $details[$approvalId] ?? null, CarbonImmutable::now('UTC'));
    }

    /** Compte limité à ses propres demandes (tout compte qui ne décide pas), ou null pour la gérante. */
    private function ownOnly(User $actor): ?string
    {
        return $actor->can('approval.decide') ? null : (string) $actor->getKey();
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
