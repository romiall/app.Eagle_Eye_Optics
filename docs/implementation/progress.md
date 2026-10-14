# Journal d'exécution — Eagle's Eye Optics Medical

Initialisé le 2026-10-14 depuis `specifications/developpement/JOURNAL.md`. Le backlog canonique (`specifications/developpement/backlog.json`) n'est jamais modifié ni régénéré ; les statuts de travail sont dans `docs/implementation/task-status.json`.

## État courant

- Branche : `claude/developpement-l0-3pkhne` (dépôt public : données fictives uniquement).
- Lot et gate visés : L0, G0 puis G1.
- Tâche courante : F02.
- Dernière preuve acquise : validation SQL existante (`database/VALIDATION.md`), **pas une recette applicative**.
- Tâches DONE : F01.
- Blocages externes : Q01–Q04/Q14 (production seulement) ; développement fictif possible.
- Prochaine action concrète : F02 — intégrer Laravel 13 à la racine sans écraser CLAUDE.md, specifications/, database/.

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
