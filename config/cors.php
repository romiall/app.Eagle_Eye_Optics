<?php

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| Application privée servie par une seule origine (fichiers 05 et 23) : la SPA, l'API et le service
| worker partagent l'origine du sous-domaine. Aucune origine tierce n'a besoin d'appeler l'API, donc
| aucun chemin n'émet d'en-tête CORS (la valeur par défaut du framework autoriserait « * » sur /api).
| La réservation publique de L3 aura ses propres routes et sa propre décision.
*/

return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
