<?php

namespace App\Console\Commands;

use App\Domain\Access\Actions\SetupClinic as SetupClinicAction;
use App\Domain\Shared\BusinessError;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Mise en service initiale contrôlée (fichier 23) : cabinet puis première gérante. Saisie interactive ;
 * en mode non interactif, le mot de passe est lu sur l'entrée standard (--password-stdin), jamais en
 * argument de ligne de commande (visible dans l'historique et la liste des processus).
 */
#[Signature('eeo:setup-clinic
    {--clinic-name= : Nom du cabinet}
    {--manager-name= : Nom affiché de la première gérante}
    {--login= : Identifiant de connexion de la gérante}
    {--email= : Adresse électronique (facultative)}
    {--password-stdin : Lire le mot de passe sur l\'entrée standard (mode non interactif)}')]
#[Description('Crée le cabinet et le compte de la première gérante (une seule fois, sans mot de passe par défaut)')]
final class SetupClinic extends Command
{
    public function handle(SetupClinicAction $setup): int
    {
        $interactive = $this->input->isInteractive() && ! $this->option('password-stdin');

        $input = [
            'clinic_name' => $this->value('clinic-name', 'Nom du cabinet', $interactive),
            'manager_name' => $this->value('manager-name', 'Nom affiché de la gérante', $interactive),
            'login' => $this->value('login', 'Identifiant de connexion (minuscules, chiffres, . _ -)', $interactive),
            'email' => $this->option('email') ?: null,
            'password' => $this->secret_($interactive),
        ];

        try {
            $result = $setup($input);
        } catch (ValidationException $exception) {
            $this->components->error('Mise en service refusée : informations invalides.');
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

        $this->components->info('Cabinet et première gérante créés.');
        $this->components->twoColumnDetail('Identifiant du cabinet (EEO_CLINIC_ID, facultatif)', $result['clinic_id']);
        $this->components->twoColumnDetail('Compte gérante', (string) $input['login']);
        $this->line('Aucun mot de passe par défaut n\'existe : conservez celui saisi hors du poste.');

        return self::SUCCESS;
    }

    private function value(string $option, string $label, bool $interactive): ?string
    {
        $value = $this->option($option);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $interactive ? text(label: $label, required: true) : null;
    }

    private function secret_(bool $interactive): ?string
    {
        if ($this->option('password-stdin')) {
            $line = fgets(STDIN);

            return $line === false ? null : rtrim($line, "\r\n");
        }

        if (! $interactive) {
            return null;
        }

        $first = password(label: 'Mot de passe de la gérante (12 caractères minimum, lettres et chiffres)', required: true);
        $second = password(label: 'Confirmer le mot de passe', required: true);

        if ($first !== $second) {
            $this->components->error('Les deux saisies du mot de passe diffèrent.');

            return null;
        }

        return $first;
    }
}
