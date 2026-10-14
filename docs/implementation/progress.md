# Journal d'exécution — Eagle's Eye Optics Medical

Initialisé le 2026-10-14 depuis `specifications/developpement/JOURNAL.md`. Le backlog canonique (`specifications/developpement/backlog.json`) n'est jamais modifié ni régénéré ; les statuts de travail sont dans `docs/implementation/task-status.json`.

## État courant

- Branche : `claude/developpement-l0-3pkhne` (dépôt public : données fictives uniquement).
- Lot et gate visés : L0 ; **G0 acquis** (voir `docs/evidence/G0.md`), prochain gate G1.
- Tâche courante : F05 (identité, permissions, bootstrap gérante) — IN_REVIEW.
- Dernière preuve acquise : F04 — `docs/evidence/schema-parity.json` (22/22 au commit `3fe6e93`, MySQL 8.4.9) et installation neuve reproductible en mode production depuis un clone frais (`docs/evidence/G0.md`) ; `check-all.sh --strict --with-e2e` : PHPUnit 170 tests / 20 560 assertions, PHPStan niveau 8 sans erreur, Vitest 30, Playwright 13. La validation SQL d'origine (`database/VALIDATION.md`) et G0 restent **distincts d'une recette applicative**.
- Tâches DONE : F01, F02, F03, F04, G0.
- Blocages externes : Q01–Q04/Q14 (production seulement) ; développement fictif possible.
- Prochaine action concrète : F05 — adapter `User` (login, `password_hash`, ULID, `active`, sans `remember_token` ni affectation de masse de `clinic_id`/`password_hash`), permissions effectives depuis `user_roles`/`user_permissions` (révocations, dates), session Sanctum SPA (`/api/v1/auth/*`), commande `SetupClinic` sans mot de passe universel ; première route `/api` réelle pour la preuve e2e « jamais en cache ».

## Environnement de développement (cloud)

PHP 8.3.6 (sans bcmath/gmp/pcov), Composer 2.8.12, Node 22.22.0, npm 10.9.4, Chromium Playwright dans `/opt/pw-browsers`. MySQL 8.4.9 par Docker (`mirror.gcr.io/library/mysql:8.4.9`, conteneur `eeo-mysql`, base isolée `eeo_test`). Reprise après redémarrage du conteneur de session : relancer `dockerd`, puis le conteneur MySQL. Détails : `startup.md`.

## Registre des tâches

### F01 — Confirmer la baseline et les contraintes de lancement

| Champ | Valeur |
| --- | --- |
| Statut | DONE |
| Objectif | Architecture 23 retenue, aucun VPS ni donnée réelle, environnement local identifié |
| Fichiers | `docs/implementation/startup.md`, `hosting-audit.md`, `progress.md`, `task-status.json` |
| Tests exécutés | Audit des versions (php -v, extensions, composer, node, docker, mysql @@version = 8.4.9) le 2026-10-14 ; accès Packagist/npm vérifiés |
| Défauts ouverts | Audit de l'hébergeur impossible sans accès (Q01) ; PHP 8.3 au lieu de 8.4 ; bcmath/gmp absents |
| Décision nouvelle | Aucune modification d'architecture ; MySQL via Docker faute de téléchargement direct (limite réseau, consignée) |
| Reprise | Démarrer F02 |

### F02 — Créer le dépôt applicatif et verrouiller les dépendances

