<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Paramètres métier d'Eagle's Eye Optics Medical
    |--------------------------------------------------------------------------
    |
    | Les instants sont enregistrés en UTC (config('app.timezone') reste « UTC », ainsi que la
    | session MySQL) ; le fuseau métier ci-dessous sert aux dates civiles et à l'affichage.
    | Valeurs proposées d'après les sources du projet (hypothèse A02, fichier 15) : XAF et
    | Africa/Douala, cabinet unique. La valeur effective par cabinet reste portée par la table
    | `clinics` (F04/F05).
    |
    */

    'clinic_timezone' => env('CLINIC_TIMEZONE', 'Africa/Douala'),

    /*
    | Cabinet servi par l'installation (ULID). Vide : l'unique cabinet de la base est utilisé ; s'il en
    | existe plusieurs, la connexion est refusée tant que la valeur n'est pas définie (contrat 23).
    */
    'clinic_id' => env('EEO_CLINIC_ID'),

    /*
    | Durée de validité d'une réauthentification pour les opérations sensibles (approbations, droits,
    | exports globaux), en secondes (fichier 09).
    */
    'reauthentication_ttl' => (int) env('EEO_REAUTHENTICATION_TTL', 900),

    'clinic_currency' => env('CLINIC_CURRENCY', 'XAF'),

    /*
    |--------------------------------------------------------------------------
    | Mise en service progressive des modules
    |--------------------------------------------------------------------------
    |
    | Codes des modules ouverts aux utilisateurs, séparés par des virgules (voir App\Support\Modules).
    | Vide : aucun module métier n'est exposé, ni à l'écran ni dans l'API. Cette liste appartient au
    | déploiement ; elle n'est pas un paramètre du cabinet.
    |
    */

    'enabled_modules' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('EEO_ENABLED_MODULES', ''))),
        static fn (string $code): bool => $code !== '',
    )),

    /*
    |--------------------------------------------------------------------------
    | Transactions du chemin central de commandes (F07, contrat 23)
    |--------------------------------------------------------------------------
    |
    | max_attempts : tentatives internes sur interblocage (3 au plus), puis erreur réessayable SERVER_BUSY.
    | lock_wait_timeout_seconds : attente maximale d'un verrou de ligne (innodb_lock_wait_timeout de la
    | session) ; au-delà, SERVER_BUSY sans nouvelle tentative interne. Le verrou du cabinet sérialise les
    | mutations centrales : mesurer avant de l'affiner (fichier 05).
    |
    */

    'transactions' => [
        'max_attempts' => (int) env('EEO_TRANSACTION_MAX_ATTEMPTS', 3),
        'lock_wait_timeout_seconds' => (int) env('EEO_LOCK_WAIT_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Postes hors-ligne
    |--------------------------------------------------------------------------
    |
    | max_clock_skew_seconds : tolérance entre l'horloge d'un poste et l'heure du serveur pour le contexte
    | d'autorisation HISTORIQUE d'une commande synchronisée (au-delà : revue par la gérante). Règles
    | d'horloge à confirmer avec la préparation des postes (O02).
    |
    */

    'offline' => [
        'max_clock_skew_seconds' => (int) env('EEO_MAX_CLOCK_SKEW', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Documents de production
    |--------------------------------------------------------------------------
    |
    | Interrupteur d'environnement. Tant qu'il vaut « false », tout document est un document TEST
    | clairement marqué (mentions légales, logo et régime de taxe non validés : Q03). Il ne peut
    | être passé à « true » qu'après validation des paramètres réels ; la règle exacte de
    | combinaison avec clinics.production_documents_enabled est définie avec les documents (L0-06).
    |
    */

    'production_documents_enabled' => (bool) env('PRODUCTION_DOCUMENTS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Intégrations externes
    |--------------------------------------------------------------------------
    |
    | « fake » : doubles de test uniquement, aucun message ni appel réel à un fournisseur pendant le
    | développement. Aucune clé fournisseur n'est lue ici ni versionnée.
    |
    */

    'integrations' => [
        'mode' => env('EEO_INTEGRATIONS_MODE', 'fake'),
    ],

];
