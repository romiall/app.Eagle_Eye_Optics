# Construction et déploiement (F02)

Ce document décrit comment construire l'application et ce qui doit être déployé. Il couvre le socle F02 : Laravel 13 (API privée) et SPA/PWA Vue 3 construite en fichiers statiques servis par Laravel sur une seule origine HTTPS. Aucune fonction métier n'est livrée à ce stade ; `php artisan migrate` ne crée encore aucune table (les 117 tables métier et les tables `fw_*` arrivent avec F04/F05).

## Principe : aucun serveur Node en production

Node ne sert qu'à **construire** les fichiers (poste de développement ou CI). En production, il n'y a que PHP et MySQL : Laravel sert la coquille HTML, l'API sous `/api`, le service worker (`/sw.js`) et le manifeste (`/manifest.webmanifest`) ; les fichiers versionnés du build sont des fichiers statiques de `public/build/`. Ni `npm run dev`, ni Vite, ni `node_modules` ne tournent ou n'existent sur le serveur.

## Prérequis de construction

| Outil | Version | Usage |
| --- | --- | --- |
| PHP | 8.3 minimum (8.4 recommandé, non exigé : voir `startup.md`) | Laravel 13, tests |
| Extensions PHP | `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `session`, `tokenizer`, `xml`, `pdo_mysql` | Exigences de Laravel + MySQL |
| Composer | 2.x | Dépendances PHP (`composer.lock` versionné) |
| Node / npm | Node 22 ou plus (`engines`), npm 10 | Build du frontend (`package-lock.json` versionné) |
| MySQL | 8.4 / InnoDB | Base applicative ; MariaDB n'est pas déclarée compatible |

`bcmath` et `gmp` ne sont pas requis : les décimaux exacts utilisent `brick/math`, bibliothèque pure PHP (mode natif automatique). Aucun package de rôles ni outil de débogage (Telescope, Debugbar) n'est installé.

## Commandes de construction reproductible

À exécuter à la racine du dépôt, dans cet ordre :

```text
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
```

- `composer install` lit `composer.lock` (aucune résolution) ; `--no-dev` exclut PHPUnit, Pint, Mockery, etc.
- `npm ci` lit `package-lock.json` et échoue si le verrou et `package.json` divergent. Les scripts d'installation des paquets sont désactivés par `.npmrc` (`ignore-scripts=true`).
- `npm run build` produit `public/build/` : `assets/*.js|css` à empreinte, `manifest.json` (lu par Laravel), `manifest.webmanifest` et `sw.js` (service worker en un seul fichier, sans `importScripts`).

Le build n'est pas octet pour octet identique d'une exécution à l'autre : la révision de précache de la coquille `/` change à chaque build (c'est voulu, pour que le service worker recharge la coquille). Définir `EEO_BUILD_ID=<identifiant>` fixe cette révision pour un build reproductible.

Ensuite, sur le serveur ou avant téléversement :

```text
cp .env.example .env          # puis renseigner les valeurs du serveur
php artisan key:generate
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Ne jamais versionner `.env`. `.env.example` ne contient aucun secret. En production : `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…` (sous-domaine HTTPS dédié), `SESSION_SECURE_COOKIE=true`, `PRODUCTION_DOCUMENTS_ENABLED=false` tant que les mentions légales ne sont pas validées, `MAIL_MAILER` et intégrations selon les lots (aucun fournisseur réel avant L2/L3).

## Développement local

```text
composer install && npm ci
cp .env.example .env && php artisan key:generate
php artisan serve        # http://127.0.0.1:8000 ; sert la coquille, /api et les fichiers PWA
npm run dev              # serveur Vite (rechargement à chaud) ; public/hot indique à Laravel où le trouver
```

En développement (`npm run dev`), le service worker n'est pas enregistré : tester la PWA sur le build (`npm run build`, puis `php artisan serve`). La première requête de session, de cache ou de file avec les pilotes `database` exige les tables `fw_*` de F04 ; la coquille et l'API inconnue n'en ont pas besoin. Tant que F04 n'est pas faite, `php artisan cache:clear` et `php artisan optimize:clear` échouent faute de table `fw_cache` : utiliser `config:clear`, `route:clear` et `view:clear`.

## Ce qui doit être déployé

| Élément | Déployé ? | Remarque |
| --- | --- | --- |
| `public/` (dont `public/build/`, `public/icons/`, `.htaccess`, `index.php`) | Oui | **Racine web du site.** Rien d'autre ne doit être accessible par HTTP |
| `app/`, `bootstrap/`, `config/`, `routes/`, `resources/views/`, `artisan` | Oui | Code PHP |
| `database/migrations/`, `database/seeders/`, `database/factories/` | Oui | Installation par migrations (F04) ; les fichiers `.sql` et outils Python de `database/` ne sont pas nécessaires à l'exécution |
| `vendor/` | Oui, construit avec `--no-dev` | Si l'hébergement n'a ni Composer ni SSH, le construire en CI et le téléverser (fichier 05) |
| `storage/` | Oui, structure vide | Droits d'écriture pour le serveur web ; les `.gitignore` de chaque sous-dossier gardent l'arborescence |
| `composer.json`, `composer.lock` | Oui | Traçabilité et `composer audit` |
| `.env` | Créé **sur le serveur** | Jamais dans une archive ni dans git |
| `node_modules/`, `package*.json`, `vite.config.ts`, `tsconfig.json`, `eslint.config.js`, `vitest.config.ts`, `playwright.config.ts` | Non | Outils de construction |
| `resources/frontend/` (sources Vue/TypeScript) | Non nécessaire | Remplacé par `public/build/`. S'il est présent, il ne doit pas être servi (racine web = `public/`) |
| `tests/`, `tools/`, `specifications/`, `docs/`, `.github/`, `.spec-work/` | Non | Développement et documentation |
| `.env.e2e`, `test-results/`, `playwright-report/`, `.phpunit.cache/` | Non | Fichiers locaux ignorés par git |

## Service worker, manifeste et HTTPS

- Le service worker est généré dans `public/build/sw.js` mais **servi à `/sw.js`** par une route Laravel (`routes/spa.php`) avec `Service-Worker-Allowed: /` et `Cache-Control: no-cache`, afin que sa portée couvre toute l'origine. Le manifeste est servi à `/manifest.webmanifest`. Sans `public/build/`, ces deux URL répondent 404 : le build fait partie du déploiement.
- Les requêtes qui ne correspondent pas à un fichier doivent atteindre `public/index.php` (réécriture fournie par `public/.htaccess` pour Apache ; sous nginx : `try_files $uri /index.php?$query_string`).
- Le service worker ne met **jamais** en cache `/api` (stratégie `NetworkOnly` explicite) et ne sert jamais la coquille SPA pour `/api`, `/up`, `/build/`, `/storage/` ni pour une URL ressemblant à un fichier. Il précache seulement la coquille `/`, les icônes locales et les fichiers versionnés de `public/build/assets/`. Les mises à jour attendent une confirmation (`registerType: 'prompt'`) : un nouveau service worker ne prend pas le contrôle d'une session ouverte (une commande locale en attente ne doit jamais être interrompue).
- HTTPS est obligatoire : les service workers, Web Crypto et les cookies `Secure` exigent un contexte sécurisé.
- La coquille est servie **hors du groupe middleware `web`** : pas de session, pas de cookie, réponse identique pour tous.

## Vérifier un déploiement

1. `curl -fsS https://<domaine>/up` répond 200.
2. `curl -sS -o /dev/null -w "%{http_code} %{content_type}\n" https://<domaine>/api/v1/inconnu` répond `404 application/json` (jamais la page HTML).
3. Ouvrir `/` : titre « Eagle’s Eye Optics — Gestion du cabinet ».
4. Outils de développement du navigateur, Application, Service Workers : script `/sw.js`, portée `/`, état « activated ».
5. Couper le réseau et recharger `/` : la coquille s'affiche (précache) ; `/api/…` n'affiche jamais la coquille.

Les tests automatisés équivalents sont dans `tests/Feature/Http/SpaShellTest.php` et `tests/e2e/smoke.spec.ts` (voir `testing.md`).

## Limites connues de F02

- Les icônes de `public/icons/` sont des icônes **provisoires** (aucun logo réel fourni : Q03). À remplacer avant la production.
- Aucune fonte distante : la pile de fontes système est utilisée ; les fontes des documents PDF seront locales (tâches de documents).
- L'analyse statique PHP (Larastan/PHPStan) n'est pas installée : son paquet `phpstan/phpstan` n'est pas téléchargeable depuis l'environnement de développement cloud (téléchargement de l'archive GitHub refusé par la politique réseau). À ajouter depuis un poste ou une CI ordinaire (`composer require --dev larastan/larastan`) puis un script `composer run analyse`.
- Les contrôles de métadonnées de schéma (`php artisan eagle:verify-schema`), les contrats (`test:contracts`) et les vecteurs de règles (`npm run test:domain`) du fichier 25 appartiennent à F04, F06 et F08 : ils n'existent pas encore.
