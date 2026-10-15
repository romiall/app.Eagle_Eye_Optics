<?php

namespace Tests\Feature\Application;

use App\Application\CommandHandler;
use App\Application\CommandRegistry;
use App\Domain\Access\EffectivePermissions;
use App\Domain\Shared\CommandSource;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Garde-fous d'architecture du chemin central (F07, fiche : « Une mutation critique passe toujours par ce
 * chemin ; aucun effet externe dans la transaction »). Ils lisent le code source : une régression est détectée
 * à la revue d'un test, pas à la première panne en production.
 */
final class CentralPathGuardTest extends TestCase
{
    use MigratedDatabase;

    /**
     * Seuls fichiers de app/ autorisés à ouvrir une transaction : l'exécuteur commun (relance sur interblocage,
     * attente de verrou bornée) et l'outillage de schéma, dont les instructions DDL valident implicitement.
     */
    private const TRANSACTION_ALLOWED = [
        'app/Application/TransactionRunner.php',
        'app/Support/Schema/BaselineInstaller.php',
        'app/Console/Commands/AdoptBaseline.php',
    ];

    /**
     * Effets externes : jamais dans la transaction (ils passent par l'outbox, lue après le commit). Motifs de
     * code, sans les noms de méthodes du bus lui-même (`dispatch` y est une méthode, pas un appel de file).
     */
    private const EXTERNAL_EFFECTS = [
        '/\b(?:Http|Mail|Notification|Storage|Queue|Bus|Event|Broadcast)::/',
        '/(?<![\w>$])(?<!function )(?:event|dispatch|dispatch_sync|mail)\s*\(/',
        '/\b(?:curl_init|fsockopen|stream_socket_client)\b|file_get_contents\(\s*[\x27"]https?:/',
        '/Illuminate\\\\Support\\\\Facades\\\\(?:Http|Mail|Notification|Storage|Queue)\b/',
    ];

    public function test_aucune_transaction_n_est_ouverte_hors_de_l_executeur_commun(): void
    {
        $offenders = [];

        foreach ($this->sources(app_path()) as $relative => $source) {
            if (in_array($relative, self::TRANSACTION_ALLOWED, true)) {
                continue;
            }
            if (preg_match('/DB::(?:transaction|beginTransaction)\(|->(?:transaction|beginTransaction)\(/', $source) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'Ces fichiers ouvrent leur propre transaction : passer par TransactionRunner (ou par le CommandBus pour une mutation).');
    }

    public function test_la_couche_de_commande_ne_declenche_aucun_effet_externe(): void
    {
        $offenders = [];

        foreach ($this->sources(app_path('Application')) as $relative => $source) {
            // Les commentaires expliquent la règle sans l'enfreindre : on ne lit que le code.
            $code = (string) preg_replace('~/\*.*?\*/|(?<![:\'"])//[^\n]*~s', '', $source);
            foreach (self::EXTERNAL_EFFECTS as $pattern) {
                if (preg_match($pattern, $code, $match) === 1) {
                    $offenders[] = $relative.' : '.$match[0];
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_toute_action_de_commande_est_enregistree_et_declare_des_permissions_connues(): void
    {
        $registry = $this->app->make(CommandRegistry::class);
        $known = $this->app->make(EffectivePermissions::class)->known();

        $registered = [];
        foreach ($registry->names() as $name) {
            $definition = $registry->definition($name);
            $registered[] = $definition->handler;

            $handler = $this->app->make($definition->handler);
            $this->assertInstanceOf(CommandHandler::class, $handler);
            $this->assertNotSame([], $handler->permissions(), $name.' doit déclarer une permission.');
            foreach ($handler->permissions() as $permission) {
                $this->assertContains($permission, $known, $name.' : permission « '.$permission.' » absente du référentiel seedé.');
            }
            $this->assertNotSame([], $definition->sources, $name.' doit admettre au moins une source.');
        }

        // Tout gestionnaire écrit dans un dossier Handlers/ doit être enregistré : sinon aucune route ne l'atteint.
        $declared = [];
        foreach ($this->sources(app_path()) as $relative => $source) {
            if (! str_contains($relative, '/Handlers/')) {
                continue;
            }
            $class = 'App\\'.str_replace(['app/', '/', '.php'], ['', '\\', ''], $relative);
            if (is_subclass_of($class, CommandHandler::class)) {
                $declared[] = $class;
            }
        }

        $this->assertNotSame([], $declared, 'Les gestionnaires de comptes (F05) sont des CommandHandler.');
        $this->assertSame([], array_values(array_diff($declared, $registered)), 'Gestionnaire non enregistré dans le chemin central.');
    }

    public function test_une_commande_du_catalogue_f06_est_enregistree_comme_le_contrat_l_exige(): void
    {
        $index = json_decode((string) file_get_contents(base_path('contracts/commands/index.json')), true, 512, JSON_THROW_ON_ERROR)['const'];
        $registry = $this->app->make(CommandRegistry::class);

        foreach ($registry->names() as $name) {
            if (! isset($index[$name])) {
                continue;
            }

            $contract = $index[$name];
            $definition = $registry->definition($name);
            $handler = $this->app->make($definition->handler);

            $this->assertSame($contract['schema'], $definition->schema, $name.' : schéma de la charge.');
            $this->assertTrue($definition->idempotencyRequired, $name.' : toute commande du catalogue exige Idempotency-Key.');
            $this->assertContains($contract['permission'], $handler->permissions(), $name.' : permission du contrat.');
            $this->assertContains(CommandSource::Direct, $definition->sources, $name.' : route directe.');
            $this->assertContains(CommandSource::Sync, $definition->sources, $name.' : journal d\'un poste.');
            $this->assertFileExists(base_path('contracts/'.$contract['schema']));
        }

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, string> chemin relatif (séparateur /) → source
     */
    private function sources(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = 'app/'.str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
            $files[$relative] = (string) file_get_contents($file->getPathname());
        }

        ksort($files);

        return $files;
    }
}
