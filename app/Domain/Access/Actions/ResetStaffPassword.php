<?php

namespace App\Domain\Access\Actions;

use App\Application\Command;
use App\Application\CommandBus;
use App\Domain\Access\Handlers\ResetStaffPasswordHandler;
use App\Domain\Access\PasswordPolicy;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Http\Middleware\AssignCorrelationId;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Réinitialisation contrôlée par la gérante (fichier 09, sans e-mail tant qu'aucun fournisseur n'est
 * actif) : nouveau mot de passe transmis en personne, motif obligatoire, toutes les sessions du compte
 * fermées. Pour son propre compte, utiliser POST /auth/password (mot de passe actuel exigé).
 *
 * Le mot de passe d'une gérante ne se réinitialise pas par l'API : une autre gérante pourrait sinon se
 * connecter sous son nom et signer l'audit à sa place. La procédure de secours est la commande console
 * journalisée `eeo:staff:recover-manager`, sur le serveur.
 *
 * Cette classe valide la saisie et hache le mot de passe HORS transaction ; l'écriture est
 * ResetStaffPasswordHandler, exécuté par le chemin central de commandes (F07).
 */
final class ResetStaffPassword
{
    public function __construct(private readonly CommandBus $bus) {}

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function __invoke(User $actor, string $userId, #[\SensitiveParameter] array $input): void
    {
        $clinicId = $actor->clinic_id;

        if ($userId === $actor->id) {
            throw new BusinessError('SELF_RESET_FORBIDDEN', 'Pour votre propre compte, utilisez le changement de mot de passe.', 409);
        }

        $login = DB::table('users')->where('clinic_id', $clinicId)->where('id', $userId)->value('login');
        if ($login === null) {
            throw BusinessError::notFound();
        }

        $data = Validator::make($input, [
            'password' => [...PasswordPolicy::rules((string) $login), 'confirmed'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ])->validate();

        $this->bus->dispatch(
            new Command(ResetStaffPasswordHandler::COMMAND, [
                'password_hash' => Hash::make((string) $data['password']),
                'reason' => (string) $data['reason'],
            ], $userId, (int) $data['expected_version']),
            CommandContext::direct($actor, AssignCorrelationId::current(request())),
        );
    }
}
