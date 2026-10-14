# Journal d'exécution — Eagle's Eye Optics Medical

Initialisé le 2026-10-14 depuis `specifications/developpement/JOURNAL.md`. Le backlog canonique (`specifications/developpement/backlog.json`) n'est jamais modifié ni régénéré ; les statuts de travail sont dans `docs/implementation/task-status.json`.

## État courant

- Branche : `claude/developpement-l0-3pkhne` (dépôt public : données fictives uniquement).
- Lot et gate visés : L0, G0 puis G1.
- Tâche courante : F04 (migrations depuis le catalogue complet, 117 tables métier + tables `fw_*`).
- Dernière preuve acquise : socle applicatif F02/F03 exécuté localement (PHPUnit 96 tests, Vitest 30 tests, Playwright 5 tests, build, Pint, vue-tsc, ESLint, Prettier, audits). La validation SQL existante (`database/VALIDATION.md`) reste **distincte d'une recette applicative** ; aucun gate n'est atteint.
- Tâches DONE : F01, F02. IN_REVIEW : F03 (le workflow GitHub Actions n'a pas encore été exécuté sur GitHub).
- Blocages externes : Q01–Q04/Q14 (production seulement) ; développement fictif possible.
- Prochaine action concrète : F04 — quatre migrations déterministes (tables/index/CHECK/colonnes générées, FK, référentiels/vues, guards) et commande `eagle:verify-schema` ; vérifier avant tout que l'utilisateur MySQL de migration peut créer des triggers (journal binaire actif : `log_bin_trust_function_creators`, voir `testing.md`).

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
| Statut | DONE |
| Objectif | Laravel 13 intégré à la racine sans écraser les sources ; SPA Vue 3/TypeScript strict/Tailwind 4/PWA construite en fichiers statiques ; dépendances verrouillées ; aucun serveur Node en production |
| Fichiers | `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `vite.config.ts`, `tsconfig.json`, `.env.example`, `config/eeo.php`, `config/{session,cache,queue,auth,database,sanctum,filesystems}.php`, `bootstrap/app.php`, `routes/{api,spa,console}.php`, `app/Http/Controllers/SpaController.php`, `app/Models/User.php`, `resources/frontend/**`, `resources/views/spa.blade.php`, `public/icons/**`, `docs/implementation/build.md` |
| Versions verrouillées | Laravel 13.36.0, Sanctum 4.3.3, brick/math 1.0.0 (pur PHP), PHPUnit 12.5.39, Pint 1.32.1 ; Vue 3.5.44, Vue Router 5.4.0, TypeScript 6.0.3, Vite 8.3.4, Tailwind 4.3.3, vite-plugin-pwa 2.0.0, Dexie 4.4.6, jsPDF 4.2.1, Vitest 5.0.4, Playwright 1.64.0, ESLint 10.12.0 |
| Tests exécutés | Voir F03 (mêmes exécutions) ; copie propre du dépôt (fichiers suivis seulement) : `composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build` réussis, assets identiques à ceux du dépôt de travail (mêmes empreintes) ; puis `composer install`, PHPUnit 96 tests (1 ignoré : pas de `.git`), Vitest, e2e réussis ; e2e aussi réussis avec `vendor/` de production ; `npm audit` et `composer audit` : aucune vulnérabilité |
| Défauts ouverts | Larastan non installable ici (archive GitHub de `phpstan/phpstan` refusée par la politique réseau, 403) : script `analyse` absent ; icônes provisoires (logo réel : Q03) ; avertissements EBADENGINE informatifs (Node 22.22.0 local, dépendances transitives de développement demandant 22.22.2) ; `glob@11.1.0` déprécié, transitif de `workbox-build` (développement seulement) ; `README.md` racine dit encore « le code applicatif reste à développer » (fichier non modifié) |
| Décision nouvelle | La coquille SPA est servie hors du groupe `web` (pas de session ni de cookie, précachable) ; le service worker et le manifeste sont servis à la racine par des routes Laravel ; la route CSRF de Sanctum est déplacée sous `/api/sanctum/csrf-cookie` ; `.gitignore` : les contenus de `storage/` sont ignorés par les `.gitignore` de chaque sous-dossier (suivis) afin que l'arborescence existe après un clone ; scripts Composer `test*` appellent PHPUnit directement (`artisan test` émet des avertissements sans `.env`) ; aucun écart d'architecture |
| Reprise | F03 en revue ; poursuivre par F04 |

### F03 — Installer les contrôles CI et MySQL isolé

| Champ | Valeur |
| --- | --- |
| Statut | IN_REVIEW |
| Objectif | Contrôles de qualité PHP/TS/build, base MySQL de test isolée, garde contre toute destruction hors base de test, e2e sans fournisseur réel |
| Fichiers | `phpunit.xml`, `tests/bootstrap.php`, `tests/TestCase.php`, `tests/Support/RequiresIsolatedMysql.php`, `tests/{Unit,Feature,Concurrency,frontend,e2e}/**`, `app/Support/Testing/**`, `app/Console/Commands/ResetTestDatabase.php`, `vitest.config.ts`, `playwright.config.ts`, `.github/workflows/quality.yml`, `tools/dev/mysql-up.sh`, `tools/dev/check-all.sh`, `docs/implementation/testing.md` |
| Tests exécutés | `tools/dev/check-all.sh --strict --with-e2e` (2026-10-14, MySQL 8.4.9 Docker) : Pint réussi ; PHPUnit **96 tests, 448 assertions, 0 échec** (Unit 28, Feature 65, Concurrency 3) ; `vue-tsc` strict sans erreur ; ESLint sans erreur ; Prettier conforme ; Vitest **30 tests** (3 fichiers) ; `vite build` réussi ; Playwright **5 tests** (Chromium local, `/opt/pw-browsers`). Preuves de la garde sur de vrais processus `php artisan` : `migrate:fresh` refusé en `production`, en `local` et en `testing` sur la base `eagle_eye_optics` (sans connexion à la base) ; `eeo:test-db:reset --migrate` et `migrate:fresh` acceptés sur `eeo_test`. Contrôles par mutation : désactiver l'écouteur ou le relais d'événements fait échouer 10 à 11 tests ; retirer `/api` de la liste de refus du service worker fait échouer le test e2e de coupure |
| Défauts ouverts | `quality.yml` non exécuté sur GitHub (commandes équivalentes exécutées localement) : à constater après le premier push ; le mode hors ligne de Playwright ne couvre pas les requêtes du service worker, d'où l'arrêt réel du serveur dans le test e2e ; le parcours G1 n'existe pas encore ; CREATE TRIGGER exige `log_bin_trust_function_creators=1` ou `SUPER` quand le journal binaire est actif : prérequis à vérifier chez l'hébergeur (Q01) ; pas de couverture PHP (pcov/xdebug absents) |
| Décision nouvelle | La garde est fermée par défaut (`APP_ENV=testing`, base `*_test`, hôte local, MySQL) ; Laravel ne relaie pas `CommandStarting` quand `APP_ENV=testing` : le fournisseur de services le relaie explicitement ; l'écouteur ne couvre pas les sous-commandes (`$this->call`) ni le SQL manuel (documenté) ; utilisateur de test `eeo_test` limité à `eeo_test` et `eeo_*_test` (mot de passe fictif) ; aucun écart d'architecture |
| Reprise | Constater la première exécution GitHub Actions, puis passer F03 à DONE ; poursuivre par F04 |

