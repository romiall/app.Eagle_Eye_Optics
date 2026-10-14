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

    'clinic_currency' => env('CLINIC_CURRENCY', 'XAF'),

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
