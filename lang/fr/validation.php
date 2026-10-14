<?php

/*
|--------------------------------------------------------------------------
| Messages de validation en français
|--------------------------------------------------------------------------
|
| Messages affichés au personnel (fichier 04 : erreurs françaises, texte saisi conservé). Seules les
| règles utilisées par l'application sont traduites ; une règle nouvelle doit être ajoutée ici.
*/

return [
    'accepted' => 'Le champ :attribute doit être accepté.',
    'array' => 'Le champ :attribute doit être une liste.',
    'between' => [
        'numeric' => 'Le champ :attribute doit être compris entre :min et :max.',
        'string' => 'Le champ :attribute doit contenir entre :min et :max caractères.',
        'array' => 'Le champ :attribute doit contenir entre :min et :max éléments.',
    ],
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'date' => 'Le champ :attribute doit être une date valide.',
    'date_format' => 'Le champ :attribute doit respecter le format :format.',
    'decimal' => 'Le champ :attribute doit comporter :decimal décimales.',
    'different' => 'Les champs :attribute et :other doivent être différents.',
    'digits' => 'Le champ :attribute doit contenir :digits chiffres.',
    'distinct' => 'Le champ :attribute contient une valeur en double.',
    'email' => 'Le champ :attribute doit être une adresse électronique valide.',
    'exists' => 'La valeur du champ :attribute est inconnue.',
    'gt' => ['numeric' => 'Le champ :attribute doit être supérieur à :value.'],
    'gte' => ['numeric' => 'Le champ :attribute doit être supérieur ou égal à :value.'],
    'in' => 'La valeur du champ :attribute n\'est pas admise.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'json' => 'Le champ :attribute doit être un JSON valide.',
    'lt' => ['numeric' => 'Le champ :attribute doit être inférieur à :value.'],
    'lte' => ['numeric' => 'Le champ :attribute doit être inférieur ou égal à :value.'],
    'max' => [
        'numeric' => 'Le champ :attribute ne doit pas dépasser :max.',
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
        'array' => 'Le champ :attribute ne doit pas contenir plus de :max éléments.',
    ],
    'min' => [
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
    ],
    'not_in' => 'La valeur du champ :attribute n\'est pas admise.',
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'password' => [
        'letters' => 'Le champ :attribute doit contenir au moins une lettre.',
        'mixed' => 'Le champ :attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le champ :attribute doit contenir au moins un chiffre.',
        'symbols' => 'Le champ :attribute doit contenir au moins un symbole.',
        'uncompromised' => 'Ce :attribute figure dans une fuite de données connue : choisissez-en un autre.',
    ],
    'present' => 'Le champ :attribute doit être présent.',
    'prohibited' => 'Le champ :attribute n\'est pas admis.',
    'regex' => 'Le format du champ :attribute est invalide.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_if' => 'Le champ :attribute est obligatoire quand :other vaut :value.',
    'required_with' => 'Le champ :attribute est obligatoire quand :values est présent.',
    'same' => 'Les champs :attribute et :other doivent correspondre.',
    'size' => [
        'numeric' => 'Le champ :attribute doit valoir :size.',
        'string' => 'Le champ :attribute doit contenir :size caractères.',
        'array' => 'Le champ :attribute doit contenir :size éléments.',
    ],
    'string' => 'Le champ :attribute doit être un texte.',
    'ulid' => 'Le champ :attribute doit être un identifiant valide.',
    'unique' => 'La valeur du champ :attribute est déjà utilisée.',

    'attributes' => [
        'login' => 'identifiant',
        'password' => 'mot de passe',
        'current_password' => 'mot de passe actuel',
        'display_name' => 'nom affiché',
        'email' => 'adresse électronique',
        'roles' => 'profils',
        'roles.*' => 'profil',
        'active' => 'compte actif',
        'reason' => 'motif',
        'expected_version' => 'version attendue',
        'permission_code' => 'permission',
        'effect' => 'effet',
        'expires_at' => 'date d\'expiration',
        'name' => 'nom',
        'address' => 'adresse',
        'phone' => 'téléphone',
        'tax_identifier' => 'identifiant fiscal',
        'professional_mentions' => 'mentions professionnelles',
        'document_settings' => 'paramètres des documents',
    ],
];
