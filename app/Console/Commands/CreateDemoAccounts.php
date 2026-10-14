<?php

namespace App\Console\Commands;

use App\Domain\Access\AuditRecorder;
use App\Domain\Access\StaffRoles;
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
 * Refusé en production. Mots de passe aléatoires générés à chaque création ou réinitialisation, affichés
 * une seule fois ou écrits dans un fichier privé (mode 0600) : jamais de mot de passe universel ni versionné.
 * Crée un cabinet de démonstration marqué « DONNÉES FICTIVES » si aucun cabinet n'existe.
 */
#[Signature('eeo:demo:accounts
    {--reset-passwords : Régénérer les mots de passe des comptes de démonstration existants}
    {--output= : Écrire les identifiants en JSON dans ce fichier (mode 0600) au lieu de les afficher}')]
#[Description('Crée les comptes de démonstration fictifs des quatre profils (refusé en production)')]
final class CreateDemoAccounts extends Command
{
    /** @var array<string, array{0: string, 1: string}> login => [nom affiché, profil] */
    private const ACCOUNTS = [
        'demo.gerante' => ['Gérante (démonstration)', 'MANAGER'],
        'demo.accueil' => ['Accueil (démonstration)', 'RECEPTION'],
        'demo.professionnel' => ['Professionnel (démonstration)', 'CLINICIAN'],
        'demo.caisse' => ['Caisse (démonstration)', 'CASHIER'],
    ];

    public function handle(AuditRecorder $audit): int
    {
        if (app()->environment('production')) {
            $this->components->error('Comptes de démonstration refusés en production.');

            return self::FAILURE;
        }

        $credentials = DB::transaction(function () use ($audit): array {
            $clinicId = $this->demoClinic();
            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
            $credentials = [];
            $managerId = null;

            foreach (self::ACCOUNTS as $login => [$name, $role]) {
                $existing = DB::table('users')->where('clinic_id', $clinicId)->where('login', $login)->first(['id', 'version']);
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
                    $audit->record($clinicId, null, 'staff.created', 'users', $id, 'Compte de démonstration fictif', ['login' => $login, 'roles' => [$role]], 'Commande de démonstration');
                } else {
                    $id = (string) $existing->id;
                    $managerId ??= $id;
                    if ($this->option('reset-passwords')) {
                        $password = self::randomPassword();
                        DB::table('users')->where('clinic_id', $clinicId)->where('id', $id)->update([
                            'password_hash' => Hash::make($password), 'active' => true,
                            'version' => (int) $existing->version + 1, 'updated_at' => $now,
                        ]);
                        $audit->record($clinicId, null, 'staff.password_reset', 'users', $id, 'Compte de démonstration fictif', [], 'Commande de démonstration');
                    }
                }

                $credentials[] = ['login' => $login, 'role' => $role, 'password' => $password];
            }

            return $credentials;
        });

        $output = $this->option('output');
        if (is_string($output) && $output !== '') {
            file_put_contents($output, json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            chmod($output, 0600);
            $this->components->info('Identifiants de démonstration écrits dans '.$output.' (mode 0600).');

            return self::SUCCESS;
        }

        $this->table(['Identifiant', 'Profil', 'Mot de passe (affiché une seule fois)'], array_map(
            static fn (array $c): array => [$c['login'], $c['role'], $c['password'] ?? '(inchangé : --reset-passwords pour le régénérer)'],
            $credentials,
        ));

        return self::SUCCESS;
    }

    private function demoClinic(): string
    {
        $existing = DB::table('clinics')->orderBy('id')->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $id = Ulid::generate();
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        DB::table('clinics')->insert(['id' => $id, 'name' => 'CABINET DE DÉMONSTRATION — DONNÉES FICTIVES', 'created_at' => $now, 'updated_at' => $now]);

        return $id;
    }

    private static function randomPassword(): string
    {
        // 20 caractères aléatoires + un chiffre et une lettre garantis pour la politique de mot de passe.
        return Str::password(20, symbols: false).'7k';
    }
}
