# Exercice de restauration serveur (X03 ; AT27)

**État : NON EXÉCUTÉ.** Ce document est le modèle de preuve à compléter lors de l'exercice sur l'hébergement cible
(Hostinger, accès SSH), puis chaque mois. Aucune valeur ci-dessous n'est constatée tant qu'une ligne `restore_tests`
de statut `PASSED` ne la justifie pas. Procédure : `docs/runbooks/server-restore.md`.

Le code est prêt : sauvegarde chiffrée et journal `backup_runs` (`tools/deploy/backup.sh`, `eeo:backup:record`),
restauration sur base jetable et contrôles (`tools/deploy/restore-check.sh`, `eeo:restore:verify`), tests
`tests/Feature/Operations/RestoreVerifierTest.php` (base MySQL isolée de test, données fictives).

## Exercice

| Élément | Valeur constatée |
| --- | --- |
| Date et personne (`--tested-by`) | à compléter |
| Sauvegarde restaurée (`backup_runs.id`, fin) | à compléter |
| Clé de chiffrement utilisée (identifiant) et sa copie hors serveur vérifiée | à compléter |
| Base jetable | `<base>_restore` |
| Ligne `restore_tests` | à compléter |
| Durée de restauration constatée (RTO) | à compléter (objectif proposé : 4 h) |
| Perte constatée (RPO) : reçus / factures émises absents, fenêtre | à compléter (objectif proposé : ≤ 24 h avec sauvegarde quotidienne) |
| Dernier reçu retrouvé | à compléter |

## Contrôles

| Contrôle | Résultat | Écart et décision |
| --- | --- | --- |
| SCHEMA_TABLES | à compléter | |
| INTEGRITY | à compléter | |
| SEQUENCES | à compléter | |
| NUMBERED_DOCUMENTS | à compléter | |
| AUDIT_HASHES | à compléter | |
| STORED_FILES | à compléter | |
| OFFLINE_RECONCILIATION | à compléter | |

## Écarts connus avant l'exercice

- Sauvegarde quotidienne seulement : un RPO d'une heure exige des sauvegardes incrémentales ou binlogs, à vérifier
  sur l'offre (décision d'hébergement, fichier 09).
- La copie hors serveur reste manuelle (`scp`, étape 7 de `docs/implementation/deploiement.md`).
- La restauration d'un poste hors-ligne (export chiffré local, AT44) relève d'O07, non couverte ici.
