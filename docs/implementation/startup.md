# F01 — Baseline et contraintes de lancement

Date : 2026-10-14. Tâche F01 (lot L0). Aucun code applicatif n'est encore écrit ; ce document consigne l'inventaire et l'audit de l'environnement de développement cloud.

## Autorités lues

README, CLAUDE.md, 00, 01, 03, 05, 08, 09, 15, 19, 21, 22, 23 (lus intégralement ou par sections utiles), 24 (fiches F01–G0 lues, les suivantes lues à la prise de tâche), 25 et `database/README.md`. Les trois sources normatives restent PC, CDC et DP ; Gemini est un contexte. Les originaux `.docx/.pdf`, `.spec-work/` et l'archive zip racine ne sont pas modifiés.

## Inventaire du dépôt (non modifié)

| Élément | État |
| --- | --- |
| `specifications/` 00–25, `verification.json`, `sources/` | Présents, intacts |
| `specifications/developpement/backlog.json` | 79 tâches, tous statuts NOT_STARTED, catalogue SHA-256 `73bd5dc4…2046e2761eb`, 117 tables |
| `database/` | SQL complet (01–05), catalogue canonique, générateurs Python, tests SQL, rapport de validation |
| `tools/sync-local.ps1` | Synchronisation cloud → poste Windows de l'utilisateur (fast-forward de la branche de travail) |

## Décision d'architecture (contrats 23 retenus)

Laravel monolithe modulaire + MySQL 8.4 ; SPA Vue 3/TypeScript/Tailwind dans `resources/frontend/`, build statique servi par Laravel ; Sanctum SPA ; Dexie/IndexedDB chiffré ; jsPDF local. Aucun serveur Node permanent, aucun VPS (D05), hors-ligne dès L0 (D06). **Aucun écart d'architecture constaté à ce stade.**

Écart de versions à noter : le plan cible PHP 8.4 ; l'environnement de développement fournit PHP 8.3.6. Laravel 13 accepte PHP 8.3, la cible 8.4 reste à confirmer avec l'offre d'hébergement (Q01). Le code ne doit pas utiliser de fonctionnalité exclusive à 8.4 tant que l'hébergement n'est pas connu.

## Audit de l'environnement local (cloud)

| Composant | Constat | Conséquence |
| --- | --- | --- |
| PHP | 8.3.6 CLI (NTS). Extensions présentes : intl, mbstring, pdo_mysql, mysqli, sodium, openssl, zip, gd, xml, curl, fileinfo, tokenizer | Suffisant pour Laravel 13 |
| PHP manquant | `bcmath`, `gmp`, `pcov`, `xdebug` | Arithmétique décimale exacte : utiliser une bibliothèque pure PHP (ex. brick/math, mode PHP) ou entiers ; ne pas dépendre de bcmath/gmp, absents aussi probablement chez un hébergeur simple. Pas de mesure de couverture PHP sans pcov |
| Composer | 2.8.12 ; Packagist accessible | Laravel 13.36.0 disponible |
| Node / npm | 22.22.0 / 10.9.4 ; registre npm accessible | Build, Vitest, Playwright uniquement (dev/CI) |
| MySQL | Pas de serveur natif ; téléchargements `cdn.mysql.com`, `dev.mysql.com`, `repo.mysql.com` refusés (403 par la politique réseau). **MySQL 8.4.9 obtenu via Docker** (`mirror.gcr.io/library/mysql:8.4.9`, Docker Hub limité en débit 429) | Version identique à la preuve SQL. Le conteneur est éphémère : script de démarrage à fournir en F03 |
| Docker | 29.8.2 ; le démon doit être lancé à la main (`dockerd`) à chaque session | Consigné ; le journal note la procédure de reprise |
| Navigateur | Chromium Playwright 1194 dans `/opt/pw-browsers` | Playwright utilisable avec `executablePath` ; ne pas lancer `playwright install` |
| Ressources | 4 CPU, 16 Go RAM, ~29 Go disque libre | Suffisant |
| sql_mode MySQL | STRICT_TRANS_TABLES, ONLY_FULL_GROUP_BY, NO_ZERO_DATE… (défaut 8.4) ; `lower_case_table_names=0` ; row format dynamic | À conserver dans les tests de schéma |
| Git | Branche de travail `claude/developpement-l0-3pkhne` (l'ancienne `claude/developpement-l0` existe aussi sur le dépôt distant) | Tous les commits sont poussés sur la branche désignée de la session |

## Audit de l'offre d'hébergement (Q01) — non réalisable

Aucun accès à l'offre, au panneau, à PHP/MySQL, au cron ni aux droits de base de données du futur hébergeur n'est disponible. Voir `hosting-audit.md` pour la checklist à exécuter. **Ce n'est pas un défaut bloquant local ; c'est un prérequis de production (G4).**

## Distinction : bloquants locaux vs informations avant production

| Catégorie | Éléments |
| --- | --- |
| Défaut bloquant local | Aucun. MySQL 8.4.9 disponible par Docker ; Playwright/Chromium disponible |
| Limites d'environnement à surveiller | Docker à relancer ; pas de pcov/xdebug ; pas de bcmath/gmp ; domaines MySQL officiels bloqués |
| Q01 (hébergeur, PHP/MySQL, cron, quotas) | Ouvert — audit avant G4 |
| Q02 (postes, navigateurs, imprimantes, pannes) | Ouvert — scénario de référence : un PC Chrome/Edge préparé |
| Q03 (mentions légales, logo, régime taxe, séries papier) | Ouvert — documents marqués TEST, `production_documents_enabled=false` |
| Q04 (catalogue, prix, stock réels) | Ouvert — catalogue fictif de test uniquement |
| Q14 (sauvegarde distante, récupération locale, support) | Ouvert — runbooks et exercices en environnement de test |

## Garanties de préparation

- Aucun VPS prévu ; aucune donnée réelle ; données fictives uniquement (le dépôt GitHub est **public** : recommander à l'utilisateur de le passer en privé).
- `.env` jamais versionné ; seul `.env.example` le sera. Le conteneur MySQL de développement utilise un mot de passe racine jetable local, jamais committé dans la configuration applicative.
- Intégrations : doubles de test uniquement, aucun message réel.
