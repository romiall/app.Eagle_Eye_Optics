<?php

namespace Tests\Feature\Database;

use App\Support\Testing\BlockDestructiveDatabaseCommands;
use App\Support\Testing\DestructiveDatabaseOperationRefused;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Preuve qu'aucune commande destructive ne passe hors d'une base de test isolée.
 *
 * Les scénarios « refusés » utilisent SQLite en mémoire : si la garde échouait, la commande ne
 * détruirait qu'une base volatile. Aucun scénario de cette classe n'exécute réellement une commande
 * destructive contre MySQL ; la preuve que down() ne s'exécute pas (la table survit) est dans
 * MigrateRollbackGuardTest, sur une base jetable « *_test ».
 */
final class DestructiveCommandsBlockedTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function destructiveCommands(): array
    {
        return [
            'migrate:fresh' => ['migrate:fresh'],
            'migrate:refresh' => ['migrate:refresh'],
            'migrate:reset' => ['migrate:reset'],
            'migrate:rollback' => ['migrate:rollback'],
            'db:wipe' => ['db:wipe'],
        ];
    }

    public function test_la_liste_des_commandes_bloquees_est_exactement_celle_documentee(): void
    {
        // Tripwire : retirer ou ajouter une commande doit être un choix explicite (testing.md).
        $this->assertSame(
            ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe'],
            BlockDestructiveDatabaseCommands::BLOCKED_COMMANDS,
        );
    }

    /**
     * Filet contre l'oubli : c'est ainsi que migrate:rollback était passé entre les mailles. Toute
     * commande des espaces « migrate » et « db » enregistrée par Laravel doit être classée
     * explicitement (gardée, non destructive ou limite documentée). Une commande apparaissant avec
     * une mise à jour du framework fait échouer ce test au lieu de passer inaperçue.
     */
    public function test_chaque_commande_migrate_et_db_est_classee_explicitement(): void
    {
        $nonDestructive = ['migrate', 'migrate:install', 'migrate:status', 'db:monitor', 'db:seed', 'db:show', 'db:table'];
        $documentedLimit = ['db']; // client SQL interactif : voir « Limites » dans testing.md.

        $registered = array_keys(Artisan::all());
        $schemaCommands = array_values(array_filter(
            $registered,
            static fn (string $name): bool => preg_match('/^(migrate|db)(:|$)/', $name) === 1,
        ));

        $unclassified = array_values(array_diff(
            $schemaCommands,
            BlockDestructiveDatabaseCommands::BLOCKED_COMMANDS,
            $nonDestructive,
            $documentedLimit,
        ));
        sort($unclassified);
        $this->assertSame([], $unclassified, 'Commande migrate:* ou db:* non classée : décider si elle doit être gardée.');

        foreach (BlockDestructiveDatabaseCommands::BLOCKED_COMMANDS as $command) {
            $this->assertContains($command, $registered, "La commande gardée « {$command} » n'existe plus dans Laravel : revoir la garde.");
        }
    }

    private function simulateProductionOnVolatileDatabase(): void
    {
        $this->app->instance('env', 'production');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    }

    #[DataProvider('destructiveCommands')]
    public function test_la_commande_destructive_est_refusee_en_production(string $command): void
    {
        $this->simulateProductionOnVolatileDatabase();

        $this->expectException(DestructiveDatabaseOperationRefused::class);
        $this->expectExceptionMessage($command);

        Artisan::call($command, ['--force' => true]);
    }

    #[DataProvider('destructiveCommands')]
    public function test_la_commande_destructive_est_refusee_sur_une_base_non_test_meme_en_environnement_testing(string $command): void
    {
        // Identifiants invalides par prudence : si la garde échouait, la connexion serait refusée.
        config([
            'database.connections.mysql.database' => 'eagle_eye_optics',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.username' => 'utilisateur_inexistant',
            'database.connections.mysql.password' => 'sans-objet',
        ]);

        $this->expectException(DestructiveDatabaseOperationRefused::class);
        $this->expectExceptionMessage('ne se termine pas par « _test »');

        Artisan::call($command, ['--force' => true]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function realCliEnvironments(): array
    {
        return [
            'production' => ['production', 'migrate:fresh'],
            'testing mais base non isolée' => ['testing', 'migrate:fresh'],
            'testing, db:wipe' => ['testing', 'db:wipe'],
            'local' => ['local', 'migrate:refresh'],
            'production, migrate:rollback' => ['production', 'migrate:rollback'],
            'testing mais base non isolée, migrate:rollback' => ['testing', 'migrate:rollback'],
            'local, migrate:rollback' => ['local', 'migrate:rollback'],
            'production, migrate:reset' => ['production', 'migrate:reset'],
            // Symfony résout les abréviations : l'écouteur reçoit le nom canonique de la commande.
            'production, abréviation migrate:rollb' => ['production', 'migrate:rollb'],
            'production, abréviation mi:rollback' => ['production', 'mi:rollback'],
        ];
    }

    /**
     * Preuve en boîte noire : un vrai processus « php artisan » (sans la couche de test de Laravel).
     * Les identifiants fournis sont invalides : même si la garde échouait, aucune base n'est touchée.
     */
    #[DataProvider('realCliEnvironments')]
    public function test_un_vrai_processus_artisan_refuse_la_commande_destructive(string $environment, string $command): void
    {
        $process = new Process([PHP_BINARY, 'artisan', $command, '--force'], base_path(), [
            'APP_ENV' => $environment,
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_DATABASE' => 'eagle_eye_optics',
            'DB_USERNAME' => 'utilisateur_inexistant',
            'DB_PASSWORD' => 'sans-objet',
        ]);
        $process->run();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('Opération destructive refusée', $process->getOutput().$process->getErrorOutput());
        $this->assertStringNotContainsString('Access denied', $process->getOutput().$process->getErrorOutput(), 'La commande ne doit même pas tenter de se connecter.');
    }

    public function test_l_option_database_vise_la_connexion_demandee_et_non_la_connexion_par_defaut(): void
    {
        // La connexion par défaut est saine, mais la commande vise une base distante : refus.
        config([
            'database.connections.autre' => [
                'driver' => 'mysql',
                'host' => 'db.example.invalid',
                'database' => 'eeo_test',
            ],
        ]);

        $this->expectException(DestructiveDatabaseOperationRefused::class);
        $this->expectExceptionMessage('db.example.invalid');

        Artisan::call('migrate:fresh', ['--database' => 'autre', '--force' => true]);
    }

    public function test_la_commande_de_reinitialisation_dediee_refuse_aussi_en_production(): void
    {
        $this->simulateProductionOnVolatileDatabase();

        $status = Artisan::call('eeo:test-db:reset');
        $output = Artisan::output();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Remise à zéro refusée', $output);
        $this->assertStringContainsString('production', $output);
    }

    #[DataProvider('destructiveCommands')]
    public function test_l_ecouteur_laisse_passer_la_base_de_test_isolee(string $command): void
    {
        // On ne dispatche que l'événement : la commande elle-même n'est pas exécutée.
        $listener = new BlockDestructiveDatabaseCommands;

        $listener->handle(new CommandStarting($command, new ArrayInput([]), new NullOutput));

        $this->addToAssertionCount(1);
    }

    public function test_les_commandes_non_destructives_ne_sont_jamais_bloquees(): void
    {
        $this->simulateProductionOnVolatileDatabase();

        foreach (['migrate', 'migrate:install', 'migrate:status', 'db:seed', 'db:show', 'db:table', 'list', 'queue:work', 'schedule:run', 'about'] as $command) {
            event(new CommandStarting($command, new ArrayInput([]), new NullOutput));
        }

        $this->addToAssertionCount(1);
    }

    public function test_l_ecouteur_est_branche_sur_les_evenements_de_commande(): void
    {
        $this->assertTrue($this->app['events']->hasListeners(CommandStarting::class));
    }
}
