<?php

namespace App\Console\Commands;

use App\Domain\Access\Actions\RecoverManagerAccount;
use App\Domain\Shared\BusinessError;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Procédure de secours d'une gérante, sur le serveur seulement (runbook d'exploitation) : nouveau mot de
 * passe saisi masqué ou lu sur l'entrée standard (--password-stdin), jamais en argument ; motif
 * obligatoire ; confirmation explicite en mode interactif. Voir RecoverManagerAccount.
 */
#[Signature('eeo:staff:recover-manager
    {login : Identifiant de la gérante à rétablir}
    {--reason= : Motif de la procédure (obligatoire, journalisé)}
    {--password-stdin : Lire le nouveau mot de passe sur l\'entrée standard (mode non interactif)}')]
#[Description('Rétablit l\'accès d\'une gérante (mot de passe, activation, profil) : procédure de secours journalisée')]
final class RecoverManager extends Command
{
    public function handle(RecoverManagerAccount $recover): int
    {
        $interactive = $this->input->isInteractive() && ! $this->option('password-stdin');
        $login = $this->argument('login');
        if (! is_string($login) || $login === '') {
            $this->components->error('Identifiant de la gérante requis.');

            return self::FAILURE;
        }

        $reason = $this->option('reason');
        if ((! is_string($reason) || trim($reason) === '') && $interactive) {
            $reason = text(label: 'Motif de la procédure de secours (journalisé)', required: true);
        }
        if (! is_string($reason) || trim($reason) === '') {
            $this->components->error('Le motif (--reason) est obligatoire.');

            return self::FAILURE;
        }

        $password = $this->readPassword($interactive);
        if ($password === null) {
            $this->components->error('Nouveau mot de passe absent ou non confirmé.');

            return self::FAILURE;
        }

        if ($interactive && ! confirm(label: 'Rétablir l\'accès de '.$login.' : nouveau mot de passe, compte actif, profil Gérante, sessions fermées ?', default: false)) {
            $this->components->warn('Procédure abandonnée : aucune modification.');

            return self::FAILURE;
        }

        try {
            $result = $recover($login, $password, $reason);
        } catch (ValidationException $exception) {
            $this->components->error('Procédure refusée : informations invalides.');
            foreach ($exception->errors() as $field => $messages) {
                foreach ((array) $messages as $message) {
                    $this->line('  - '.$field.' : '.$message);
                }
            }

            return self::FAILURE;
        } catch (BusinessError $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Accès de la gérante rétabli ; toutes ses sessions ont été fermées.');
        $this->components->twoColumnDetail('Compte réactivé', $result['reactivated'] ? 'oui' : 'non (déjà actif)');
        $this->components->twoColumnDetail('Profil Gérante rétabli', $result['manager_role_restored'] ? 'oui' : 'non (déjà actif)');
        $this->components->twoColumnDetail('Dérogations users.manage supprimées', (string) $result['overrides_removed']);

        return self::SUCCESS;
    }

    private function readPassword(bool $interactive): ?string
    {
        if ($this->option('password-stdin')) {
            $line = fgets(STDIN);

            return $line === false ? null : rtrim($line, "\r\n");
        }

        if (! $interactive) {
            return null;
        }

        $first = password(label: 'Nouveau mot de passe (12 caractères minimum, lettres et chiffres)', required: true);
        $second = password(label: 'Confirmer le mot de passe', required: true);

        return $first === $second ? $first : null;
    }
}