| Champ | Valeur |
| --- | --- |
| Statut | IN_REVIEW |
| Objectif | Laravel 13 intégré à la racine sans écraser les sources ; SPA Vue 3/TypeScript strict/Tailwind 4/PWA construite en fichiers statiques ; dépendances verrouillées ; aucun serveur Node en production |
| Fichiers | `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `vite.config.ts`, `tsconfig.json`, `.env.example`, `config/eeo.php`, `config/{session,cache,queue,auth,database,sanctum,filesystems}.php`, `bootstrap/app.php`, `routes/{api,spa,console}.php`, `app/Http/Controllers/{SpaController,HealthController}.php`, `app/Providers/AppServiceProvider.php`, `app/Models/User.php`, `resources/frontend/**`, `resources/views/spa.blade.php`, `public/icons/**`, `docs/implementation/build.md` |
| Versions verrouillées | Laravel 13.36.0, Sanctum 4.3.3, brick/math 1.0.0 (pur PHP), PHPUnit 12.5.39, Pint 1.32.1, Larastan 3.13.0, PHPStan 2.3.1 ; Vue 3.5.44, Vue Router 5.4.0, TypeScript 6.0.3, Vite 8.3.4, Tailwind 4.3.3, vite-plugin-pwa 2.0.0, Dexie 4.4.6, jsPDF 4.2.1, Vitest 5.0.4, Playwright 1.64.0, ESLint 10.12.0 |
| Tests exécutés | Voir F03 (mêmes exécutions) ; copie propre du dépôt (fichiers suivis seulement) : `composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build` réussis, assets identiques à ceux du dépôt de travail (mêmes empreintes) ; puis `composer install`, PHPUnit 96 tests (1 ignoré : pas de `.git`), Vitest, e2e réussis ; e2e aussi réussis avec `vendor/` de production ; `npm audit` et `composer audit` : aucune vulnérabilité |
| Défauts ouverts | CSP absente (le défaut D2 de la vérification, hors de ce lot, reste à planifier avec les en-têtes de sécurité) ; aucun `trustProxies` ni `TrustHosts` (adresse IP du client pour l'audit, URL générées depuis la requête : à décider avec l'audit de l'hébergeur, Q01) ; icônes provisoires (logo réel : Q03) ; avertissements EBADENGINE informatifs (Node 22.22.0 local, dépendances transitives de développement demandant 22.22.2) ; `glob@11.1.0` déprécié, transitif de `workbox-build` (développement seulement) ; `README.md` racine dit encore « le code applicatif reste à développer » (fichier non modifié) |
| Décision nouvelle | La coquille SPA est servie hors du groupe `web` (pas de session ni de cookie, précachable) ; le service worker et le manifeste sont servis à la racine par des routes Laravel ; la route CSRF de Sanctum est déplacée sous `/api/sanctum/csrf-cookie` ; `.gitignore` : les contenus de `storage/` sont ignorés par les `.gitignore` de chaque sous-dossier (suivis) afin que l'arborescence existe après un clone ; scripts Composer `test*` appellent PHPUnit directement (`artisan test` émet des avertissements sans `.env`) ; **la sonde `/up` est une route maison** (`HealthController`, JSON `{"status":"ok"}`, sans vue ni session) car le `health:` de Laravel rend une vue qui charge un script CDN et des fontes tierces sur l'origine de l'application ; analyse statique PHPStan niveau 8 + Larastan verrouillée (`phpstan.neon`, `composer run analyse`) ; **les assets de la coquille sont des chemins depuis la racine du site** (`Vite::createAssetPathsUsing`), indépendants du schéma, de l'hôte, de `X-Forwarded-*` et d'`ASSET_URL` : la coquille fonctionne derrière un proxy ou un CDN qui termine TLS sans `trustProxies` ; aucun écart d'architecture |
| Reprise | Re-vérification adverse de la sonde `/up`, de l'analyse statique et de la coquille derrière un proxy TLS, puis DONE ; poursuivre par F04 |

### F03 — Installer les contrôles CI et MySQL isolé

| Champ | Valeur |
| --- | --- |
| Statut | IN_REVIEW |
| Objectif | Contrôles de qualité PHP/TS/build, base MySQL de test isolée, garde contre toute destruction hors base de test, e2e sans fournisseur réel |
| Fichiers | `phpunit.xml`, `tests/bootstrap.php`, `tests/TestCase.php`, `tests/Support/RequiresIsolatedMysql.php`, `tests/{Unit,Feature,Concurrency,frontend,e2e}/**` (dont `tests/e2e/support/{browser,chromium,ports,tls-proxy}.ts`), `app/Support/Testing/**`, `app/Console/Commands/ResetTestDatabase.php`, `vitest.config.ts`, `playwright.config.ts`, `phpstan.neon`, `.github/workflows/quality.yml`, `tools/dev/mysql-up.sh`, `tools/dev/check-all.sh`, `tools/dev/seed-composer-cache.sh`, `tests/Feature/Database/MigrateRollbackGuardTest.php`, `tests/fixtures/migrations/**`, `docs/implementation/testing.md` |
| Tests exécutés | `tools/dev/check-all.sh --strict --with-e2e` (2026-10-14, MySQL 8.4.9 Docker, après corrections) : Pint réussi ; PHPStan niveau 8 + Larastan **0 erreur** ; PHPUnit **132 tests, 671 assertions, 0 échec** (Unit 28, Feature 101, Concurrency 3) ; `vue-tsc` strict sans erreur ; ESLint sans erreur ; Prettier conforme ; Vitest **30 tests** (3 fichiers) ; `vite build` réussi ; Playwright **13 tests** (Chromium local, `/opt/pw-browsers`). Preuves de la garde sur de vrais processus `php artisan` : `migrate:fresh` et `migrate:rollback` refusés en `production`, en `local` et en `testing` sur la base `eagle_eye_optics` (sans connexion à la base) ; `eeo:test-db:reset --migrate` et `migrate:fresh` acceptés sur `eeo_test`. Contrôles par mutation : désactiver l'écouteur ou le relais d'événements fait échouer 10 à 11 tests ; retirer `migrate:rollback` de la liste fait échouer 11 tests ; remettre le `health:` de Laravel fait échouer 6 tests PHP et le test e2e « aucune requête hors origine » ; retirer `/api` de la liste de refus du service worker fait échouer le test e2e de coupure ; retirer `Vite::createAssetPathsUsing` fait échouer les 12 tests de `SpaShellOriginTest` et les 4 tests e2e d'origine HTTPS |
| Défauts ouverts | `quality.yml` non exécuté sur GitHub (commandes équivalentes exécutées localement) : à constater après le premier push ; le mode hors ligne de Playwright ne couvre pas les requêtes du service worker, d'où l'arrêt réel du serveur dans le test e2e ; le parcours G1 n'existe pas encore ; CREATE TRIGGER exige `log_bin_trust_function_creators=1` ou `SUPER` quand le journal binaire est actif : prérequis à vérifier chez l'hébergeur (Q01) ; pas de couverture PHP (pcov/xdebug absents) ; les `down()` des migrations de F04 doivent refuser sur les tables métier (la garde de commande ne suffit pas) ; niveau PHPStan 8 (les niveaux 9 et 10 restent possibles) |
| Décision nouvelle | La garde est fermée par défaut (`APP_ENV=testing`, base `*_test`, hôte local, MySQL), couvre `migrate:fresh/refresh/reset/rollback` et `db:wipe`, et un test classe toute commande `migrate:*`/`db:*` enregistrée par Laravel ; Laravel ne relaie pas `CommandStarting` quand `APP_ENV=testing` : le fournisseur de services le relaie explicitement ; l'écouteur ne couvre pas les sous-commandes (`$this->call`) ni le SQL manuel (documenté) ; utilisateur de test `eeo_test` limité à `eeo_test` et `eeo_*_test` (mot de passe fictif) ; l'étape d'analyse statique du job `php` est ajoutée à `quality.yml` ; `tools/dev/seed-composer-cache.sh` rend `composer install` reproductible dans un bac à sable dont le réseau refuse l'API GitHub ; aucun écart d'architecture |
| Reprise | Re-vérification adverse des corrections, constater la première exécution GitHub Actions, puis passer F03 à DONE ; poursuivre par F04 |

## Corrections après vérification adverse (2026-10-14)

Trois défauts majeurs relevés par des vérificateurs indépendants (lentilles conformité à la spécification et frontend e2e) sur le point d'étape F02/F03. Chaque défaut a été reproduit **avant** correction, puis la commande qui échouait a été rejouée.

| Défaut | Correction | Preuve |
| --- | --- | --- |
| `migrate:rollback --force` exécutait `down()` et supprimait une table en `APP_ENV=production` sur une base sans `_test` (garde incomplète ; F03 : « destruction de base impossible avec configuration production ») | `migrate:rollback` ajouté à `BlockDestructiveDatabaseCommands::BLOCKED_COMMANDS` ; test qui classe explicitement toute commande `migrate:*`/`db:*` ; message de refus mis à jour ; `testing.md` liste commandes couvertes et limites ; contrat F04 : `down()` refusant sur tables métier | Avant : table `probe_metier` supprimée, code 0. Après : « Opération destructive refusée », code 1, table intacte, y compris `migrate:rollb`, `mi:rollback`, `--step=1`, en `local` et en `testing` sur une base non isolée. `MigrateRollbackGuardTest` (MySQL réel) ; mutation : 11 tests échouent sans le correctif |
| Aucune analyse statique PHP (spec 05 et 25 : `composer run lint` = format **et** analyse) ; la justification « Larastan non installable ici » était inexacte | `larastan/larastan` (3.13.0, PHPStan 2.3.1) en `require-dev` et verrouillé ; `phpstan.neon` niveau 8 ; scripts `analyse`, `lint:style` et `lint` (style puis analyse) ; étape dans `quality.yml` ; 4 constats Larastan corrigés dans le code (test `=== null` mort, `method_exists` toujours vrai, deux `env()` de type union booléen ou chaîne), code aussi sans constat sous PHPStan seul ; `tools/dev/seed-composer-cache.sh` pour le bac à sable | `composer run lint` : Pint réussi, PHPStan « No errors », code 0, y compris sur une copie sans `.env` reconstruite de zéro (`vendor/` absent, 111 paquets) ; contrôle négatif : un fichier fautif fait échouer `lint` (code 1) ; `composer audit` sans alerte |
| `/up` (vue par défaut du `health:` de Laravel) chargeait `cdn.jsdelivr.net` (script Tailwind non épinglé) et `fonts.bunny.net` sur l'origine de la SPA, du service worker et du futur coffre local | Route maison `GET /up` (`HealthController`) : JSON `{"status":"ok"}`, `Cache-Control: no-store`, 500 `{"status":"error"}` sans message d'exception, hors du groupe `web`, exemptée de la maintenance ; `health:` retiré ; test statique des vues Blade | Avant : requêtes `https://fonts.bunny.net/css?family=figtree…` et `https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4`. Après : aucune requête hors origine (Playwright, y compris service worker actif), `curl` 200 JSON sans « http », routes en cache (`route:cache`) conformes ; mutation : 6 tests PHP et l'e2e échouent avec l'ancien `health:` |

Points laissés ouverts (hors de ce lot) : CSP et en-têtes de sécurité (D2) ; niveau PHPStan 9/10 ; `quality.yml` toujours jamais exécuté sur GitHub.

## Seconde vérification adverse (2026-10-14) : coquille derrière un proxy TLS

Un vérificateur indépendant (lentille frontend e2e) a conclu `pass=false` sur un défaut majeur. Il a été reproduit **avant** correction, puis les commandes qui échouaient ont été rejouées.

| Défaut | Correction | Preuve |
| --- | --- | --- |
| Coquille vide (page blanche, service worker jamais enregistré) derrière un proxy ou un CDN qui termine TLS : `@vite` écrivait des URL d'assets **absolues** déduites du schéma et de l'hôte de la requête (`http://…/build/assets/main-….js`), bloquées par Chromium comme contenu mixte ; `APP_URL=https://…` (consigne de `build.md`) n'y changeait rien ; un en-tête `Host` arbitraire faisait pointer les assets vers cet hôte. Aucun test ni document ne couvrait le cas : tous les e2e tournent en `http://127.0.0.1`, que Chromium exempte des règles de contenu mixte (fichier 23 : « une seule origine HTTPS ») | `Vite::createAssetPathsUsing` dans `AppServiceProvider` : les assets sont des chemins depuis la racine (`/build/assets/…`), indépendants du schéma, de l'hôte, de `X-Forwarded-*`, de `trustProxies` et d'`ASSET_URL` (volontairement sans effet). Tests : `SpaShellOriginTest` (12 tests PHP, vrai Vite, manifeste jetable) et `tests/e2e/https-origin.spec.ts` (4 tests : serveur PHP en HTTP, proxy TLS Node avec certificat `openssl` jetable, Chromium sur `https://cabinet.eeo-e2e.test`) ; supports e2e factorisés (`tests/e2e/support/{browser,chromium,ports,tls-proxy}.ts`) ; `build.md` (rôle d'`APP_URL`, `ASSET_URL`, `trustProxies`, vérification de déploiement), `testing.md`, commentaire de `.env.example`. Pas de `trustProxies(at: '*')` : tout client pourrait falsifier `X-Forwarded-*` (adresse IP d'audit, hôte) | Avant : `curl -H 'X-Forwarded-Proto: https' /` → `src="http://127.0.0.1:8765/build/assets/main-BsVnD6L-.js"` ; `-H 'Host: evil.test'` → `http://evil.test/build/…` ; Chromium sur `https://cabinet.example.test:8443` (proxy TLS Node, `APP_URL` HTTPS) : `#app` de 0 caractère, 0 `h1`, `getRegistration()` nul, console « Mixed Content : … requested an insecure script … This request has been blocked ». Après : mêmes commandes → `/build/assets/main-BsVnD6L-.js`, y compris avec `Host` et `X-Forwarded-Host` arbitraires et sous `config:cache` + `route:cache` + `view:cache` en `APP_ENV=production` ; Chromium : `#app` de 987 caractères, 1 `h1`, service worker enregistré, console vide. Mutation : sans l'appel, les 12 tests PHP et les 4 tests e2e échouent. `check-all.sh --strict --with-e2e` : PHPUnit 132 tests / 671 assertions, Vitest 30, Playwright 13, Pint, PHPStan niveau 8, vue-tsc, ESLint, Prettier et build sans erreur |

Points laissés ouverts (hors de ce lot) : réglages dépendant de l'hébergeur (Q01) — `trustProxies` (adresse IP d'audit, URL générées depuis la requête) et éventuel `TrustHosts` ; le workflow GitHub Actions n'a toujours jamais été exécuté (le test d'origine HTTPS exige `openssl`, présent sur `ubuntu-24.04`).

## Durcissement après la troisième vérification (2026-10-14)

Les quatre vérificateurs indépendants concluent `pass=true` (reproductibilité, hygiène du dépôt public, conformité aux spécifications, frontend e2e) ; la CI GitHub Actions a tourné pour la première fois sur le point d'étape `9144e25` (run 38551182773 : jobs PHP, frontend et e2e réussis). Défauts mineurs restants corrigés tout de suite lorsqu'ils touchent la sécurité ou la confidentialité (fichier 09, « Protection de l'application ») :

| Défaut mineur relevé | Correction | Preuve |
| --- | --- | --- |
| Garde de base : `$` acceptait un nom avec saut de ligne final (`prod_test\n`) | Motif terminé par `\z` | 2 cas ajoutés à `IsolatedDatabaseGuardTest` |
| En-têtes de sécurité absents (CSP, iframe, nosniff), `X-Powered-By` visible | Middleware `SecurityHeaders` global : CSP d'origine unique sans `unsafe-inline`/`unsafe-eval`, `frame-ancestors 'none'`, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: same-origin`, `Permissions-Policy`, COOP, `X-Robots-Tag: noindex` ; CSP omise seulement quand le serveur Vite de développement est actif | `SecurityHeadersTest` ; e2e Playwright (13) sans erreur de console sous CSP |
| Réponses `/api` sans `Cache-Control: no-store` | `no-store, private` sur `/api` | `SecurityHeadersTest` |
| CORS par défaut du framework (`*` sur `/api`) | `config/cors.php` : aucun chemin CORS (application mono-origine) | Préflight d'une origine tierce sans `Access-Control-Allow-Origin` |
| `.gitignore` sans certificats, dumps ni sauvegardes | Règles ajoutées (`*.crt`, `*.p12`, `*.dump`, `*.sql.gz`, `/backups/`…) ; les scripts SQL du socle restent suivis | `git check-ignore` |
| `vueCompilerOptions.strictTemplates` absent ; `build.md` contradictoire sur `APP_URL` ; README racine obsolète | Activé ; texte corrigé ; README pointe vers ce journal | `vue-tsc` sans erreur |

`tools/dev/check-all.sh --strict --with-e2e` après durcissement : Pint, PHPStan niveau 8 (0 erreur), PHPUnit **138 tests / 714 assertions**, vue-tsc, ESLint, Prettier, Vitest 30, build, Playwright 13 ; `verifier_plan.py` : PASS, empreintes SQL validées inchangées.

Restent ouverts (non bloquants pour F02/F03, reportés) : `trustProxies`/`TrustHosts` selon l'hébergeur (Q01) ; affectation de masse de `User` (`clinic_id`, `password_hash`) à fermer en F05 ; preuve e2e « /api jamais en cache » avec une vraie route `/api` 200 (première route en F05) ; profil de configuration « recette » à documenter avec H01 ; `composer run test:contracts` et `npm run test:domain` (fichier 25) arrivent avec F06 et F08 ; avertissements EBADENGINE (Node 22.22.0 local).

### F04 — Traduire le schéma complet et prouver son équivalence

| Champ | Valeur |
| --- | --- |
| Statut | DONE |
| Objectif | 117 tables métier, 464 FK, 484 CHECK, 88 guards et 10 vues équivalents à la baseline ; 4 rôles, 49 permissions, 76 termes ; colonnes générées, charset, défauts et référentiels préservés ; SQL livré et migrations jamais exécutés deux fois |
| Fichiers | `database/tools/build_migrations.py`, `database/baseline/statements.json`, `database/baseline/metadata.json`, `database/migrations/2026_10_14_00000{0..4}_*.php`, `app/Support/Schema/*`, `app/Console/Commands/{VerifySchema,AdoptBaseline,SnapshotSchema}.php`, `tools/dev/schema-parity.sh`, `tools/dev/schema_parity_report.py`, `tests/Unit/Schema/*`, `tests/Feature/Schema/*`, `tests/Support/ScratchMysqlDatabases.php`, `docs/implementation/schema.md`, `docs/evidence/schema-parity.json`, `.github/workflows/quality.yml` |
| Contrats / tables | Toutes les tables du catalogue (baseline SHA-256 `73bd5dc4…2046e2761eb` = backlog) ; tables techniques `migrations`, `fw_sessions` (user_id ULID ASCII), `fw_cache`, `fw_cache_locks`, `fw_jobs`, `fw_job_batches`, `fw_failed_jobs` ; `fw_password_reset_tokens` non créée (pas de réinitialisation par e-mail à ce stade) |
| Tests exécutés | (1) `build_migrations.py --check` : les instructions redonnent octet pour octet `01_schema.sql`, `02_reference_data.sql`, `03_reporting_views.sql`, `04_history_guards.sql` et le fichier complet (CRLF ramenés à LF). (2) `tools/dev/schema-parity.sh` au commit `3fe6e93`, arbre propre, MySQL 8.4.9 : **22/22** — photographies import SQL / migrations identiques (0 écart, 0 avertissement de session), 41 assertions `mysql_smoke_tests.sql` et `mysql_metadata_tests.sql` passées sur la base **migrée**, adoption d'une base importée acceptée puis refusée une seconde fois, adoption refusée sans écriture pour un trigger supprimé, un CHECK supprimé et un référentiel modifié, `migrate` refusé sur base déjà importée avec renvoi à `eeo:schema:adopt`, second `migrate` sans effet, `migrate:rollback` refusé en production. (3) PHPUnit `tests/Unit/Schema` + `tests/Feature/Schema` : 32 tests (IT01 : perte d'une colonne générée, d'une FK, d'un CHECK ou d'un trigger détectée ; guard append-only réellement actif ; `down()` des 4 étapes refusant ; cohérence catalogue ↔ métadonnées colonne par colonne). (4) `check-all.sh --strict --with-e2e` : PHPUnit 170 / 20 560 assertions, PHPStan niveau 8 sans erreur, Vitest 30, Playwright 13 |
| Défauts ouverts | Preuve sur MySQL 8.4.9 uniquement : à rejouer sur la version réelle de l'hébergeur (Q01) ; `log_bin_trust_function_creators=1` ou SUPER requis pour créer les triggers quand le journal binaire est actif (Q01, `hosting-audit.md` point 8) ; `migrate --pretend` n'affiche pas les instructions de baseline (envoyées par PDO, voir `BaselineInstaller::execute`) |
| Décision nouvelle | Nom contractuel `php artisan eagle:verify-schema` (fichier 25), alias `eeo:schema:verify` ; attributs de session des triggers/vues (sql_mode, jeux de caractères) signalés comme avertissements et non comme écarts, car ils dépendent du client d'import (fichier complet ou scripts séparés) sans changer le corps des gardes ; tables techniques migrées **avant** la baseline pour qu'un retour arrière bute immédiatement sur le refus ; CI épinglée sur `mysql:8.4.9` (l'image flottante `mysql:8.4` a servi 8.4.11) |
| Reprise | G0 puis F05 |

### G0 — Valider installation et modèle de toute l'application

| Champ | Valeur |
| --- | --- |
| Statut | DONE |
| Preuve | `docs/evidence/G0.md` : équivalence relue (22/22), installation neuve reproductible depuis un clone frais du dépôt distant en mode production, application servie (sonde, coquille, API 404 JSON no-store, fichiers sensibles non exposés), CI GitHub verte, `.env.example` sans secret, aucune activation financière réelle |
| Manques pour la production | Q01–Q04, Q14 (et Q05–Q13, Q15 pour les lots concernés) : voir `G0.md` |
| Reprise | F05 |

### F05 — Adapter identité, permissions et bootstrap gérante

| Champ | Valeur |
| --- | --- |
| Statut | IN_REVIEW (revue de sécurité indépendante en cours) |
| Objectif | Quatre profils démontrables ; URL/API/PDF refusés au mauvais rôle ; session invalidée sans perte des pièces |
| Fichiers | `app/Domain/Access/*` (résolveur pur, permissions effectives, cabinet configuré, audit, profils, sessions, actions), `app/Domain/Shared/{Ulid,BusinessError}.php`, `app/Http/Errors/ApiErrorRenderer.php`, `app/Http/Middleware/{AssignCorrelationId,EnsureUserIsActive,RequireRecentReauthentication}.php`, `app/Http/Controllers/Api/V1/{AuthController,StaffController,ClinicSettingsController}.php`, `app/Models/{User,Clinic}.php`, `app/Console/Commands/{SetupClinic,CreateDemoAccounts}.php`, `lang/fr/validation.php`, `routes/api.php`, `docs/implementation/access.md` |
| Contrats / tables | `clinics`, `users`, `user_roles`, `user_permissions`, `roles`, `permissions`, `role_permissions`, `audit_events`, `fw_sessions` ; routes `/api/v1/auth/*`, `/staff*`, `/clinic/settings` ; enveloppe d'erreur du fichier 08 avec `correlation_id` |
| Tests exécutés | `check-all.sh --strict --with-e2e` (2026-10-14, MySQL 8.4.9) : PHPUnit **208 tests / 20 975 assertions** dont `tests/Unit/Access` (7) et `tests/Feature/Access` (30 : connexion, échecs indiscernables, 429, autre cabinet, 401/403/404/409/422/423, désactivation qui coupe les sessions `fw_sessions`, dernière gérante, profils et dérogations immédiats et expirés, réinitialisation, mise en service unique, comptes de démonstration aléatoires refusés en production) ; PHPStan niveau 8 sans erreur ; Vitest 30 ; Playwright **18** dont `auth-session.spec.ts` (session réelle + CSRF, `me` no-store, cookie HttpOnly, 419 sans Fetch Metadata/jeton et intersite, origine tierce sans session, 403 caisse / 200 gérante, `/api` authentifiée jamais servie hors ligne) |
| Défauts ouverts | Hors F05 par dépendance : verrouillage du coffre local (O01), refus PDF par rôle (D01/D02), écrans et garde de navigation (F09), idempotence des mutations de comptes (F07) ; MFA gérante non implémentée (décision avant accès d'administration distant) ; `trustProxies` selon l'hébergeur (Q01) |
| Décision nouvelle | CSRF : Laravel 13 accepte les écritures `Sec-Fetch-Site: same-origin` (Fetch Metadata) sinon exige `X-XSRF-TOKEN` — conservé, prouvé en e2e ; connexion refusée sans session SPA (400 `SESSION_REQUIRED`) ; réinitialisation par la gérante sans e-mail (pas de `fw_password_reset_tokens`) ; codes d'erreur ajoutés (`REAUTHENTICATION_REQUIRED` 423, `SESSION_REQUIRED` 400, `CLINIC_NOT_CONFIGURED` 503, `LAST_MANAGER_REQUIRED`/`SELF_*` 409) à reporter dans F06 ; chaîne `previous_hash` de l'audit non tenue (facultative, décision F07) |
| Reprise | Revue de sécurité indépendante, corrections, puis DONE ; ensuite F06 |
