<?php

namespace App\Domain\Access\Actions;

use App\Application\Command;
use App\Application\CommandBus;
use App\Domain\Access\Handlers\CreateStaffMemberHandler;
use App\Domain\Access\PasswordPolicy;
use App\Domain\Access\StaffRoles;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use App\Http\Middleware\AssignCorrelationId;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Création d'un compte nominatif par une gérante (aucune inscription publique, fichier 09). Le mot de
 * passe initial est fixé par la gérante et transmis en personne ; le compte le change ensuite par
 * POST /auth/password. Profils parmi les quatre des sources, au moins un.
 *
 * L'écriture passe par le chemin central de commandes (F07) : droits relus sous le verrou du cabinet,
 * transaction rejouée sur interblocage, audit écrit au commit. Cette classe valide la saisie et hache le mot
 * de passe HORS transaction : le verrou du cabinet n'est jamais tenu pendant un calcul bcrypt.
 */
final class CreateStaffMember
{
    public const LOGIN_PATTERN = '/^[a-z0-9][a-z0-9._-]{2,63}\z/';

    public function __construct(private readonly CommandBus $bus) {}

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function __invoke(User $actor, #[\SensitiveParameter] array $input): User
    {
        $clinicId = $actor->clinic_id;
        $login = is_string($input['login'] ?? null) ? PasswordPolicy::normalizeLogin($input['login']) : null;

        $data = Validator::make(['login' => $login] + $input, [
            'login' => ['required', 'string', 'regex:'.self::LOGIN_PATTERN, Rule::unique('users', 'login')->where('clinic_id', $clinicId)],
            'display_name' => ['required', 'string', 'min:2', 'max:180'],
            'email' => ['nullable', 'email', 'max:254', Rule::unique('users', 'email')->where('clinic_id', $clinicId)],
            'password' => PasswordPolicy::rules($login),
            'roles' => ['required', 'array', 'min:1', 'max:4'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(StaffRoles::known())],
        ])->validate();

        $userId = Ulid::generate();

        try {
            $this->bus->dispatch(
                new Command(CreateStaffMemberHandler::COMMAND, [
                    'login' => (string) $data['login'],
                    'display_name' => (string) $data['display_name'],
                    'email' => isset($data['email']) ? (string) $data['email'] : null,
                    'password_hash' => Hash::make((string) $data['password']),
                    'roles' => array_values(array_map('strval', (array) $data['roles'])),
                ], $userId),
                CommandContext::direct($actor, AssignCorrelationId::current(request())),
            );
        } catch (UniqueConstraintViolationException) {
            // Double soumission ou course entre la validation `unique` (hors verrou) et l'insertion : même
            // réponse qu'une validation, jamais un 500 ni le message SQL (masqué de toute façon dans les
            // journaux par mask_bindings_in_exception_messages).
            throw BusinessError::validation(['login' => ['Cet identifiant ou cette adresse électronique est déjà utilisé.']]);
        }

        return User::query()->where('clinic_id', $clinicId)->findOrFail($userId);
    }
}
