<?php

namespace App\Domain\Access\Actions;

use App\Application\Command;
use App\Application\CommandBus;
use App\Domain\Access\Handlers\UpdateStaffMemberHandler;
use App\Domain\Access\StaffRoles;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Http\Middleware\AssignCorrelationId;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Modification d'un compte par une gérante : nom, courriel, profils, activation. Version attendue
 * (verrouillage optimiste), motif obligatoire pour une désactivation ou un retrait de profil.
 *
 * Une désactivation ferme immédiatement toutes les sessions serveur du compte. Le cabinet garde toujours
 * au moins une gérante active capable de gérer les comptes, une gérante ne se désactive pas elle-même et
 * personne ne modifie ses propres profils (pas d'auto-attribution ni d'auto-rétrogradation qui
 * contournerait le contrôle d'une autre gérante).
 *
 * Cette classe valide la saisie ; les règles ci-dessus s'appliquent dans UpdateStaffMemberHandler, exécuté par
 * le chemin central de commandes (F07) sous le verrou du cabinet.
 */
final class UpdateStaffMember
{
    public function __construct(private readonly CommandBus $bus) {}

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function __invoke(User $actor, string $userId, array $input): User
    {
        $clinicId = $actor->clinic_id;

        $data = Validator::make($input, [
            'expected_version' => ['required', 'integer', 'min:1'],
            'display_name' => ['sometimes', 'string', 'min:2', 'max:180'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254', Rule::unique('users', 'email')->where('clinic_id', $clinicId)->ignore($userId, 'id')],
            'active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array', 'min:1', 'max:4'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(StaffRoles::known())],
            'reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $this->bus->dispatch(
                new Command(UpdateStaffMemberHandler::COMMAND, Arr::except($data, ['expected_version']), $userId, (int) $data['expected_version']),
                CommandContext::direct($actor, AssignCorrelationId::current(request())),
            );
        } catch (UniqueConstraintViolationException) {
            // Course entre la validation `unique` (hors verrou) et l'écriture : même réponse qu'une
            // validation, sans message SQL.
            throw BusinessError::validation(['email' => ['La valeur du champ adresse électronique est déjà utilisée.']]);
        }

        return User::query()->where('clinic_id', $clinicId)->findOrFail($userId);
    }
}
