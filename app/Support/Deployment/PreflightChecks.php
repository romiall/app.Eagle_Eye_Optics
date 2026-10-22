<?php

namespace App\Support\Deployment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Contrôles d'hébergement de `php artisan eeo:preflight` (grille d'audit : docs/implementation/hosting-audit.md).
 *
 * Trois familles : le serveur PHP (version, extensions, réglages), la configuration de l'application (production
 * seulement : débogage, HTTPS, cookie sécurisé, domaine de session) et la base MySQL (version, MariaDB refusée
 * tant que sa compatibilité n'est pas validée, droits réels vérifiés en créant puis supprimant des objets
 * temporaires, dont une clé étrangère, une contrainte CHECK, une colonne générée, une vue et un TRIGGER).
 *
 * Les objets temporaires portent le préfixe `eeo_preflight_` et un suffixe aléatoire ; ils sont supprimés dans tous
 * les cas. Aucune table de l'application n'est lue ni modifiée.
 */
final class PreflightChecks
{
    /** Extensions sans lesquelles l'application ne fonctionne pas. */
    private const REQUIRED_EXTENSIONS = [
        'ctype', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'intl', 'json', 'libxml', 'mbstring',
        'openssl', 'pcre', 'pdo_mysql', 'session', 'tokenizer', 'xml',
    ];

    /** Extensions utiles (documents, échanges) dont l'absence est signalée sans bloquer. */
    private const RECOMMENDED_EXTENSIONS = ['curl', 'sodium', 'zip'];

    /**
     * @return list<PreflightResult>
     */
    public function run(bool $withDatabase = true): array
    {
        $results = [...$this->php(), ...$this->configuration(), ...$this->filesystem()];
        if ($withDatabase) {
            $results = [...$results, ...$this->database()];
        }

        return $results;
    }

