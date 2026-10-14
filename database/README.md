# Base MySQL complète Eagle’s Eye Optics Medical

Ce dossier fournit le modèle de données de **toute l’application**, et son schéma SQL exécutable. Les 117 tables couvrent clinique, patients, facturation, caisse, stock/achats/inventaires, atelier, agenda, CRM/IA, communication, showroom, intégrations, hors-ligne et exploitation. Les échéances du planning ne filtrent aucune table.

Le modèle a été importé et vérifié sur MySQL Community Server 8.4.9 dans une base locale isolée. Les détails et limites figurent dans [VALIDATION](VALIDATION.md). Aucune base du cabinet n’a été modifiée et aucun compte réel n’a été créé.

## Fichiers à utiliser

| Fichier | Usage |
| --- | --- |
| [Schéma complet en un fichier](eagle_eye_optics_complet.sql) | Import unique réunissant tables, référentiels, vues et protections d’historique |
| [01 Schéma](01_schema.sql) | Toutes tables, PK/FK, index, CHECK et colonnes générées |
| [02 Référentiels](02_reference_data.sql) | Rôles/permissions, codes DP/PC, catégories et unités, sans données fictives de production |
| [03 Vues](03_reporting_views.sql) | Soldes, flux CA/encaissements, caisse, stock, consentements et synchronisation |
| [04 Historique](04_history_guards.sql) | Triggers de protection des pièces émises et événements immuables |
| [05 Diagnostics](05_integrity_checks.sql) | Contrôles de cohérence en lecture seule après commit |
| [00 Création facultative](00_create_database_optional.sql) | Créer une base vide si les permissions l’autorisent ; inutile sur une base fournie par l’hébergeur |
| [Catalogue canonique](schema_catalog.json) | Toutes colonnes, types, nullabilité, clés, contrôles et règles de garde |
| [Tests SQL](tests/mysql_smoke_tests.sql) | Données TEST en transaction annulée, uniquement sur base de validation isolée |
| [Audit metadata](tests/mysql_metadata_tests.sql) | Contrôle moteur/charset/types/FK/CHECK et nombre d’objets |

Les diagrammes métier sont dans le [MCD](../specifications/06-modele-conceptuel.md), les clés/relations dans le [MLD](../specifications/20-modele-relationnel.md), et les champs dans le [dictionnaire](../specifications/07-dictionnaire-donnees.md). [Les invariants](../specifications/21-invariants-base-donnees.md) séparent les garanties SQL des transactions applicatives obligatoires.

## Import sur hébergement web simple

1. Obtenir une **base vide** MySQL 8.4 avec son utilisateur et ses droits depuis le panneau de l’hébergeur. Nom fourni par l’hébergeur, éventuellement préfixé. Aucun VPS requis.
2. Vérifier InnoDB, utf8mb4, CREATE/ALTER/INDEX/REFERENCES, CREATE VIEW et TRIGGER. Le serveur cible et les droits réels doivent être audités avant production. MySQL 8.0.16+ peut être étudié et testé ; MariaDB ou SQLite ne sont pas déclarés compatibles sans validation.
3. Sélectionner cette base dans le client/phpMyAdmin. Importer `eagle_eye_optics_complet.sql` **ou** les fichiers 01 puis 02 puis 03 puis 04 ; ne pas faire les deux.
4. Exécuter 05 pour vérifier la cohérence des données après installation/reprise. Le diagnostic seul ne remplace pas les tests métier.
5. Faire créer le cabinet, ses paramètres, les comptes nominatifs et le catalogue réel par l’application/procédure de démarrage contrôlée. Avant cela, aucune émission réelle n’est possible sans identité/tarifs/régime validés.

Les scripts n’imposent ni nom de base dans un USE ni identifiants/passwords. Le script 00 est facultatif et distinct. Aucun script d’installation ne supprime une table ou ne réinitialise une base existante. Réimporter 01/02 sur une base déjà initialisée échoue volontairement : convertir ensuite le modèle en migrations Laravel non destructrices, sans masquer les divergences par IF NOT EXISTS ou INSERT IGNORE.

MySQL applique des commits implicites au DDL : un import interrompu peut laisser un schéma partiellement créé. Diagnostiquer en base de recette, corriger puis réimporter dans une nouvelle base vide ; ne pas supprimer des données de production pour « recommencer ». L’installation des triggers ne demande aucun changement global du serveur et n’installe ni service, ni routine métier, ni tâche EVENT.

## Données et conventions

ULID techniques immuables hors-ligne/serveur ; numéros métier disjoints et conservés après sync. FK composites `clinic_id` pour protéger les frontières de cabinet. Montants FCFA entiers, quantités/données optiques décimales exactes, UTC pour instants et DATE pour dates civiles. Plusieurs patients peuvent partager un téléphone.

Pièces financières et snapshots sont conservés ; corrections par objets liés. Soldes/CA/encaissements sont des projections. Une réservation n’est pas une sortie ; un avoir n’est pas un retour physique. Quotas de caisse hors-ligne sont séparés du stock réservé central, et une facture n’a qu’un rédacteur financier actif. Les règles complètes se trouvent dans 03/19/21.

Les référentiels seedés reprennent DP/PC, y compris indice 1.61 et HMC. Aucun prix, taux fiscal, patient, cabinet, mot de passe, coordonnées de laboratoire, template Meta approuvé ou secret n’est inventé. Le script des tests est séparé de l’installation et clairement TEST.

## Modifier le modèle sans divergence

Définition canonique : `tools/model_definition.py`. `tools/build_model.py` régénère 01, le catalogue JSON, le dictionnaire 07 et le MLD 20. `tools/reference_data.py` régénère 02 ; `tools/history_guards.py` régénère 04 depuis le catalogue. 03/05 restent des projections/diagnostics relus lorsque les colonnes changent.

Après modification : régénérer, vérifier les diagrammes 06 et invariants 21, importer dans une base isolée, lancer metadata/smoke/diagnostics, puis refaire le fichier complet et l’archive. Ne pas changer manuellement une colonne SQL sans reporter sa définition canonique et ses relations. Les changements futurs sur une installation existante nécessitent une migration versionnée spécifique.

## Ce qui reste à implémenter

Ce livrable est une base SQL et sa conception. Les transactions PHP/TypeScript, l’authentification du personnel, le coffre IndexedDB, les PDFs, les droits, signatures/grants et intégrations externes restent du développement applicatif. Les sommes/plafonds cumulés, chevauchements, autorisations liées aux hashes et consentements exacts se vérifient dans les commandes du fichier 21. Le SQL ne remplace pas cette logique.
