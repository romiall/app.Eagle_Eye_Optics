# Schéma de base : migrations fidèles de la baseline (F04)

La base de l'application est la baseline complète du socle : 117 tables métier, 464 clés étrangères, 484 CHECK, colonnes générées, 10 vues, 88 guards et les référentiels des sources (4 rôles, 49 permissions, 76 termes cliniques). Les migrations Laravel la reproduisent à l'identique ; elles ne contiennent aucun modèle simplifié.

## Chaîne de génération

| Élément | Rôle |
| --- | --- |
| `database/tools/model_definition.py` → `build_model.py` | Définition canonique → `01_schema.sql`, catalogue, MLD, dictionnaire (inchangés) |
| `database/tools/build_migrations.py` | Rend les instructions depuis `schema_catalog.json` (mêmes règles que `build_model.py` et `history_guards.py`), découpe `02`/`03` sur leurs frontières de génération, et **vérifie que leur concaténation redonne octet pour octet les scripts SQL validés** (CRLF ramenés à LF) |
| `database/baseline/statements.json` | Liste structurée produite : 117 CREATE TABLE, 111 ALTER TABLE (464 FK), 6 INSERT de référentiels, 10 vues, 88 CREATE TRIGGER, chacun une instruction PDO sans DELIMITER |
| `database/baseline/metadata.json` | Métadonnées MySQL attendues : photographie normalisée d'une base créée par import de `eagle_eye_optics_complet.sql` sur MySQL 8.4.9 |

Après toute modification de la définition canonique : régénérer le modèle (README de `database/`), puis `python3 database/tools/build_migrations.py`, puis `tools/dev/schema-parity.sh --write-baseline` sur une base de test, puis relire le diff de `metadata.json`. En CI, `build_migrations.py --check` échoue si `statements.json` ne correspond plus aux scripts.

Une installation existante ne se modifie jamais en réécrivant ces migrations : toute évolution passe par une **nouvelle migration additive versionnée**.

## Migrations

| Ordre | Migration | Contenu |
| --- | --- | --- |
| 0 | `2026_10_14_000000_create_framework_tables` | Tables techniques séparées : `fw_sessions` (user_id ULID ASCII), `fw_cache`, `fw_cache_locks`, `fw_jobs`, `fw_job_batches`, `fw_failed_jobs` ; `migrations` est créée par Laravel. `fw_password_reset_tokens` n'est pas créée (pas de réinitialisation par e-mail à ce stade) |
| 1 | `…_eeo_baseline_01_tables` | 117 tables, index, clés uniques, CHECK, colonnes générées |
| 2 | `…_eeo_baseline_02_foreign_keys` | 464 FK après toutes les tables (liens cycliques), aucune cascade |
| 3 | `…_eeo_baseline_03_reference_data_and_views` | Référentiels (transaction) et 10 vues `SQL SECURITY INVOKER` |
| 4 | `…_eeo_baseline_04_history_guards` | 88 triggers, un `CREATE TRIGGER` complet par instruction |

Chaque étape applique la session de `01_schema.sql` (`utf8mb4_0900_ai_ci`, UTC, `sql_mode` strict identique) puis restaure celle de la connexion : triggers et vues enregistrent les mêmes attributs que lors de l'import du fichier complet.

Contrôles préalables, sans `IF NOT EXISTS` ni `INSERT IGNORE` : l'étape 1 refuse une base contenant déjà une table métier et renvoie à `eeo:schema:adopt` ; les étapes 2 à 4 refusent une installation partielle (FK, référentiels, vues ou triggers déjà présents). MySQL valide implicitement chaque DDL : une installation interrompue se reprend **sur une base vide**, jamais en supprimant des données de production.

Toutes les méthodes `down()` de la baseline refusent ; celle des tables techniques n'agit qu'en base de test isolée. Les tables techniques étant migrées en premier, un `migrate:rollback` bute immédiatement sur la baseline sans rien supprimer.

## Commandes

| Commande | Usage |
| --- | --- |
| `php artisan migrate --force` | Installation normale sur une base neuve |
| `php artisan eagle:verify-schema [--json=rapport.json]` (alias `eeo:schema:verify`, nom contractuel du fichier 25) | Lecture seule : compare la base à `metadata.json` (tables, colonnes, index, CHECK, FK, vues, triggers, contenu des référentiels) ; inventorie à part les tables techniques ; code non nul au moindre écart |
| `php artisan eeo:schema:adopt [--dry-run] [--force]` | Base déjà créée par le SQL livré : vérification stricte, puis enregistrement des 4 migrations de baseline sans rien réimporter ; refus au moindre écart (rien n'est écrit) et refus d'une seconde adoption. Ensuite `php artisan migrate` crée les seules tables techniques |
| `php artisan eeo:schema:snapshot --output=…` | Photographie normalisée (lecture seule), sans date, nom de base ni DEFINER |

Les attributs de session des triggers et vues (sql_mode, jeux de caractères) dépendent du client d'import (fichier complet ou scripts 01–04 séparés). Ils sont signalés comme avertissements et non comme écarts : ils ne changent pas le corps des gardes.

## Preuve d'équivalence

`tools/dev/schema-parity.sh` (MySQL de développement ou de CI, bases jetables `eeo_*_test` uniquement) produit `docs/evidence/schema-parity.json` :

1. import client mysql du SQL livré dans une base neuve, `php artisan migrate` dans une autre ;
2. photographies comparées strictement, `eagle:verify-schema` sur les deux bases ;
3. `database/tests/mysql_metadata_tests.sql` et les 41 assertions de `mysql_smoke_tests.sql` sur la base **migrée** ;
4. adoption acceptée d'une base importée équivalente, refusée pour trois divergences (trigger supprimé, CHECK supprimé, référentiel modifié) sans aucune écriture ;
5. `migrate` refusé sur une base déjà importée, second `migrate` sans effet, retour arrière refusé en configuration production.

Les tests PHPUnit `tests/Unit/Schema` (instructions, comparateur, cohérence catalogue ↔ métadonnées) et `tests/Feature/Schema` (migrations, guards réels, adoption, refus) rejouent ces garanties à chaque exécution.

## Prérequis de l'hébergement

Droits CREATE, ALTER, INDEX, REFERENCES, CREATE VIEW et **TRIGGER** sur la base. Lorsque le journal binaire est actif, un utilisateur sans SUPER ne peut créer les triggers que si `log_bin_trust_function_creators=1` (réglage serveur à vérifier avec l'hébergeur, point 8 de `hosting-audit.md`). Le schéma est validé sur MySQL 8.4.9 ; une autre version (8.0.16+) peut normaliser différemment certaines métadonnées et doit être revalidée avec `schema-parity.sh` avant production. MariaDB est refusé par les migrations.
