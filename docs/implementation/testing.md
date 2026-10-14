# Tests, contrôles de qualité et base MySQL isolée (F03)

Ce document explique comment lancer chaque suite en local et en CI, comment la base de test est protégée, et les limites de l'environnement. Il couvre uniquement le socle F03 : les tests de règles métier, de contrats et de schéma arrivent avec les tâches suivantes (voir « Ce qui n'existe pas encore »).

## Vue d'ensemble

| Suite | Commande | Prérequis | Ce qu'elle prouve |
| --- | --- | --- | --- |
| PHP, règles pures | `composer run test:unit` | PHP seulement | Garde de base de données (refus en production, nom sans `_test`, hôte distant, pilote) |
| PHP, application + MySQL | `composer run test:mysql` | MySQL 8.4 isolé (`eeo_test`) | Coquille SPA et `/api` JSON 404, configuration `fw_*`/UTC/XAF, `.env.example` sans secret, écouteur et commande de remise à zéro, connexion MySQL réelle |
| PHP, concurrence | `composer run test:concurrency` | MySQL 8.4 isolé | Deux sessions MySQL distinctes et barrière contrôlée (base des tests de verrous) |
| PHP, tout | `composer run test` (ou `php artisan test`) | MySQL 8.4 isolé | Les trois suites |
| Style PHP | `composer run lint` | — | Pint, sans modification (`composer run lint:fix` corrige) |
| TypeScript | `npm run typecheck` | `npm ci` | `vue-tsc`, mode strict, `noUncheckedIndexedAccess` |
| ESLint | `npm run lint` | `npm ci` | Règles TypeScript/Vue, aucune API Node dans le frontend, aucun accès Dexie hors de `offline/` |
| Prettier | `npm run format:check` | `npm ci` | Format du code frontend |
| Vitest | `npm test` | `npm ci` | Amorçage Vue, configuration PWA (jamais d'API en cache ni de repli SPA sur `/api`), outillage Dexie/fake-indexeddb |
| Build | `npm run build` | `npm ci` | Assets statiques, manifeste et service worker dans `public/build/` |
| E2E | `npm run test:e2e` (build + tests) ou `npm run test:e2e:run` | Build, Chromium | Application construite dans un vrai navigateur : titre français, service worker actif, rechargement serveur arrêté, `/api` jamais remplacé par la coquille |
| Tout enchaîner | `tools/dev/check-all.sh [--strict] [--with-e2e]` | Dépendances installées | Pint, PHPUnit, types, ESLint, Prettier, Vitest, build (+ e2e) |

Un test MySQL dont la base est injoignable est **ignoré avec un message explicite** (pas d'échec silencieux) sauf si `EEO_REQUIRE_MYSQL=1` : alors c'est un échec. La CI et `check-all.sh --strict` positionnent cette variable.

## Mise en route locale

```text
composer install
npm ci
tools/dev/mysql-up.sh            # conteneur MySQL 8.4.9 + base eeo_test + utilisateur eeo_test
tools/dev/check-all.sh --strict
```

Sous Windows, lancer les scripts depuis WSL ou Git Bash.

### Base MySQL de test (Docker)

`tools/dev/mysql-up.sh` démarre ou réutilise le conteneur `eeo-mysql` (image `mysql:8.4.9`, repli sur `mirror.gcr.io/library/mysql:8.4.9` si Docker Hub limite le débit), publié seulement sur `127.0.0.1:3306`. Il crée la base `eeo_test` et l'utilisateur `eeo_test`, limité à `eeo_test` et aux bases jetables `eeo_*_test`. Il active `log_bin_trust_function_creators` : avec le journal binaire actif (réglage par défaut de MySQL 8.4), un utilisateur sans `SUPER` ne peut pas créer de triggers, or les guards d'historique (F04) en sont. **Ce prérequis est aussi à contrôler chez l'hébergeur (Q01).** `SET GLOBAL` est perdu au redémarrage du serveur : relancer `mysql-up.sh` après tout redémarrage.

- Aucun mot de passe n'est écrit dans le dépôt. `EEO_MYSQL_ROOT_PASSWORD` donne le mot de passe root du conteneur ; à défaut, le script utilise le mot de passe jetable explicite `eeo-dev-root-jetable`, qui ne protège qu'un conteneur local lié à `127.0.0.1`. Un conteneur déjà créé avec un autre mot de passe exige `EEO_MYSQL_ROOT_PASSWORD=<ce mot de passe>`.
- Variables lues par les tests, avec des défauts fictifs de développement : `EEO_TEST_DB_HOST` (`127.0.0.1`), `EEO_TEST_DB_PORT` (`3306`), `EEO_TEST_DB_DATABASE` (`eeo_test`), `EEO_TEST_DB_USERNAME` (`eeo_test`), `EEO_TEST_DB_PASSWORD` (`eeo_test_password`, mot de passe fictif).
- `tests/bootstrap.php` impose ces valeurs **avant** le démarrage de Laravel : un `.env` de développement ne peut jamais rediriger PHPUnit vers une autre base (Dotenv ne remplace pas une variable déjà présente).

**Docker à relancer.** Dans l'environnement de développement cloud, le démon Docker ne survit pas aux redémarrages de session :

```text
(dockerd >/tmp/dockerd.log 2>&1 &)
docker start eeo-mysql
tools/dev/mysql-up.sh            # ou, en une seule commande : EEO_START_DOCKERD=1 tools/dev/mysql-up.sh
```

## Garde de base de données

Objectif (contrats 23 et 25) : détruire une base de production ou de développement est impossible par erreur ou par mauvaise configuration.

La garde `App\Support\Testing\IsolatedDatabaseGuard` n'autorise une opération destructive que si **les quatre conditions** sont réunies :

1. `APP_ENV` vaut exactement `testing` ;
2. le nom de base correspond à `^[a-z0-9][a-z0-9_]*_test$` ;
3. tous les hôtes de la connexion (lecture et écriture comprises) sont locaux : `127.0.0.1`, `localhost`, `::1` ou `mysql` (service de CI), ou connexion par socket Unix ;
4. le pilote est MySQL (SQLite ne vaut jamais « base isolée »).

Elle est appliquée à trois endroits :

| Point d'application | Effet |
| --- | --- |
| Écouteur `CommandStarting` (`BlockDestructiveDatabaseCommands`) | `migrate:fresh`, `migrate:refresh`, `migrate:reset` et `db:wipe` lèvent `DestructiveDatabaseOperationRefused` **avant** toute connexion, sauf base de test isolée, y compris sous `APP_ENV=testing` avec une base mal ciblée. Il vise la connexion de `--database` si elle est donnée. Le fournisseur de services relaie explicitement les événements Symfony, car Laravel ne le fait pas lorsque `APP_ENV=testing` |
| `php artisan eeo:test-db:reset [--database=] [--migrate]` | Vide (tables, vues, triggers) une base isolée, puis rejoue éventuellement les migrations. Refuse toute autre base (code de sortie 1). C'est la **seule** procédure de remise à zéro prévue |
| `Tests\TestCase::setUp()` | Aucun test d'application ne démarre si l'environnement n'est pas `testing` ou si la connexion par défaut n'est pas isolée. Défense en profondeur, indépendante de l'écouteur : elle échoue avant tout accès à la base, y compris pour `RefreshDatabase` |

Limites à connaître : l'écouteur protège les invocations de commande de premier niveau (`php artisan …`, `Artisan::call(…)`), pas une commande appelée par une autre commande (`$this->call(…)`), ni du SQL écrit à la main. En production, aucune commande `migrate:fresh/refresh/reset` n'a sa place dans un runbook ; une correction passe par une migration additive.

Réinitialiser la base de test depuis un terminal (la configuration doit viser la base isolée) :

```text
APP_ENV=testing DB_DATABASE=eeo_test DB_USERNAME=eeo_test DB_PASSWORD=<mot de passe fictif de test> \
  php artisan eeo:test-db:reset --migrate
```

Preuves exécutées (voir `progress.md`) : un vrai processus `php artisan migrate:fresh` est refusé en `production`, en `local` et en `testing` avec la base `eagle_eye_optics` ; il est accepté sur `eeo_test`.

## Tests PHP

- `phpunit.xml` : suites `Unit`, `Feature`, `Concurrency`, `APP_ENV=testing` imposé, cache et sessions en mémoire, file `sync`, courrier `array`, intégrations `fake`, aucun fournisseur réel.
- `tests/Support/RequiresIsolatedMysql.php` : trait des tests qui ont besoin du vrai MySQL. Il **échoue** si la connexion n'est pas isolée, **ignore** (ou échoue avec `EEO_REQUIRE_MYSQL=1`) si MySQL est injoignable.
- Les tests de concurrence ouvrent deux connexions MySQL réellement distinctes et les entrelacent avec une barrière contrôlée (verrou nommé). Les tests de verrous et de files de F07/O03 s'appuieront sur ce modèle.
- SQLite ne remplace pas MySQL pour InnoDB, contraintes, triggers et verrous : il n'est utilisé que comme base volatile pour prouver qu'un refus de commande n'atteint aucune base.
- Pas de mesure de couverture PHP : `pcov` et `xdebug` sont absents de l'environnement.

## Tests frontend

- `vitest.config.ts` : environnement jsdom, `tests/frontend/**/*.test.ts`, `fake-indexeddb` chargé dans `tests/frontend/setup.ts`. Configuration distincte de `vite.config.ts` (ni Laravel, ni Tailwind, ni PWA).
- `tests/frontend/pwa-config.test.ts` teste la même configuration PWA que celle du build (`resources/frontend/app/pwa.config.ts`).
- `fake-indexeddb` sert aux tests unitaires du moteur local ; la preuve de persistance après fermeture du navigateur (G1) exige un vrai navigateur avec profil persistant.

## Tests e2e (Playwright, Chromium)

- Prérequis : `npm run build` (le script `npm run test:e2e` le fait) et un Chromium.
- Résolution du navigateur par `playwright.config.ts` : variable `PLAYWRIGHT_CHROMIUM_EXECUTABLE`, sinon Chromium préinstallé de `/opt/pw-browsers`, sinon navigateur géré par Playwright. **Ne jamais lancer `playwright install` sur le poste de développement cloud** ; l'installation n'est autorisée qu'en CI.
- Serveur : `node tests/e2e/prepare-env.mjs` génère `.env.e2e` (ignoré par git, `APP_ENV=e2e`, clé d'application aléatoire, base `eeo_test`, intégrations `fake`), puis `php artisan serve` sur `127.0.0.1:8123`. Le test de coupure démarre son propre serveur sur le port suivant (8124).
- **Coupure réelle du serveur.** Le mode « hors ligne » de Playwright ne s'applique pas aux requêtes émises par le service worker : un test fondé dessus passerait à tort. Le test arrête donc vraiment le processus PHP (et vérifie que le port refuse les connexions) puis recharge la page : la coquille vient du précache, `/api` n'est jamais remplacé par la coquille. Une régression de la liste de refus du service worker fait échouer ce test (vérifié par mutation).
- Ces tests e2e sont des preuves de fumée du socle. Le parcours G1 (patient, facture, deux paiements, PDF, reprise après fermeture complète du navigateur, ACK rejoué) reste à écrire avec les tâches O01 et suivantes.

## Intégration continue

`.github/workflows/quality.yml` (permissions `contents: read`, aucun secret) :

| Job | Contenu |
| --- | --- |
| `php` | PHP 8.3, service `mysql:8.4` avec base `eeo_test`, `composer install`, préparation des droits des bases de test, Pint, `test:unit`, `test:mysql`, `test:concurrency`, `composer audit` ; `EEO_REQUIRE_MYSQL=1` |
| `frontend` | Node 22, `npm ci`, `typecheck`, `lint`, `format:check`, `test`, `build`, `npm audit --omit=dev` |
| `e2e` | PHP + Node, build, Chromium installé et mis en cache (installation autorisée en CI uniquement), tests Playwright ; traces conservées 7 jours en cas d'échec seulement |

Les mots de passe visibles dans le workflow sont des valeurs fictives d'un service éphémère du runner. Les journaux ne contiennent que des identifiants techniques et des données fictives. **Le workflow n'a pas encore été exécuté sur GitHub** : il l'a été sous forme de commandes équivalentes en local ; sa première exécution réelle est à constater après le premier push.

## Ce qui n'existe pas encore (fichier 25)

Ces commandes seront créées par les tâches propriétaires ; elles ne sont pas disponibles et ne doivent pas être annoncées comme réussies : `php artisan eagle:verify-schema` (F04), `composer run test:contracts` (F06), `npm run test:domain` (vecteurs communs PHP/TS, F08), tests de concurrence métier (F07/O03), analyse statique PHP (Larastan, voir `build.md`).
