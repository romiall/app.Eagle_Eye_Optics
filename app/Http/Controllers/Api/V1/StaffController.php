<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Actions\CreateStaffMember;
use App\Domain\Access\Actions\ResetStaffPassword;
use App\Domain\Access\Actions\SetStaffPermissionOverride;
use App\Domain\Access\Actions\UpdateStaffMember;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffMemberPresenter;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Comptes du personnel du cabinet de la gérante connectée (permission users.manage ; mutations après
 * réauthentification). Toujours filtré par le cabinet du compte connecté : un identifiant d'un autre
 * cabinet répond « introuvable ».
 */
final class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $page = User::query()->where('clinic_id', $actor->clinic_id)->orderBy('display_name')->orderBy('id')->paginate(50);

        return new JsonResponse([
            'data' => array_map(static fn (User $user): array => StaffMemberPresenter::present($user, false), $page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return new JsonResponse(['data' => StaffMemberPresenter::present($this->target($request, $id))]);
    }

    public function store(Request $request, CreateStaffMember $create): JsonResponse
    {
        $user = $create($this->actor($request), $request->all());

        return new JsonResponse(['data' => StaffMemberPresenter::present($user)], 201);
    }

    public function update(Request $request, string $id, UpdateStaffMember $update): JsonResponse
    {
        $target = $this->target($request, $id);

        return new JsonResponse(['data' => StaffMemberPresenter::present($update($this->actor($request), $target->id, $request->all()))]);
    }

    public function permissions(Request $request, string $id, SetStaffPermissionOverride $override): JsonResponse
    {
        $target = $this->target($request, $id);

        return new JsonResponse(['data' => StaffMemberPresenter::present($override($this->actor($request), $target->id, $request->all()))]);
    }

    public function resetPassword(Request $request, string $id, ResetStaffPassword $reset): Response
    {
        $target = $this->target($request, $id);
        $reset($this->actor($request), $target->id, $request->all());

        return response()->noContent();
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    private function target(Request $request, string $id): User
    {
        /** @var User|null $target */
        $target = User::query()->where('clinic_id', $this->actor($request)->clinic_id)->find($id);
        if ($target === null) {
            throw BusinessError::notFound();
        }

        return $target;
    }
}
