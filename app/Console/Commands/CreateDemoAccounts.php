<?php

namespace App\Console\Commands;

use App\Domain\Access\AuditRecorder;
use App\Domain\Access\StaffRoles;
use App\Domain\Access\StaffSessions;
use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Comptes de démonstration fictifs pour les quatre profils (F05 : « quatre profils démontrables »).
 *
 * Autorisée seulement dans les environnements de développement, de test et de démonstration (liste
 * fermée : une faute de frappe sur APP_ENV ne l'ouvre pas), et seulement dans le cabinet de démonstration
 * « DONNÉES FICTIVES » : refusée dès qu'un autre cabinet ou un compte non fictif existe, pour ne jamais
 * ajouter de gérante de démonstration à un cabinet réel.
 *
 * Mots de passe aléatoires générés à chaque création ou réinitialisation, affichés une seule fois ou écrits
 * dans un fichier privé créé en mode 0600 (jamais un lien symbolique suivi) : jamais de mot de passe
 * universel ni versionné. Un compte de démonstration désactivé n'est jamais réactivé par cette commande.
 */
#[Signature('eeo:demo:accounts
    {--reset-passwords : Régénérer les mots de passe des comptes de démonstration actifs}
    {--output= : Écrire les identifiants en JSON dans ce fichier (mode 0600) au lieu de les afficher}')]
#[Description('Crée les comptes de démonstration fictifs des quatre profils (cabinet de démonstration seulement)')]
final class CreateDemoAccounts extends Command
{
    public const DEMO_CLINIC_NAME = 'CABINET DE DÉMONSTRATION — DONNÉES FICTIVES';

    /** Environnements admis (liste fermée). */
    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'e2e', 'demo'];

    /** @var array<string, array{0: string, 1: string}> login => [nom affiché, profil] */
    private const ACCOUNTS = [
        'demo.gerante' => ['Gérante (démonstration)', 'MANAGER'],
        'demo.accueil' => ['Accueil (démonstration)', 'RECEPTION'],
        'demo.professionnel' => ['Professionnel (démonstration)', 'CLINICIAN'],
        'demo.caisse' => ['Caisse (démonstration)', 'CASHIER'],
    ];

    private const ACTOR = 'Commande de démonstration';

    public function handle(AuditRecorder $audit, StaffSessions $sessions): int
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->components->error('Comptes de démonstration refusés dans cet environnement ('.app()->environment().') : admis seulement en '.implode(', ', self::ALLOWED_ENVIRONMENTS).'. Jamais en production.');

            return self::FAILURE;
        }

        $output = $this->option('output');
        if (is_string($output) && $output !== '' && (is_link($output) || is_dir($output))) {
            $this->components->error('Fichier de sortie refusé : lien symbolique ou répertoire.');

            return self::FAILURE;
        }

        try {
            $credentials = DB::transaction(fn (): array => $this->provision($audit, $sessions));
        } catch (\DomainException $refused) {
            // Refus métier : la transaction est annulée, aucune écriture.
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        if (is_string($output) && $output !== '') {
            self::writePrivateFile($output, json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            $this->components->info('Identifiants de démonstration écrits dans '.$output.' (mode 0600).');

            return self::SUCCESS;
        }

        $this->table(['Identifiant', 'Profil', 'Mot de passe (affiché une seule fois)'], array_map(
            static fn (array $c): array => [$c['login'], $c['role'], $c['password'] ?? '(inchangé : --reset-passwords pour le régénérer)'],
            $credentials,
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<array{login: string, role: string, password: ?string}>
     */
    private function provision(AuditRecorder $audit, StaffSessions $sessions): array
    {
        $clinicId = $this->demoClinic();
        StaffRoles::lockClinic($clinicId);

        $others = DB::table('users')->where('clinic_id', $clinicId)->whereNotIn('login', array_keys(self::ACCOUNTS))->count();
        if ($others > 0) {
            throw new \DomainException('Le cabinet de démonstration contient des comptes non fictifs : commande refusée.');
        }

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $credentials = [];
        $managerId = null;

        foreach (self::ACCOUNTS as $login => [$name, $role]) {
            $existing = DB::table('users')->where('clinic_id', $clinicId)->where('login', $login)->first(['id', 'version', 'active']);
            $password = null;

            if ($existing === null) {
                $password = self::randomPassword();
                $id = Ulid::generate();
                DB::table('users')->insert([
                    'id' => $id, 'clinic_id' => $clinicId, 'display_name' => $name, 'login' => $login,
                    'password_hash' => Hash::make($password), 'active' => true, 'version' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $managerId ??= $id;
                StaffRoles::sync($clinicId, $id, [$role], $managerId);
                $audit->record($clinicId, null, 'staff.created', 'users', $id, 'Compte de démonstration fictif', ['login' => $login, 'roles' => [$role]], self::ACTOR);
            } else {
                $id = (string) $existing->id;
                $managerId ??= $id;
                if ($this->option('reset-passwords') && ! (bool) $existing->active) {
                    $this->components->warn($login.' est désactivé : non réactivé, mot de passe inchangé.');
                } elseif ($this->option('reset-passwords')) {
                    $password = self::randomPassword();
                    DB::table('users')->where('clinic_id', $clinicId)->where('id', $id)->update([
                        'password_hash' => Hash::make($password),
                        'version' => (int) $existing->version + 1, 'updated_at' => $now,
                    ]);
                    $audit->record($clinicId, null, 'staff.password_reset', 'users', $id, 'Compte de démonstration fictif : mot de passe régénéré', [], self::ACTOR);
                    $sessions->closeAll($id);
                }
            }

            $credentials[] = ['login' => $login, 'role' => $role, 'password' => $password];
        }

        return $credentials;
    }

    private function demoClinic(): string
    {
        $clinics = DB::table('clinics')->orderBy('id')->get(['id', 'name']);
        if ($clinics->count() > 1 || ($clinics->count() === 1 && $clinics->first()?->name !== self::DEMO_CLINIC_NAME)) {
            throw new \DomainException('Un cabinet réel existe : les comptes de démonstration ne sont créés que dans le cabinet « '.self::DEMO_CLINIC_NAME.' ».');
        }

        $existing = $clinics->first();
        if ($existing !== null) {
            return (string) $existing->id;
        }

        $id = Ulid::generate();
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        DB::table('clinics')->insert(['id' => $id, 'name' => self::DEMO_CLINIC_NAME, 'created_at' => $now, 'updated_at' => $now]);

        return $id;
    }

    /**
     * Écriture privée : fichier temporaire créé exclusivement (mode 0600 dès la création), puis renommé
     * sur la cible. rename() remplace un éventuel lien symbolique au lieu de le suivre.
     */
    private static function writePrivateFile(string $path, string $contents): void
    {
        $temporary = dirname($path).'/.'.basename($path).'.'.bin2hex(random_bytes(6)).'.tmp';
        $previousMask = umask(0077);

        try {
            $handle = fopen($temporary, 'x');
            if ($handle === false) {
                throw new \RuntimeException('Création du fichier de sortie impossible.');
            }
            fwrite($handle, $contents);
            fclose($handle);
            chmod($temporary, 0600);
            if (! rename($temporary, $path)) {
                throw new \RuntimeException('Écriture du fichier de sortie impossible.');
            }
        } finally {
            umask($previousMask);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function randomPassword(): string
    {
        // 20 caractères aléatoires + un chiffre et une lettre garantis pour la politique de mot de passe.
        return Str::password(20, symbols: false).'7k';
    }
}
