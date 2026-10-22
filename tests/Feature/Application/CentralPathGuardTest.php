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
     * attente de verrou bornée) et l'outillage de schéma, dont les instructions DDL valident implicitement. Le
     * diagnostic d'hébergement (`eeo:preflight`) crée puis supprime des objets temporaires pour éprouver les droits
     * DDL de la base : il n'écrit aucune donnée de l'application et n'ouvre aucune transaction.
     */
    private const TRANSACTION_ALLOWED = [
        'app/Application/TransactionRunner.php',
        'app/Support/Schema/BaselineInstaller.php',
        'app/Console/Commands/AdoptBaseline.php',
        'app/Support/Deployment/PreflightChecks.php',
    ];

    /**
     * Effets externes : jamais dans la transaction (ils passent par l'outbox, lue après le commit). Motifs de
     * code, sans les noms de méthodes du bus lui-même (`dispatch` y est une méthode, pas un appel de file) :
     * façades et classes de messagerie, réseau, fichiers, processus et files ; fonctions PHP de réseau, de fichier
     * et de processus (appel de fonction, pas méthode d'objet) ; services résolus par leur nom dans le conteneur.
     */
    private const EXTERNAL_EFFECTS = [
        '/\b(?:Http|Mail|Notification|Storage|Queue|Bus|Event|Broadcast|File|Process|Artisan|Redis)::/',
        '/(?<![\w>$:])(?<!function )(?:event|dispatch|dispatch_sync|broadcast|mail)\s*\(/',
        '/(?<![\w>$:])(?<!function )(?:curl_\w+|fsockopen|pfsockopen|stream_socket_client|socket_create|socket_connect|exec|shell_exec|system|passthru|proc_open|popen|fopen|fwrite|fputs|file_put_contents|unlink|rename|copy|mkdir|rmdir|touch|tempnam|move_uploaded_file)\s*\(/',
        '/file_get_contents\(\s*[\x27"](?:https?|ftp):/',
        '/->notify(?:Now)?\(/',
        '/\b(?:app|resolve)\(\s*[\x27"](?:mailer|mail\.manager|queue|events|filesystem|files|redis|http|broadcaster)[\x27"]/',
        '/\b(?:GuzzleHttp|Symfony\\\\Component\\\\Process)\\\\|Illuminate\\\\Http\\\\Client\\\\|Illuminate\\\\Support\\\\Facades\\\\(?:Http|Mail|Notification|Storage|Queue|Bus|Event|Broadcast|File|Process|Artisan|Redis)\b/',
    ];

    /**
     * Contrôle de transaction hors de l'exécuteur : ouverture, validation ou annulation par l'API de Laravel, par le
     * PDO brut ou par du SQL écrit à la main.
     */
    private const TRANSACTION_CONTROL = [
        '/DB::(?:transaction|beginTransaction|commit|rollBack)\(|->(?:transaction|beginTransaction|commit|rollBack)\(/',
        '/->(?:getPdo|unprepared)\(/',
        '/[\x27"]\s*(?:START\s+TRANSACTION|BEGIN\b|COMMIT\b|ROLLBACK\b|SAVEPOINT\b|RELEASE\s+SAVEPOINT|SET\s+(?:SESSION\s+)?autocommit)/i',
    ];

    public function test_aucune_transaction_n_est_ouverte_ni_conclue_hors_de_l_executeur_commun(): void
    {
        $offenders = [];

        foreach ($this->sources(app_path()) as $relative => $source) {
            if (in_array($relative, self::TRANSACTION_ALLOWED, true)) {
                continue;
            }
            $code = self::withoutComments($source);
            foreach (self::TRANSACTION_CONTROL as $pattern) {
                if (preg_match($pattern, $code, $match) === 1) {
                    $offenders[] = $relative.' : '.trim($match[0]);
                }
            }
        }

        $this->assertSame([], $offenders, 'Ces fichiers contrôlent eux-mêmes une transaction : passer par TransactionRunner (ou par le CommandBus pour une mutation).');
    }

    public function test_ni_le_chemin_central_ni_les_actions_du_domaine_ne_declenchent_d_effet_externe(): void
    {
        $offenders = [];

        // Tout ce qui peut s'exécuter dans la transaction : le chemin central ET le domaine (actions, règles,
        // services appelés sous verrou), pas seulement app/Application.
        foreach ([...$this->sources(app_path('Application')), ...$this->sources(app_path('Domain'))] as $relative => $source) {
            $code = self::withoutComments($source);
            foreach (self::EXTERNAL_EFFECTS as $pattern) {
                if (preg_match($pattern, $code, $match) === 1) {
                    $offenders[] = $relative.' : '.trim($match[0]);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_les_motifs_d_effet_externe_reconnaissent_les_formes_courantes(): void
    {
        // Contrôle négatif du garde-fou lui-même : chaque forme relevée par la revue doit être détectée.
        $samples = [
            'file_put_contents($p, $x);', 'File::put($p, $x);', 'new \GuzzleHttp\Client();', "app('mailer')->send(\$m);",
            "fopen('https://exemple.invalid', 'r');", 'Process::run($c);', 'Http::post($u);', '$user->notify($n);',
            'event(new Paid());', 'exec($c);', 'Mail::to($a);', 'curl_exec($h);',
        ];
        foreach ($samples as $sample) {
            $matched = array_filter(self::EXTERNAL_EFFECTS, static fn (string $pattern): bool => preg_match($pattern, $sample) === 1);
            $this->assertNotSame([], $matched, 'Non détecté : '.$sample);
        }
        foreach (["DB::statement('START TRANSACTION');", '$pdo = DB::connection()->getPdo();', "DB::unprepared('COMMIT');", 'DB::commit();'] as $sample) {
            $matched = array_filter(self::TRANSACTION_CONTROL, static fn (string $pattern): bool => preg_match($pattern, $sample) === 1);
            $this->assertNotSame([], $matched, 'Non détecté : '.$sample);
        }
        // Et pas de faux positif sur les méthodes du bus ou d'un objet.
        foreach (['$this->bus->dispatch($c, $x);', '$query->update([]);', '$locks->clinic();'] as $sample) {
            $matched = array_filter(self::EXTERNAL_EFFECTS, static fn (string $pattern): bool => preg_match($pattern, $sample) === 1);
            $this->assertSame([], $matched, 'Faux positif : '.$sample);
        }
    }

    public function test_aucun_controleur_n_ecrit_en_base(): void
    {
        // Une écriture hors du chemin central serait validée seule (autocommit), sans verrou ni audit.
        $offenders = [];
        foreach ($this->sources(app_path('Http')) as $relative => $source) {
            $code = self::withoutComments($source);
            if (preg_match('/DB::table\([^;]*->(?:insert\w*|update\w*|upsert|delete|truncate|increment|decrement)\(|->(?:save|forceDelete|push)\(\)|::(?:create|insert|upsert|updateOrCreate|firstOrCreate)\(/s', $code, $match) === 1) {
                $offenders[] = $relative.' : '.trim($match[0]);
            }
        }

        $this->assertSame([], $offenders, 'Un contrôleur ne modifie rien lui-même : il confie la mutation au CommandBus.');
    }

    private static function withoutComments(string $source): string
    {
        // Les commentaires expliquent la règle sans l'enfreindre : on ne lit que le code.
        return (string) preg_replace('~/\*.*?\*/|(?<![:\'"])//[^\n]*~s', '', $source);
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

            // Le corps de la route directe est validé contre le DirectRequest du schéma de la commande.
            $schema = json_decode((string) file_get_contents(base_path('contracts/'.$contract['schema'])), true, 512, JSON_THROW_ON_ERROR);
            if (isset($schema['$defs']['DirectRequest'])) {
                $this->assertSame($contract['schema'].'#/$defs/DirectRequest', $definition->directSchema, $name.' : schéma du corps de la route directe.');
                $this->assertContains('expected_version', $schema['$defs']['DirectRequest']['required'], $name.' : version attendue dans le corps direct.');
            }
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
