<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Cash\CashMovements;
use App\Domain\Cash\CashSessionDirectory;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * Mouvements de caisse hors vente et catégories de dépenses (L1D05), module `sales`.
 *
 *   GET  /cash-sessions/{id}/movements            mouvements de la session et revue de son écart (la sienne, ou toute
 *                                                session pour la gérante)
 *   POST /cash-sessions/{id}/movements            RecordCashMovement (approbation exacte ou exécution de la gérante)
 *   POST /cash-sessions/{id}/variance-review      ReviewCashVariance (gérante, mot de passe confirmé)
 *   GET  /expense-categories                      catégories (actives seulement sans cash.manage)
 *   POST /expense-categories                      SaveExpenseCategory (gérante)
 */
final class CashMovementController extends CommandController
{
    public function index(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $session = $this->visibleSession($actor, $id);
        $review = CashMovements::varianceReview((string) $actor->clinic_id, $id);

        return new JsonResponse(['data' => [
            'cash_session_id' => $id,
            'closed' => $session->closed_at !== null,
            'movements' => array_map(self::movement(...), CashMovements::forSession((string) $actor->clinic_id, $id)),
            'variance_review' => $review === null ? null : self::presentReview($review),
        ]]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $payload = $this->body($request, 'cash_session_id', $id);
        $result = $this->dispatchCommand($request, new Command('RecordCashMovement', $payload, $id, null));
        $actor = $this->actor($request);
        foreach (CashMovements::forSession((string) $actor->clinic_id, $id) as $row) {
            if ((string) $row->id === (string) $result->resultId) {
                return new JsonResponse(['data' => self::movement($row)], 201);
            }
        }
        throw BusinessError::notFound();
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $payload = $this->body($request, 'cash_session_id', $id);
        $this->dispatchCommand($request, new Command('ReviewCashVariance', $payload, $id, null));
        $review = CashMovements::varianceReview((string) $this->actor($request)->clinic_id, $id);
        if ($review === null) {
            throw BusinessError::notFound();
        }

        return new JsonResponse(['data' => self::presentReview($review)], 201);
    }

    public function categories(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $rows = CashMovements::categories((string) $actor->clinic_id, ! $actor->can('cash.manage'));

        return new JsonResponse(['data' => array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'active' => (bool) $row->active,
        ], $rows)]);
    }

    public function saveCategory(Request $request): JsonResponse
    {
        $payload = $this->body($request, null, null);
        $result = $this->dispatchCommand($request, new Command('SaveExpenseCategory', $payload, is_string($payload['category_id'] ?? null) ? $payload['category_id'] : null, null));
        $actor = $this->actor($request);
        foreach (CashMovements::categories((string) $actor->clinic_id) as $row) {
            if ((string) $row->id === (string) $result->resultId) {
                return new JsonResponse(['data' => ['id' => (string) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name, 'active' => (bool) $row->active]]);
            }
        }
        throw BusinessError::notFound();
    }

    /**
     * Corps d'une commande en ligne sans version : `expected_version` présent et null ; identifiant du corps égal à
     * celui de l'adresse quand il y en a un.
     *
     * @return array<string, mixed>
     */
    private function body(Request $request, ?string $field, ?string $id): array
    {
        [$payload, $expected, $present] = $this->directBody($request);
        $errors = [];
        if ($field !== null && ($payload[$field] ?? null) !== $id) {
            $errors[$field] = ['L\'identifiant du corps doit être celui de l\'adresse.'];
        }
        if (! $present || $expected !== null) {
            $errors['expected_version'] = ['La version attendue vaut null (champ obligatoire).'];
        }
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }

        return $payload;
    }

    private function visibleSession(User $actor, string $id): stdClass
    {
        $session = CashSessionDirectory::find((string) $actor->clinic_id, $id);
        if ($session === null) {
            throw BusinessError::notFound('Session de caisse introuvable dans ce cabinet.');
        }
        if ((string) $session->cashier_id !== (string) $actor->getKey() && ! $actor->can('cash.manage')) {
            throw BusinessError::forbidden('Cette session appartient à une autre caissière : seule la gérante lit ses mouvements.');
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private static function movement(stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'cash_session_id' => (string) $row->cash_session_id,
            'kind' => (string) $row->kind,
            'signed_amount' => (int) $row->signed_amount,
            'reason' => (string) $row->reason,
            'reference' => $row->reference,
            'occurred_at' => self::instant((string) $row->occurred_at),
            'expense_category' => $row->expense_category_id === null ? null : [
                'id' => (string) $row->expense_category_id,
                'code' => (string) $row->category_code,
                'name' => (string) $row->category_name,
            ],
            'created_by' => (string) $row->created_by,
            'created_by_name' => $row->created_by_name,
            'approval_id' => (string) $row->approval_id,
            // Exécution directe : la gérante a demandé et décidé elle-même.
            'approval_mode' => (string) $row->approval_requested_by === (string) $row->approval_decided_by ? 'DIRECT' : 'APPROVED',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentReview(stdClass $row): array
    {
        $diff = is_string($row->sanitized_diff) ? json_decode($row->sanitized_diff, true, 512, JSON_THROW_ON_ERROR) : [];

        return [
            'decision' => (string) ($diff['decision'] ?? ''),
            'note' => $row->reason,
            'reviewed_by' => $row->actor_id,
            'reviewed_by_name' => (string) $row->actor_name_snapshot,
            'reviewed_at' => self::instant((string) $row->recorded_at),
        ];
    }

    private static function instant(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
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
