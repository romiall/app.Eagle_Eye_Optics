<?php

namespace App\Support\Testing;

/**
 * Garde de base de données : décide si une connexion désigne sans ambiguïté une base de test isolée.
 *
 * Les trois conditions sont cumulatives (contrats 23 et 25) :
 *   1. l'environnement applicatif est exactement « testing » ;
 *   2. le nom de la base se termine par « _test » (et ne contient que [a-z0-9_]) ;
 *   3. tous les hôtes configurés sont locaux (ou le service « mysql » d'un CI).
 * Le pilote doit aussi être MySQL : SQLite ne remplace pas les preuves InnoDB et n'est jamais « isolé » ici.
 *
 * Cette garde ne lit aucun réglage modifiable depuis l'environnement pour s'assouplir : les constantes
 * ci-dessous sont volontairement figées dans le code.
 */
final class IsolatedDatabaseGuard
{
    public const REQUIRED_ENVIRONMENT = 'testing';

    public const REQUIRED_NAME_SUFFIX = '_test';

    /** Hôtes considérés comme locaux ; « mysql » est le nom du service dans un conteneur de CI. */
    public const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1', 'mysql'];

    // \z et non $ : « $ » accepterait un saut de ligne final (« prod_test\n »).
    private const DATABASE_NAME_PATTERN = '/^[a-z0-9][a-z0-9_]*_test\z/';

    /**
     * @param  list<string>  $hosts  Tous les hôtes de la connexion (lecture et écriture comprises).
     */
    public function __construct(
        public readonly string $environment,
        public readonly string $database,
        public readonly array $hosts,
        public readonly string $driver = 'mysql',
    ) {}

    /**
     * Construit la garde pour une connexion de config/database.php.
     */
    public static function forConnection(string $environment, string $connection): self
    {
        $config = config("database.connections.{$connection}");

        if (! is_array($config)) {
            return new self($environment, '', [], '');
        }

        return new self(
            $environment,
            is_string($config['database'] ?? null) ? $config['database'] : '',
            self::collectHosts($config),
            is_string($config['driver'] ?? null) ? $config['driver'] : '',
        );
    }

    public function allows(): bool
    {
        return $this->violations() === [];
    }

    /**
     * @return list<string> Motifs de refus en français, vide si la base est une base de test isolée.
     */
    public function violations(): array
    {
        $violations = [];

        if ($this->driver !== 'mysql') {
            $violations[] = sprintf('le pilote « %s » n\'est pas MySQL (seules les bases MySQL de test sont admises)', $this->driver);
        }

        if ($this->environment !== self::REQUIRED_ENVIRONMENT) {
            $violations[] = sprintf('l\'environnement « %s » n\'est pas « %s »', $this->environment, self::REQUIRED_ENVIRONMENT);
        }

        if (preg_match(self::DATABASE_NAME_PATTERN, $this->database) !== 1) {
            $violations[] = sprintf(
                'le nom de base « %s » ne se termine pas par « %s » (ou contient des caractères non admis)',
                $this->database,
                self::REQUIRED_NAME_SUFFIX,
            );
        }

        if ($this->hosts === []) {
            $violations[] = 'aucun hôte de base de données déterminé';
        }

        foreach ($this->hosts as $host) {
            if (! in_array(strtolower($host), self::LOCAL_HOSTS, true)) {
                $violations[] = sprintf('l\'hôte « %s » n\'est pas local', $host);
            }
        }

        return $violations;
    }

    /**
     * @throws DestructiveDatabaseOperationRefused
     */
    public function assertAllows(string $operation): void
    {
        $violations = $this->violations();

        if ($violations !== []) {
            throw DestructiveDatabaseOperationRefused::for($operation, $violations);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function collectHosts(array $config): array
    {
        $hosts = [];

        $candidates = [$config['host'] ?? null];
        foreach (['read', 'write'] as $role) {
            $section = $config[$role] ?? null;
            $candidates[] = is_array($section) ? ($section['host'] ?? null) : null;
        }

        foreach ($candidates as $candidate) {
            foreach ((array) $candidate as $host) {
                if (is_string($host) && trim($host) !== '') {
                    $hosts[] = trim($host);
                }
            }
        }

        // Connexion par socket Unix : forcément locale.
        if ($hosts === [] && is_string($config['unix_socket'] ?? null) && $config['unix_socket'] !== '') {
            $hosts[] = 'localhost';
        }

        return array_values(array_unique($hosts));
    }
}