    /**
     * @return list<PreflightResult>
     */
    private function php(): array
    {
        $results = [];

        $results[] = version_compare(PHP_VERSION, '8.3.0', '>=')
            ? (version_compare(PHP_VERSION, '8.4.0', '>=')
                ? PreflightResult::ok('PHP '.PHP_VERSION, 'version cible')
                : PreflightResult::ok('PHP '.PHP_VERSION, 'accepté (8.4 recommandé)'))
            : PreflightResult::blocking('PHP '.PHP_VERSION, 'Laravel 13 exige PHP 8.3 au minimum : changez la version PHP du site.');

        $results[] = PHP_INT_SIZE === 8
            ? PreflightResult::ok('PHP 64 bits', 'montants entiers sûrs')
            : PreflightResult::blocking('PHP 32 bits', 'Les montants en FCFA exigent des entiers de 64 bits.');

        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn (string $name): bool => ! extension_loaded($name)));
        $results[] = $missing === []
            ? PreflightResult::ok('Extensions PHP requises', count(self::REQUIRED_EXTENSIONS).' présentes')
            : PreflightResult::blocking('Extensions PHP requises', 'manquantes : '.implode(', ', $missing).' (à activer dans le panneau de l\'hébergeur).');

        $absent = array_values(array_filter(self::RECOMMENDED_EXTENSIONS, static fn (string $name): bool => ! extension_loaded($name)));
        $results[] = $absent === []
            ? PreflightResult::ok('Extensions PHP utiles', implode(', ', self::RECOMMENDED_EXTENSIONS).' présentes')
            : PreflightResult::warning('Extensions PHP utiles', 'absentes : '.implode(', ', $absent).' (nécessaires aux lots suivants).');

        $production = app()->environment('production');
        $results[] = $this->iniFlag('display_errors', false, $production, 'Les erreurs ne doivent jamais s\'afficher aux utilisateurs.');
        $results[] = $this->iniFlag('expose_php', false, false, 'Masquer la version de PHP.');
        $results[] = $this->iniFlag('zend.exception_ignore_args', true, false, 'Aucun argument dans les piles d\'appels journalisées.');

        $memory = $this->bytes((string) ini_get('memory_limit'));
        $results[] = $memory === -1 || $memory >= 128 * 1024 * 1024
            ? PreflightResult::ok('memory_limit', (string) ini_get('memory_limit'))
            : PreflightResult::warning('memory_limit', ini_get('memory_limit').' : 128M au moins sont recommandés.');

        $post = min($this->bytes((string) ini_get('post_max_size')) ?: PHP_INT_MAX, $this->bytes((string) ini_get('upload_max_filesize')) ?: PHP_INT_MAX);
        $results[] = $post >= 4 * 1024 * 1024
            ? PreflightResult::ok('Taille maximale des envois', ini_get('post_max_size').' / '.ini_get('upload_max_filesize'))
            : PreflightResult::warning('Taille maximale des envois', 'moins de 4 Mo : la synchronisation et les PDF exigent davantage (2 Mio de poussée et fichiers PDF).');

        $time = (int) ini_get('max_execution_time');
        $results[] = $time === 0 || $time >= 30
            ? PreflightResult::ok('max_execution_time', $time === 0 ? 'illimité' : $time.' s')
            : PreflightResult::warning('max_execution_time', $time.' s : 30 s au moins sont recommandées (migration initiale).');

        $results[] = extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)
            ? PreflightResult::ok('OPcache', 'actif')
            : PreflightResult::warning('OPcache', 'inactif : chaque requête recompile Laravel (lenteur).');

        return $results;
    }

    /**
     * @return list<PreflightResult>
     */
    private function configuration(): array
    {
        $results = [];
        $production = app()->environment('production');
        $environment = (string) config('app.env');

        $results[] = (string) config('app.key') !== ''
            ? PreflightResult::ok('APP_KEY', 'définie')
            : PreflightResult::blocking('APP_KEY', 'vide : lancez « php artisan key:generate ».');

        if (! $production) {
            $results[] = PreflightResult::info('Environnement', "APP_ENV={$environment} : les contrôles propres à la production (HTTPS, débogage, cookie sécurisé) sont ignorés.");
        } else {
            $results[] = config('app.debug') === false
                ? PreflightResult::ok('APP_DEBUG', 'désactivé')
                : PreflightResult::blocking('APP_DEBUG', 'activé en production : des détails internes seraient exposés.');

            $url = (string) config('app.url');
            $host = (string) parse_url($url, PHP_URL_HOST);
            $results[] = str_starts_with($url, 'https://') && $host !== ''
                ? PreflightResult::ok('APP_URL', $url)
                : PreflightResult::blocking('APP_URL', "« {$url} » : l'URL doit être en https:// (service worker, cookies sécurisés).");

            $results[] = config('session.secure') === true
                ? PreflightResult::ok('SESSION_SECURE_COOKIE', 'vrai')
                : PreflightResult::blocking('SESSION_SECURE_COOKIE', 'doit valoir true : le cookie de session ne doit voyager qu\'en HTTPS.');

            $stateful = array_map('strval', (array) config('sanctum.stateful', []));
            $results[] = $host !== '' && in_array($host, $stateful, true)
                ? PreflightResult::ok('SANCTUM_STATEFUL_DOMAINS', 'contient '.$host)
                : PreflightResult::blocking('SANCTUM_STATEFUL_DOMAINS', "doit contenir exactement l'hôte de l'application ({$host}) : sinon la connexion est refusée.");
        }

        $modules = (array) config('eeo.enabled_modules', []);
        $results[] = PreflightResult::info('Modules ouverts (EEO_ENABLED_MODULES)', $modules === [] ? 'aucun : tout module métier est invisible' : implode(', ', $modules));

        $results[] = (string) config('eeo.clinic_currency') === 'XAF' && (string) config('eeo.clinic_timezone') === 'Africa/Douala'
            ? PreflightResult::ok('Cabinet', 'XAF, Africa/Douala')
            : PreflightResult::info('Cabinet', config('eeo.clinic_currency').', '.config('eeo.clinic_timezone').' (hypothèses A02 modifiées)');

        $results[] = (string) config('app.timezone') === 'UTC'
            ? PreflightResult::ok('Fuseau applicatif', 'UTC (les instants sont stockés en UTC)')
            : PreflightResult::warning('Fuseau applicatif', (string) config('app.timezone').' : les instants doivent être stockés en UTC.');

        return $results;
    }

    /**
     * @return list<PreflightResult>
     */
    private function filesystem(): array
    {
        $results = [];
        $production = app()->environment('production');

        foreach (['storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $directory) {
            $path = base_path($directory);
            $results[] = is_dir($path) && is_writable($path)
                ? PreflightResult::ok('Écriture '.$directory, 'autorisée')
                : PreflightResult::blocking('Écriture '.$directory, 'dossier absent ou non accessible en écriture par le serveur web.');
        }

        $manifest = public_path('build/manifest.json');
        $results[] = is_file($manifest)
            ? PreflightResult::ok('Application construite', 'public/build/manifest.json présent')
            : ($production
                ? PreflightResult::blocking('Application construite', 'public/build/ absent : l\'archive livrée doit contenir le build du frontend.')
                : PreflightResult::warning('Application construite', 'public/build/ absent (npm run build).'));

        $leaks = array_values(array_filter(['.env', '.git', 'composer.json', 'artisan'], static fn (string $name): bool => file_exists(public_path($name))));
        $results[] = $leaks === []
            ? PreflightResult::ok('Racine web', 'public/ ne contient ni .env ni code source sensible')
            : PreflightResult::blocking('Racine web', 'public/ contient : '.implode(', ', $leaks).' (la racine web doit être le dossier public/ seul).');

        return $results;
    }

    /**
     * @return list<PreflightResult>
     */
    private function database(): array
    {
        $results = [];

        try {
            $row = DB::selectOne('SELECT VERSION() AS version, @@version_comment AS comment, @@default_storage_engine AS engine, @@max_allowed_packet AS packet, @@log_bin AS log_bin, @@log_bin_trust_function_creators AS trust, @@sql_mode AS mode, @@time_zone AS zone');
        } catch (Throwable $error) {
            return [PreflightResult::blocking('Connexion à la base', 'impossible : '.$this->short($error).' (vérifiez DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD).')];
        }
        if ($row === null) {
            return [PreflightResult::blocking('Connexion à la base', 'aucune réponse du serveur.')];
        }

        $version = (string) $row->version;
        $comment = (string) $row->comment;
        $results[] = PreflightResult::ok('Connexion à la base', 'établie ('.DB::connection()->getDatabaseName().')');

        $results[] = self::serverVerdict($version, $comment);

        $results[] = strtolower((string) $row->engine) === 'innodb'
            ? PreflightResult::ok('Moteur par défaut', 'InnoDB')
            : PreflightResult::warning('Moteur par défaut', (string) $row->engine.' : les tables sont créées en InnoDB explicitement.');

        $results[] = (int) $row->packet >= 4 * 1024 * 1024
            ? PreflightResult::ok('max_allowed_packet', (string) round(((int) $row->packet) / 1048576, 1).' Mo')
            : PreflightResult::warning('max_allowed_packet', (string) round(((int) $row->packet) / 1048576, 1).' Mo : 4 Mo au moins sont recommandés.');

        $results[] = PreflightResult::info('Journal binaire', ((int) $row->log_bin === 1 ? 'actif' : 'inactif').', log_bin_trust_function_creators='.(int) $row->trust);

        try {
            if (Schema::hasTable('clinics')) {
                $official = DB::table('clinics')->where('production_documents_enabled', 1)->count();
                $results[] = $official === 0
                    ? PreflightResult::ok('Documents officiels', 'désactivés : les pièces émises sont marquées TEST')
                    : PreflightResult::warning('Documents officiels', 'activés pour '.$official.' cabinet(s) : mentions légales, logo et régime fiscal doivent être validés.');
            } else {
                $results[] = PreflightResult::info('Schéma applicatif', 'pas encore installé (php artisan migrate --force)');
            }
        } catch (Throwable) {
        }

        return [...$results, ...$this->privileges((int) $row->log_bin === 1 && (int) $row->trust === 0)];
    }

    /**
     * Verdict sur la version annoncée par le serveur : MariaDB refusée tant que sa compatibilité n'est pas validée,
     * MySQL avant 8.0.16 refusé (contraintes CHECK ignorées), MySQL 8.0.16 à 8.3 accepté avec alerte, 8.4 et plus ok.
     */
    public static function serverVerdict(string $version, string $comment = ''): PreflightResult
    {
        if (stripos($version, 'mariadb') !== false || stripos($comment, 'mariadb') !== false) {
            return PreflightResult::blocking('Serveur de base', "{$version} : MariaDB n'est pas déclarée compatible (le projet est validé sur MySQL 8.4 : CHECK, colonnes générées, triggers). Demandez une base MySQL 8.x ou validez MariaDB par une recette complète.");
        }

        $number = preg_match('/^(\d+\.\d+\.\d+)/', $version, $match) === 1 ? $match[1] : '0.0.0';

        return match (true) {
            version_compare($number, '8.0.16', '<') => PreflightResult::blocking('Serveur de base', "MySQL {$version} : les contraintes CHECK ne sont appliquées qu'à partir de MySQL 8.0.16."),
            version_compare($number, '8.4.0', '<') => PreflightResult::warning('Serveur de base', "MySQL {$version} : la cible validée est MySQL 8.4 (à tester avant la mise en service)."),
            default => PreflightResult::ok('Serveur de base', "MySQL {$version}"),
        };
    }

    /**
     * Droits réels : chaque capacité est essayée sur des objets temporaires puis supprimée.
     *
     * @return list<PreflightResult>
     */
    private function privileges(bool $triggerRisk): array
    {
        $results = [];
        $suffix = bin2hex(random_bytes(4));
        $parent = "eeo_preflight_{$suffix}_p";
        $child = "eeo_preflight_{$suffix}_c";
        $view = "eeo_preflight_{$suffix}_v";
        $trigger = "eeo_preflight_{$suffix}_t";
        $created = [];

        $step = function (string $label, string $sql, string $failure) use (&$results): bool {
            try {
                $this->execute($sql);
                $results[] = PreflightResult::ok($label, 'accordé');

                return true;
            } catch (Throwable $error) {
                $results[] = PreflightResult::blocking($label, $failure.' ('.$this->short($error).')');

                return false;
            }
        };

        try {
            if ($step('Droit CREATE TABLE', "CREATE TABLE `{$parent}` (`id` CHAR(26) NOT NULL, `qty` INT NOT NULL DEFAULT 0, `label` VARCHAR(20) NOT NULL DEFAULT 'a', PRIMARY KEY (`id`), CONSTRAINT `{$parent}_ck` CHECK (`qty` >= 0)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci", 'impossible de créer une table InnoDB utf8mb4 avec une contrainte CHECK')) {
                $created[] = ['table', $parent];
                $results[] = PreflightResult::ok('Contrainte CHECK', 'acceptée à la création');
            }

            if ($created !== [] && $step('Clé étrangère (REFERENCES)', "CREATE TABLE `{$child}` (`id` CHAR(26) NOT NULL, `parent_id` CHAR(26) NOT NULL, `double_qty` INT GENERATED ALWAYS AS (1 * 2) STORED, PRIMARY KEY (`id`), CONSTRAINT `{$child}_fk` FOREIGN KEY (`parent_id`) REFERENCES `{$parent}` (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'impossible de créer une clé étrangère ni une colonne générée')) {
                $created[] = ['table', $child];
                $results[] = PreflightResult::ok('Colonne générée', 'acceptée');
            }

            if ($created !== []) {
                try {
                    $this->execute("INSERT INTO `{$parent}` (`id`, `qty`) VALUES ('P1', 1)");
                    $this->execute("UPDATE `{$parent}` SET `qty` = 2 WHERE `id` = 'P1'");
                    $results[] = PreflightResult::ok('Droits INSERT / UPDATE', 'accordés');
                } catch (Throwable $error) {
                    $results[] = PreflightResult::blocking('Droits INSERT / UPDATE', 'refusés ('.$this->short($error).')');
                }

                try {
                    $this->execute("INSERT INTO `{$parent}` (`id`, `qty`) VALUES ('P2', -1)");
                    $results[] = PreflightResult::blocking('Contraintes CHECK appliquées', 'une valeur négative a été acceptée : le serveur ignore les CHECK (MySQL avant 8.0.16 ou MariaDB réglée autrement).');
                } catch (Throwable) {
                    $results[] = PreflightResult::ok('Contraintes CHECK appliquées', 'une valeur interdite est refusée');
                }

                $step('Droit ALTER TABLE', "ALTER TABLE `{$parent}` ADD COLUMN `note` VARCHAR(10) NULL, ADD INDEX `{$parent}_ix` (`label`)", 'impossible de modifier une table ni d\'ajouter un index');
            }

            if ($created !== [] && $step('Droit CREATE VIEW', "CREATE VIEW `{$view}` AS SELECT `id`, `qty` FROM `{$parent}`", 'impossible de créer une vue (vues de soldes et de stock)')) {
                $created[] = ['view', $view];
            }

            if ($created !== []) {
                $ok = $step('Droit TRIGGER', "CREATE TRIGGER `{$trigger}` BEFORE UPDATE ON `{$parent}` FOR EACH ROW BEGIN IF OLD.`id` <> NEW.`id` THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'immuable'; END IF; END", 'impossible de créer un trigger : la base en utilise 88 pour protéger l\'historique'.($triggerRisk ? ' ; le journal binaire est actif et log_bin_trust_function_creators=0 : demandez à l\'hébergeur de l\'activer ou d\'autoriser SUPER' : ''));
                if ($ok) {
                    $created[] = ['trigger', $trigger];
                    try {
                        $this->execute("UPDATE `{$parent}` SET `id` = 'P9' WHERE `id` = 'P1'");
                        $results[] = PreflightResult::blocking('Trigger exécuté', 'une modification interdite par le trigger a été acceptée.');
                    } catch (Throwable) {
                        $results[] = PreflightResult::ok('Trigger exécuté', 'la modification interdite est refusée par le trigger');
                    }
                }
            }
        } finally {
            foreach (array_reverse($created) as [$type, $name]) {
                try {
                    $this->execute(match ($type) {
                        'trigger' => "DROP TRIGGER IF EXISTS `{$name}`",
                        'view' => "DROP VIEW IF EXISTS `{$name}`",
                        default => "DROP TABLE IF EXISTS `{$name}`",
                    });
                } catch (Throwable $error) {
                    $results[] = PreflightResult::warning('Nettoyage', "objet temporaire {$name} non supprimé (".$this->short($error).') : à supprimer à la main.');
                }
            }
            // Filet : une création partielle (échec au milieu) laisse peut-être un objet non listé.
            foreach ([$child, $parent] as $table) {
                try {
                    $this->execute("DROP TABLE IF EXISTS `{$table}`");
                } catch (Throwable) {
                }
            }
        }

        return $results;
    }

    /**
     * Envoie une instruction DDL seule, par PDO. Les noms d'objets sont ceux, générés ici (préfixe fixe et suffixe
     * hexadécimal aléatoire), des objets temporaires du diagnostic : jamais une donnée saisie. Connection::unprepared()
     * n'accepte qu'un littéral PHP ; PDO::exec lève une PDOException en cas d'erreur.
     */
    private function execute(string $sql): void
    {
        DB::connection()->getPdo()->exec($sql);
    }

    private function iniFlag(string $name, bool $expected, bool $blocking, string $why): PreflightResult
    {
        $value = filter_var(ini_get($name), FILTER_VALIDATE_BOOLEAN);

        if ($value === $expected) {
            return PreflightResult::ok($name, $expected ? 'activé' : 'désactivé');
        }

        $detail = ($expected ? 'désactivé' : 'activé').' : '.$why;

        return $blocking ? PreflightResult::blocking($name, $detail) : PreflightResult::warning($name, $detail);
    }

    /** Octets d'une valeur de php.ini (« 128M », « 2G », « -1 »). */
    private function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function short(Throwable $error): string
    {
        $message = preg_replace('/\s+/', ' ', $error->getMessage()) ?? '';
        // Ne jamais afficher le texte SQL complet ni d'identifiants de connexion.
        $message = (string) preg_replace('/\(Connection:.*$/', '', $message);

        return mb_substr(trim($message), 0, 220);
    }
}
