# Exports contrôlés (X02)

Module `settings`, EN LIGNE. Code : `app/Domain/Operations/{ExportPlan,OperationsCommands}.php`,
`Handlers/RequestExportHandler.php`, `app/Support/Exports/ExportBuilder.php` (effets externes, hors domaine),
`app/Console/Commands/ProcessExports.php`, `app/Http/Controllers/Api/V1/ExportController.php`, écrans
`pages/settings/ExportsPage.vue` et `pages/patients/ExportRecordButton.vue`. État : codé le 2026-10-23, vérifié à la
passe de fin de lot.

## Parcours

1. Demande (`POST /api/v1/exports`, commande centrale `RequestExport`, mot de passe confirmé récemment : 423 sinon).
   Le dossier d'un patient (`PATIENT_RECORD`) exige `clinical.export` (gérante, professionnel) et se demande depuis
   la fiche du patient ; `PATIENTS`, `FINANCE`, `STOCK` et `FULL_HANDOVER` exigent `export.global` (gérante).
2. Production par la tâche planifiée `eeo:exports:process` (cron `schedule:run` chaque minute, jamais dans la
   requête : délai d'exécution court d'un hébergement simple). Archive ZIP PRIVÉE
   (`storage/app/private/exports/<cabinet>/<export>.zip`, `stored_files`, classement MEDICAL, FINANCIAL ou
   INTERNAL) : un CSV par table (`;`, UTF-8 avec BOM), `dictionnaire.csv` tiré de `database/schema_catalog.json`,
   `manifeste.json` (lignes et empreinte SHA-256 de chaque fichier), `LISEZMOI.txt` ; pièces privées pour la seule
   remise complète. Échec : FAILED avec le code `EXPORT_FAILED`, jamais un message qui contiendrait des données.
3. Téléchargement privé (`GET /api/v1/exports/{id}/download`) pendant 48 h : la gérante pour tout export du
   cabinet, un autre compte pour ses propres exports ; tracé (`export.downloaded`), sans cache. Ensuite EXPIRED :
   fichier supprimé, métadonnées gardées ; une archive expirée ou pas prête répond 409 avec `current_status`.

## Règles du plan (`ExportPlan`)

- Dossier d'un patient : identité, visites, consultations et toutes leurs rubriques, révisions, prescriptions et
  leurs lignes, ordonnances de médicaments, contrôles recommandés ; filtré par patient, consultation ou
  prescription : aucun autre patient.
- Jamais exportés : `idempotency_requests`, `sync_commands`, `sync_command_results`, `webhook_inbox`,
  `outbox_events` ; colonnes `password_hash`, `mfa_secret_ciphertext`, `remember_token`, chemins privés et clés de
  chiffrement des fichiers, jetons de synchronisation et de réservation publique, destination privée des sauvegardes.
- Le manifeste dit qu'un export du serveur ne contient pas les saisies encore en attente sur un poste hors ligne.

## Reste

Filtres par période, chiffrement de l'archive au repos, copie hors serveur des exports (les sauvegardes
quotidiennes le sont déjà par `tools/deploy/backup.sh`), table `backup_runs` alimentée par la sauvegarde.
Tests écrits : `tests/Feature/Operations/ExportsTest.php`.

# Sauvegardes chiffrées et restauration vérifiée (X03)

Code : `app/Domain/Operations/RestoreVerifier.php`, `app/Console/Commands/{VerifyRestore,RecordBackupRun}.php`,
`tools/deploy/{backup,restore-check}.sh` ; procédure `docs/runbooks/server-restore.md` ; preuve à compléter
`docs/evidence/restore-rehearsal.md`. État : codé le 2026-10-23, non exécuté (vérification de fin de lot ; exercice
réel sur l'hébergement à faire).

- `backup.sh` chiffre base, fichiers et `.env` avec `EEO_BACKUP_KEY_FILE` (openssl AES-256, PBKDF2) et consigne chaque
  partie dans `backup_runs` (`eeo:backup:record` : empreinte, taille, identifiant de clé, jamais la clé). Sans clé :
  sauvegarde en clair consignée `FAILED` / `NOT_ENCRYPTED` (fichier 09 : copie chiffrée obligatoire).
- `restore-check.sh` déchiffre, restaure dans une base jetable (`_restore`/`_test`), vérifie le schéma, extrait les
  fichiers privés dans un dossier temporaire et lance `eeo:restore:verify`.
- `eeo:restore:verify <base>` (refuse la base de l'application) : tables du catalogue, diagnostics
  `database/05_integrity_checks.sql` (zéro ligne), séquences jamais remises à zéro (≥ numéros consommés et fin des
  plages des postes), pièces émises liées à leur consommation de numéro, empreintes d'audit recalculées, fichiers
  privés et empreintes, postes à réconcilier (plages, quotas, commandes non conclues : alerte), dernier reçu, perte
  mesurée contre la base vivante et durée ; `--record` consigne `restore_tests` (sauvegarde réussie et compte exigés)
  et l'audit `restore.tested`.
- Tests écrits : `tests/Feature/Operations/RestoreVerifierTest.php`.

| Point | Décision / état |
| --- | --- |
| RPO d'une heure (fichier 09) | Non atteint par une sauvegarde quotidienne : sauvegardes incrémentales ou binlogs à vérifier sur l'offre. |
| Copie hors serveur | Manuelle (`scp`), à automatiser si l'offre le permet. |
| Restauration d'un poste hors-ligne (AT44) | O07, non couverte ici. |
