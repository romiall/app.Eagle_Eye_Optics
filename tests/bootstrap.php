<?php

/*
|--------------------------------------------------------------------------
| Amorçage PHPUnit
|--------------------------------------------------------------------------
|
| Fixe la connexion de test avant le démarrage de Laravel. Les valeurs viennent des variables
| EEO_TEST_DB_* de l'environnement du shell ou, à défaut, de valeurs fictives de développement
| (utilisateur « eeo_test » limité à la base isolée eeo_test par tools/dev/mysql-up.sh).
| Ces valeurs ne sont pas des secrets : elles ne donnent accès qu'à un conteneur local jetable.
|
| Les valeurs DB_* d'un fichier .env sont toujours écrasées ici : Dotenv ne remplace pas une
| variable déjà présente dans l'environnement du processus.
*/

require __DIR__.'/../vendor/autoload.php';

/** @var array<string, array{0: string, 1: string}> $connection */
$connection = [
    'DB_HOST' => ['EEO_TEST_DB_HOST', '127.0.0.1'],
    'DB_PORT' => ['EEO_TEST_DB_PORT', '3306'],
    'DB_DATABASE' => ['EEO_TEST_DB_DATABASE', 'eeo_test'],
    'DB_USERNAME' => ['EEO_TEST_DB_USERNAME', 'eeo_test'],
    'DB_PASSWORD' => ['EEO_TEST_DB_PASSWORD', 'eeo_test_password'],
];

$settings = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql'];

foreach ($connection as $name => [$source, $default]) {
    $value = getenv($source);
    $settings[$name] = ($value === false || $value === '') ? $default : $value;
}

// Clé de chiffrement éphémère de test : jamais lue d'un fichier, jamais versionnée.
if (! getenv('APP_KEY')) {
    $settings['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
}

foreach ($settings as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
