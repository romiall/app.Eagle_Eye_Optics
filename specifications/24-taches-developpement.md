# Tâches détaillées de développement

Version 3.0, 14 octobre 2026. Généré depuis [construire_backlog.py](developpement/construire_backlog.py), avec le même contenu que [backlog.json](developpement/backlog.json). Les chemins de code ci-dessous sont des **fichiers à créer**, pas des fichiers applicatifs déjà livrés.

79 tâches, dont 52 pour L0 et ses gates. Tous les statuts sont NOT_STARTED ; les vérifications présentes dans ce dossier portent sur la cohérence du plan, pas sur les tests futurs. Le [plan 22](22-plan-developpement-detaille.md) et les [contrats 23](23-contrats-ingenierie.md) s’appliquent à chaque fiche.

## Ordre exécutable

| Ordre | Tâche | Lot | Prérequis directs | Risque |
| --- | --- | --- | --- | --- |
| 1 | [F01 Confirmer la baseline et les contraintes de lancement](#f01) | L0 | Aucun | NORMAL |
| 2 | [F02 Créer le dépôt applicatif et verrouiller les dépendances](#f02) | L0 | F01 | NORMAL |
| 3 | [F03 Installer les contrôles CI et MySQL isolé](#f03) | L0 | F02 | NORMAL |
| 4 | [F04 Traduire le schéma complet et prouver son équivalence](#f04) | L0 | F03 | HIGH |
| 5 | [G0 Valider installation et modèle de toute l’application](#g0) | L0 | F04 | HIGH |
| 6 | [F05 Adapter identité, permissions et bootstrap gérante](#f05) | L0 | G0 | HIGH |
| 7 | [F06 Matérialiser les contrats et versions](#f06) | L0 | F02, F04 | NORMAL |
| 8 | [F07 Construire le chemin central de commandes](#f07) | L0 | F05, F06 | HIGH |
| 9 | [F08 Établir règles pures et vecteurs PHP/TypeScript](#f08) | L0 | F06 | HIGH |
| 10 | [F09 Construire shell responsive et composants réutilisés](#f09) | L0 | F02 | NORMAL |
| 11 | [F10 Définir lectures locales et états de synchronisation](#f10) | L0 | F06, F09 | NORMAL |
| 12 | [O01 Implémenter le coffre et les compartiments](#o01) | L0 | F05, F09 | HIGH |
| 13 | [O02 Enrôler le poste et émettre les grants signés](#o02) | L0 | O01, F07 | HIGH |
| 14 | [O03 Allouer plages, propriétaires et quotas sans chevauchement](#o03) | L0 | O02, F08 | HIGH |
| 15 | [O04 Implémenter le commit local et journal commun au poste](#o04) | L0 | O03, F10 | HIGH |
| 16 | [P01 Créer et modifier patients dans les deux moteurs](#p01) | L0 | O04, F07 | NORMAL |
| 17 | [P02 Rechercher patients et ouvrir identité sans ressaisie](#p02) | L0 | P01 | NORMAL |
| 18 | [B01 Créer catalogue et paramètres prix/taxes](#b01) | L0 | P01, F08, O03 | NORMAL |
| 19 | [B02 Implémenter les brouillons de facture](#b02) | L0 | B01, F07, O04 | NORMAL |
| 20 | [I00 Préparer le service de réserve utilisé dès l’émission](#i00) | L0 | B01, O03, F07 | HIGH |
| 21 | [B03 Émettre facture immuable et propriété locale](#b03) | L0 | B02, I00 | HIGH |
| 22 | [B05 Ouvrir et clôturer les sessions de caisse](#b05) | L0 | B03, F05 | HIGH |
| 23 | [B04 Encaisser acompte et solde avec reçu unique](#b04) | L0 | B03, B05 | HIGH |
| 24 | [O05 Implémenter push/pull, ACK et conflits durables](#o05) | L0 | P01, B04, O04 | HIGH |
| 25 | [D01 Générer et conserver les PDFs financiers hors-ligne](#d01) | L0 | B04, F09 | HIGH |
| 26 | [G1 Prouver la tranche urgente persistante et rejouable](#g1) | L0 | O05, D01, P02 | HIGH |
| 27 | [B06 Implémenter approbations exactes et réauthentification](#b06) | L0 | B04, F05 | HIGH |
| 28 | [B07 Contre-écrire paiement et émettre avoir lié](#b07) | L0 | B06 | HIGH |
| 29 | [B08 Rembourser le crédit et conserver sa pièce](#b08) | L0 | B07, B05 | HIGH |
| 30 | [I01 Gérer réservations et remises sous quota](#i01) | L0 | I00, B03, B06, O03 | HIGH |
| 31 | [I02 Enregistrer retour physique et sorties exceptionnelles](#i02) | L0 | I01, B07 | HIGH |
| 32 | [O06 Sécuriser mises à jour PWA et schéma local](#o06) | L0 | G1 | HIGH |
| 33 | [O07 Exporter et restaurer le poste sans cloner ses droits](#o07) | L0 | O06 | HIGH |
| 34 | [G2 Valider sécurité financière, stock et reprise](#g2) | L0 | B08, I02, O07, O05 | HIGH |
| 35 | [P03 Créer visite, file du jour et contrôle paiement examen](#p03) | L0 | P01, B04, B06 | NORMAL |
| 36 | [C01 Saisir toutes rubriques narratives du dossier DP](#c01) | L0 | P03, F08 | NORMAL |
| 37 | [C02 Saisir acuités, autorefracteur et réfraction distinctes](#c02) | L0 | C01 | NORMAL |
| 38 | [C03 Finaliser, corriger et comparer les révisions](#c03) | L0 | C02 | HIGH |
| 39 | [D02 Compléter templates et validation visuelle des documents](#d02) | L0 | D01, C03, B08 | NORMAL |
| 40 | [C04 Prescrire optique, médicaments et orientation](#c04) | L0 | C03, D02 | NORMAL |
| 41 | [P04 Vérifier parcours entre profils et postes préparés](#p04) | L0 | C04, G1, O07 | HIGH |
| 42 | [S01 Configurer et utiliser l’agenda interne](#s01) | L0 | P03, F07 | NORMAL |
| 43 | [S02 Calculer suivis et tâches de contact internes](#s02) | L0 | C04, S01 | NORMAL |
| 44 | [R01 Afficher soldes, caisse et finance avec fraîcheur](#r01) | L0 | B08, B05, I02, O05 | NORMAL |
| 45 | [R02 Afficher statistiques DP et activité patient](#r02) | L0 | C03, S02 | NORMAL |
| 46 | [X01 Importer catalogue, stock et créances papier contrôlés](#x01) | L0 | B04, B08, G1 | HIGH |
| 47 | [X02 Exporter données et sauvegarder base/fichiers/clés](#x02) | L0 | O07, F07, D02, X01 | NORMAL |
| 48 | [X03 Restaurer et réconcilier l’environnement serveur](#x03) | L0 | X02 | HIGH |
| 49 | [U01 Corriger l’usage et les impressions sur appareils ciblés](#u01) | L0 | P04, R01, R02, S02, D02 | NORMAL |
| 50 | [G3 Exécuter toute la recette L0 et figer le candidat](#g3) | L0 | G2, C04, P04, S02, R01, R02, X01, X03, U01 | HIGH |
| 51 | [H01 Déployer sur hébergement simple et préparer la bascule](#h01) | L0 | G3 | HIGH |
| 52 | [G4 Recevoir la première livraison opérationnelle](#g4) | L0 | H01 | HIGH |
| 53 | [L1D01 Gérer fournisseurs et réceptions validées](#l1d01) | L1 | G4 | NORMAL |
| 54 | [L1D02 Compter inventaires et valoriser les écarts](#l1d02) | L1 | L1D01 | HIGH |
| 55 | [L1D03 Activer seuils, péremption et supervision stock](#l1d03) | L1 | L1D02 | NORMAL |
| 56 | [L1D04 Implémenter atelier et bon laboratoire](#l1d04) | L1 | G4, L1D01 | HIGH |
| 57 | [L1D05 Ajouter dépenses et clôture avancée](#l1d05) | L1 | G4, B05 | NORMAL |
| 58 | [L1D06 Rapprocher MoMo/virements et exporter comptabilité](#l1d06) | L1 | L1D05 | NORMAL |
| 59 | [L1D07 Compléter devis versionnés et conversion en facture](#l1d07) | L1 | G4, B02, D02 | NORMAL |
| 60 | [L1D08 Fusionner identités et approfondir comparaison clinique](#l1d08) | L1 | G4, C03 | HIGH |
| 61 | [L1D09 Compléter tableaux et exports de gestion](#l1d09) | L1 | L1D03, L1D04, L1D06, L1D07, L1D08 | NORMAL |
| 62 | [G5 Recevoir stock, atelier et gestion avancée](#g5) | L1 | L1D09 | NORMAL |
| 63 | [L2D01 Construire adaptateurs et supervision des intégrations](#l2d01) | L2 | G5, F07 | HIGH |
| 64 | [L2D02 Synchroniser Google Calendar et les disponibilités](#l2d02) | L2 | L2D01, S01 | HIGH |
| 65 | [L2D03 Raccorder Kommo et miroir commercial minimal](#l2d03) | L2 | L2D01 | NORMAL |
| 66 | [L2D04 Versionner FAQ et tarifs publics autorisés](#l2d04) | L2 | L2D01 | NORMAL |
| 67 | [L2D05 Développer assistant limité et reprise humaine](#l2d05) | L2 | L2D02, L2D03, L2D04 | HIGH |
| 68 | [L2D06 Gérer consentements et templates versionnés](#l2d06) | L2 | L2D01, S02 | HIGH |
| 69 | [L2D07 Activer rappels et notifications transactionnelles](#l2d07) | L2 | L2D06, L2D02, L1D04 | HIGH |
| 70 | [L2D08 Planifier campagnes ciblées et relances contrôlées](#l2d08) | L2 | L2D07 | HIGH |
| 71 | [L2D09 Traiter webhooks et réconcilier les fournisseurs](#l2d09) | L2 | L2D01, L2D02, L2D03, L2D07 | HIGH |
| 72 | [L2D10 Valider intégrations et sécurité sur comptes autorisés](#l2d10) | L2 | L2D05, L2D08, L2D09 | HIGH |
| 73 | [G6 Recevoir automatisations et canaux externes](#g6) | L2 | L2D10 | NORMAL |
| 74 | [L3D01 Auditer le site existant et fixer son raccordement](#l3d01) | L3 | G4 | NORMAL |
| 75 | [L3D02 Publier showroom et contenus approuvés](#l3d02) | L3 | L3D01, G5 | NORMAL |
| 76 | [L3D03 Connecter réservation publique et protection anti-abus](#l3d03) | L3 | L3D02, L2D02, G6 | HIGH |
| 77 | [L3D04 Configurer emails et vérifications du domaine](#l3d04) | L3 | L3D01, G4 | NORMAL |
| 78 | [L3D05 Remettre documentation, formation et propriété](#l3d05) | L3 | L3D03, L3D04 | NORMAL |
| 79 | [G7 Clôturer la recette globale et la remise](#g7) | L3 | L3D05 | NORMAL |

Les dépendances désignent des preuves acquises, pas la simple création d’un fichier. Une dépendance indirecte n’est pas répétée dans chaque fiche. Une tâche BLOCKED pour accès fournisseur n’autorise pas à simuler sa livraison ; prendre un autre travail prêt et consigner la cause.

Les tickets L0-01…L3-02 de 13 restent les regroupements de livraison. Les IDs F/O/P/B/etc. et L1Dxx/L2Dxx/L3Dxx précisent les tâches exécutables ; ils ne remplacent pas les regroupements du planning. Le champ « regroupement 13 » établit la correspondance.

## Fiches exécutables

### F01

**Confirmer la baseline et les contraintes de lancement**

Lot L0 ; risque NORMAL ; prérequis : aucun. Regroupement 13 : L0-01.

Fichiers attendus : `docs/implementation/startup.md`, `docs/implementation/hosting-audit.md`.

Travaux :
1. Lire les autorités et décisions ; inventorier le dossier sans le modifier.
2. Auditer versions locales et offre web si accès disponibles.
3. Distinguer défaut bloquant local et information manquante avant production ; consigner Q01–Q04/Q14.

Sortie vérifiable :
- Architecture 23 retenue et écarts documentés.
- Aucun VPS ni donnée réelle dans la préparation.
- Environnement local à préparer identifié.

Validation : Audit versions, accès, cron et droits DB.
Scénarios : contrôles d’ingénierie, sans scénario métier autonome. Exigences : EE040, EE042, EE044, EE046.

### F02

**Créer le dépôt applicatif et verrouiller les dépendances**

Lot L0 ; risque NORMAL ; prérequis : F01. Regroupement 13 : L0-01.

Fichiers attendus : `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `vite.config.ts`, `tsconfig.json`, `.env.example`.

Travaux :
1. Intégrer Laravel à la racine sans écraser les sources.
2. Ajouter Vue Router, TS strict, Tailwind, PWA, Dexie, PDF et outils de tests.
3. Fixer noms des tables techniques et configurations dev/test/recette.

Sortie vérifiable :
- Build PHP/frontend réussit avec lockfiles.
- Aucun serveur Node requis en production.
- Secrets absents des fichiers suivis.

Validation : Installation reproductible et build vide.
Scénarios : contrôles d’ingénierie, sans scénario métier autonome. Exigences : EE040, EE044, EE046.

### F03

**Installer les contrôles CI et MySQL isolé**

Lot L0 ; risque NORMAL ; prérequis : F02. Regroupement 13 : L0-01.

Fichiers attendus : `.github/workflows/quality.yml`, `phpunit.xml`, `vitest.config.ts`, `playwright.config.ts`, `docs/implementation/testing.md`.

Travaux :
1. Créer scripts de qualité PHP/TS et build.
2. Préparer base MySQL test distincte, fixtures et garde environnement.
3. Configurer PHPUnit, Vitest et Playwright sans fournisseur réel.

Sortie vérifiable :
- CI exécute une preuve minimale dans chaque runtime.
- Destruction de base impossible avec configuration production.
- Logs de CI sans secret ni identité réelle.

Validation : Commande de tests locale et CI documentées.
Scénarios : contrôles d’ingénierie, sans scénario métier autonome. Exigences : EE045, EE046.

### F04

**Traduire le schéma complet et prouver son équivalence**

Lot L0 ; risque HIGH ; prérequis : F03. Regroupement 13 : L0-01.

Fichiers attendus : `database/migrations/`, `app/Console/Commands/VerifySchema.php`, `app/Console/Commands/AdoptBaseline.php`, `docs/evidence/schema-parity.json`.

Travaux :
1. Générer tables puis FK, référentiels, vues et guards selon 23.
2. Ajouter les seules tables techniques déclarées, sessions ULID incluses.
3. Importer par migrations sur base neuve ; comparer toutes les métadonnées.
4. Vérifier adoption d’un import SQL équivalent et refus d’une divergence.

Sortie vérifiable :
- 117 tables métier, 464 FK, 484 CHECK, 88 guards et 10 vues équivalents à la baseline.
- 4 rôles, 49 permissions et 76 termes cliniques présents.
- Colonnes générées, charset, defaults et référentiels préservés.
- SQL livré et migration initiale ne sont pas exécutés deux fois.

Validation : Metadata MySQL et 41 assertions SQL existantes sur base isolée; Comparaison catalogue–migrations et cas de divergence.
Scénarios : contrôles d’ingénierie, sans scénario métier autonome. Exigences : EE046.

### G0

**Valider installation et modèle de toute l’application**

Lot L0 ; risque HIGH ; prérequis : F04. Regroupement 13 : transverse/gate.

Fichiers attendus : `docs/evidence/G0.md`.

Travaux :
1. Relire équivalence et logs réels.
2. Refaire installation neuve reproductible.
3. Identifier les données réelles et accès encore manquants.

Sortie vérifiable :
- Build et schéma complet validés.
- Développement fictif autorisé par les configurations.
- Aucune activation financière réelle.

Validation : Gate G0 du fichier 25.
Scénarios : contrôles d’ingénierie, sans scénario métier autonome. Exigences : EE046.

### F05

**Adapter identité, permissions et bootstrap gérante**

Lot L0 ; risque HIGH ; prérequis : G0. Regroupement 13 : L0-01.

Fichiers attendus : `app/Models/User.php`, `app/Domain/Access/`, `app/Policies/`, `app/Console/Commands/SetupClinic.php`.

Travaux :
1. Adapter login/password_hash/ULID/active et session Sanctum.
2. Utiliser rôles/permissions existants et révocations.
3. Créer cabinet/première gérante sans mot de passe universel ; sécuriser reset et réauthentification.

Sortie vérifiable :
- Quatre profils démontrables.
- URL/API/PDF refusés au mauvais rôle.
- Session invalidée et coffre verrouillé sans perte des pièces.

Validation : CSRF, 401/419, désactivation, reset contrôlé et autre cabinet.
Scénarios : AT01, AT02, AT43. Exigences : EE016, EE040.
Tables fonctionnelles principales : `clinics`, `permissions`, `role_permissions`, `roles`, `user_permissions`, `user_roles`, `users`.

### F06

**Matérialiser les contrats et versions**

Lot L0 ; risque NORMAL ; prérequis : F02, F04. Regroupement 13 : L0-01, L0-15.

Fichiers attendus : `contracts/openapi.yaml`, `contracts/commands/`, `contracts/events/`, `contracts/compatibility.json`.

Travaux :
1. Définir DTO/routes 08 et compléments 23.
2. Fixer envelopes, types exacts, erreurs, ACK et versions.
3. Ajouter fixtures de request/response et schémas des snapshots.

Sortie vérifiable :
- Contrats versionnés validables.
- IDs de tous les effets définis avant commit local.
- Aucun endpoint métier nécessaire laissé implicitement à inventer.

Validation : Validation des fixtures JSON et génération des types TS.
Scénarios : contrôles d’ingénierie, sans scénario métier autonome. Exigences : EE042, EE045, EE046.

### F07

**Construire le chemin central de commandes**

Lot L0 ; risque HIGH ; prérequis : F05, F06. Regroupement 13 : L0-01, L0-15.

Fichiers attendus : `app/Application/CommandBus.php`, `app/Domain/Shared/CommandContext.php`, `app/Domain/Shared/BusinessError.php`, `app/Application/TransactionRunner.php`.

Travaux :
1. Implémenter autorisation, contexte historique, ordre des verrous et idempotence.
2. Écrire audit, outbox et change log au commit.
3. Traiter deadlock, rollback, hash différent et réponse perdue.

Sortie vérifiable :
- Une mutation critique passe toujours par ce chemin.
- Aucun effet externe dans la transaction.
- Publication pull ordonnée sous verrou clinic.

Validation : Deux connexions MySQL avec interleavings contrôlés; Rollback de chaque effet après erreur injectée.
Scénarios : AT11, AT12, AT13, AT19, AT38. Exigences : EE017, EE024, EE045.
Tables fonctionnelles principales : `audit_events`, `idempotency_requests`, `outbox_events`.

### F08

**Établir règles pures et vecteurs PHP/TypeScript**

Lot L0 ; risque HIGH ; prérequis : F06. Regroupement 13 : L0-05, L0-06, L0-15.

Fichiers attendus : `app/Domain/Shared/ValueObjects/`, `resources/frontend/domain/`, `contracts/vectors/`.

Travaux :
1. Implémenter nombres/dates/parsing/null et calculs exacts.
2. Définir mêmes vecteurs acceptés et refusés dans PHP et TS.
3. Fixer bornes SQL, arrondis et versions de règles.

Sortie vérifiable :
- Aucun flottant monétaire.
- Résultats identiques sur tous vecteurs.
- Fin de mois, année, fuseau, zéro et vide distingués.

Validation : Vitest et PHPUnit sur fichiers JSON communs.
Scénarios : AT08, AT10, AT14, AT22, AT26, AT40. Exigences : EE021, EE022, EE033, EE045.

### F09

**Construire shell responsive et composants réutilisés**

Lot L0 ; risque NORMAL ; prérequis : F02. Regroupement 13 : L0-14.

Fichiers attendus : `resources/frontend/app/`, `resources/frontend/components/`, `resources/frontend/styles/`.

Travaux :
1. Créer navigation selon permissions et activation.
2. Construire champs, tableau optique, erreurs, confirmations et états de sauvegarde.
3. Appliquer palette, clavier, zoom et 360 px de 04.

Sortie vérifiable :
- Shell accessible et agréable.
- Aucun menu de module futur présenté comme livré.
- Composants utilisables dans patient et caisse.

Validation : Contrastes, focus, clavier et viewport.
Scénarios : AT28, AT29. Exigences : EE044.

### F10

**Définir lectures locales et états de synchronisation**

Lot L0 ; risque NORMAL ; prérequis : F06, F09. Regroupement 13 : L0-14, L0-15.

Fichiers attendus : `resources/frontend/app/services/`, `resources/frontend/offline/projections/`, `resources/frontend/components/SyncStatus.vue`.

Travaux :
1. Créer interface de lecture commune et dispatcher de mutation.
2. Distinguer local durable, pending, ACK, conflit et PDF.
3. Préserver formulaire lors de conflit/session expirée ; afficher fraîcheur distante.

Sortie vérifiable :
- Aucune écriture Dexie directe depuis page.
- Aucun total local+serveur compté deux fois.
- États sauvegarde/sync distincts du réseau.

Validation : Sélecteurs sur entités locales et consolidées avec mêmes IDs.
Scénarios : AT29, AT42, AT45. Exigences : EE023, EE042, EE044.

### O01

**Implémenter le coffre et les compartiments**

Lot L0 ; risque HIGH ; prérequis : F05, F09. Regroupement 13 : L0-15.

Fichiers attendus : `resources/frontend/offline/vault/`, `resources/frontend/offline/keys/`, `tests/e2e/vault.spec.ts`.

Travaux :
1. Créer schéma Dexie et chiffrement AES-GCM avec AAD.
2. Envelopper clés par profil et recovery ; préparer recherche en mémoire.
3. Installer PWA et assets sans réponses médicales en cache.

Sortie vérifiable :
- Fermeture/reprise retrouve le coffre.
- Mauvais profil ne déchiffre ni index sensible ni PDF.
- Verrouillage masque mémoire/UI et préserve ciphertext.

Validation : Inspection IndexedDB/cache et tests de déchiffrement refusé.
Scénarios : AT02, AT36, AT45. Exigences : EE016, EE042.

### O02

**Enrôler le poste et émettre les grants signés**

Lot L0 ; risque HIGH ; prérequis : O01, F07. Regroupement 13 : L0-15.

Fichiers attendus : `app/Offline/EnrollDevice.php`, `app/Offline/PrepareGrant.php`, `resources/frontend/offline/enrollment/`, `contracts/vectors/signatures/`.

Travaux :
1. Préparer ES256 appareil et JWS grants.
2. Lier utilisateur, droits, versions, durée et snapshot catalogue privé.
3. Vérifier signatures croisées, expiry, révocation différée et horloge suspecte.

Sortie vérifiable :
- Algorithme/clé imposés et grant immuable.
- Catalogue figé vérifiable conservé.
- Autorité locale bornée sans bearer permanent.

Validation : JCS/JWS PHP–Web Crypto et corruption/alg=none/clé substituée.
Scénarios : AT36, AT43. Exigences : EE016, EE020, EE042.
Tables fonctionnelles principales : `enrolled_devices`, `offline_grants`.

### O03

**Allouer plages, propriétaires et quotas sans chevauchement**

Lot L0 ; risque HIGH ; prérequis : O02, F08. Regroupement 13 : L0-08, L0-15.

Fichiers attendus : `app/Offline/PrepareResources.php`, `app/Offline/ReleaseResources.php`, `resources/frontend/offline/allocations/`.

Travaux :
1. Réserver plages sous verrous et exclure numéros centraux.
2. Attribuer factures existantes et epochs ; préparer quotas stock.
3. Restituer seulement après reprise complète, jamais à expiration simple.

Sortie vérifiable :
- Plages disjointes et numéros non recyclés.
- Un seul writer financier par facture.
- Physique/réserve centrale/quota local non doublement soustraits.

Validation : Concurrence allocation, année nouvelle et release prématuré refusé.
Scénarios : AT09, AT39, AT40, AT43. Exigences : EE021, EE025, EE042.
Tables fonctionnelles principales : `device_stock_allocations`, `document_number_allocations`, `document_sequences`, `invoice_write_owners`.

### O04

**Implémenter le commit local et journal commun au poste**

Lot L0 ; risque HIGH ; prérequis : O03, F10. Regroupement 13 : L0-15.

Fichiers attendus : `resources/frontend/offline/LocalCommandBus.ts`, `resources/frontend/offline/command-journal/`, `resources/frontend/offline/locks/`.

Travaux :
1. Calculer/chiffrer/signer candidat hors transaction.
2. Vérifier CAS versions/head puis committer faits, numéros, journal et ressources ensemble.
3. Gérer deux onglets et profils sans deux journaux financiers parallèles.

Sortie vérifiable :
- Aucune pièce affichée avant commit.
- Collision locale recalcule sans double effet.
- Commande retrouve résultat idempotent après reprise.

Validation : Pannes avant/après commit et deux onglets concurrents.
Scénarios : AT36, AT37, AT41. Exigences : EE017, EE042, EE045.

### P01

**Créer et modifier patients dans les deux moteurs**

Lot L0 ; risque NORMAL ; prérequis : O04, F07. Regroupement 13 : L0-02.

Fichiers attendus : `app/Domain/Patients/CreatePatient.php`, `app/Domain/Patients/UpdatePatient.php`, `resources/frontend/pages/patients/`, `resources/frontend/domain/patients/`.

Travaux :
1. Valider nom/contact/naissance absente justifiée et versions.
2. Créer ULID/numéro central ou plage locale.
3. Adapter les corrections par rôle et suggestions de doublon sans fusion.

Sortie vérifiable :
- Même ID après sync.
- Téléphone partagé accepté.
- Dates futures et écrasements silencieux refusés.

Validation : Patient offline après restart et version conflictuelle.
Scénarios : AT03, AT04, AT36, AT42. Exigences : EE001, EE045.
Tables fonctionnelles principales : `patient_records`.

### P02

**Rechercher patients et ouvrir identité sans ressaisie**

Lot L0 ; risque NORMAL ; prérequis : P01. Regroupement 13 : L0-02.

Fichiers attendus : `app/Domain/Patients/SearchPatients.php`, `resources/frontend/pages/patients/PatientList.vue`, `resources/frontend/components/PatientPicker.vue`.

Travaux :
1. Recherche nom accentué/téléphone/dossier paginée.
2. Recherche mémoire sur annuaire autorisé du poste.
3. Signaler serveur indisponible pour dossiers non préparés.

Sortie vérifiable :
- Identité réutilisée en vente/visite.
- Absence de cache ne signifie pas absence certaine du patient.
- Aucun résumé médical montré à la caisse.

Validation : Accents/homonymes et recherche privée par rôle.
Scénarios : AT03, AT04, AT29. Exigences : EE001, EE044, EE045.

### B01

**Créer catalogue et paramètres prix/taxes**

Lot L0 ; risque NORMAL ; prérequis : P01, F08, O03. Regroupement 13 : L0-04.

Fichiers attendus : `app/Domain/Billing/Catalog/`, `resources/frontend/pages/sales/CatalogPicker.vue`, `resources/frontend/domain/billing/`.

Travaux :
1. Implémenter catégorie/unité/mode stock et recherche deux caractères.
2. Limiter prix achat à gérante ; figer version de tarif préparée.
3. Utiliser taxe fictive TEST tant que régime réel manque.

Sortie vérifiable :
- Articles stockés/services/sur mesure distincts.
- Unité paire non transformée automatiquement en deux.
- Prix et taxe officiels non inventés.

Validation : Snapshot catalogue et changement central pendant grant.
Scénarios : AT07, AT15. Exigences : EE020, EE027.
Tables fonctionnelles principales : `catalog_categories`, `catalog_items`, `tax_rules`, `units`.

### B02

**Implémenter les brouillons de facture**

Lot L0 ; risque NORMAL ; prérequis : B01, F07, O04. Regroupement 13 : L0-04, L0-05.

Fichiers attendus : `app/Domain/Billing/CreateInvoiceDraft.php`, `app/Domain/Billing/UpdateDraft.php`, `resources/frontend/pages/sales/InvoiceEditor.vue`.

Travaux :
1. Ajouter lignes, quantités exactes, prix autorisés et total.
2. Réutiliser patient sélectionné et version brouillon.
3. Préparer tous IDs des effets et schéma de snapshot.

Sortie vérifiable :
- Brouillon durable sans numéro définitif.
- Conflit conserve formulaire.
- Remise non autorisée refusée.

Validation : Calculs exacts et modifications concurrentes de brouillon.
Scénarios : AT07, AT08. Exigences : EE020, EE021, EE045.
Tables fonctionnelles principales : `invoice_lines`, `invoices`.

### I00

**Préparer le service de réserve utilisé dès l’émission**

Lot L0 ; risque HIGH ; prérequis : B01, O03, F07. Regroupement 13 : L0-08, L0-15.

Fichiers attendus : `app/Domain/Inventory/ReservationService.php`, `resources/frontend/domain/inventory/reservations.ts`, `app/Domain/Inventory/OpeningStock.php`.

Travaux :
1. Créer positions/lots et stock fictif ou comptage validé.
2. Implémenter réserve exacte centrale ou sous quota local et blocage péremption.
3. Fournir le même port transactionnel à IssueInvoice et future ConfirmDelivery.

Sortie vérifiable :
- Émission ne peut valider une vente stockée sans capacité réservée.
- Plusieurs lignes et lots contrôlés atomiquement.
- Aucune nouvelle logique de stock à inventer après la caisse.

Validation : Réserve dernier article et plusieurs lignes sous quota.
Scénarios : AT15, AT17, AT40. Exigences : EE025, EE026, EE027, EE045.
Tables fonctionnelles principales : `stock_locations`, `stock_lots`, `stock_positions`, `stock_reservations`.

### B03

**Émettre facture immuable et propriété locale**

Lot L0 ; risque HIGH ; prérequis : B02, I00. Regroupement 13 : L0-05.

Fichiers attendus : `app/Domain/Billing/IssueInvoice.php`, `resources/frontend/domain/billing/issueInvoice.ts`, `resources/frontend/pages/sales/InvoiceDetails.vue`.

Travaux :
1. Écrire enfants avant finalisation, snapshot et usage de numéro dans même commit.
2. Créer writer pour facture locale et ledger initial.
3. Séparer émis, dette et statut PDF.

Sortie vérifiable :
- Facture et numéro ne changent plus.
- Toute erreur rollback toutes ressources.
- Reprise ne renumérote jamais.

Validation : Guards et émission concurrente/plage épuisée.
Scénarios : AT08, AT09, AT37. Exigences : EE021, EE023, EE045.
Tables fonctionnelles principales : `document_number_usages`.

### B05

**Ouvrir et clôturer les sessions de caisse**

Lot L0 ; risque HIGH ; prérequis : B03, F05. Regroupement 13 : L0-12.

Fichiers attendus : `app/Domain/Cash/OpenSession.php`, `app/Domain/Cash/CloseSession.php`, `resources/frontend/pages/cash/`.

Travaux :
1. Créer une session ouverte par caisse/caissière.
2. Journaliser ouverture/fermeture également sur poste offline.
3. Figer attendu/compté/écart motivé et fermer seulement après les opérations précédentes.

Sortie vérifiable :
- Encaissement CASH exige session admissible.
- Session close immuable et nouvelles opérations refusées.
- Correction ultérieure ne réécrit pas le comptage.

Validation : Deux ouvertures concurrentes et sync ordonnée avant clôture.
Scénarios : AT25, AT41. Exigences : EE030.
Tables fonctionnelles principales : `cash_registers`, `cash_sessions`.

### B04

**Encaisser acompte et solde avec reçu unique**

Lot L0 ; risque HIGH ; prérequis : B03, B05. Regroupement 13 : L0-06.

Fichiers attendus : `app/Domain/Billing/RegisterPayment.php`, `app/Domain/Billing/InvoiceLedger.php`, `resources/frontend/pages/sales/PaymentDialog.vue`.

Travaux :
1. Contrôler writer/ledger_version/solde et canal.
2. Créer paiement confirmé puis reçu/document PENDING atomiquement.
3. Réutiliser même intention sur double clic/timeout et afficher dette exacte.

Sortie vérifiable :
- 80000 puis 30000 puis 50000 donnent deux reçus et dette zéro.
- Aucun UPDATE de facture émise.
- Paiement au-delà du solde refusé.

Validation : Concurrence MySQL et locale, cash/modes non cash.
Scénarios : AT10, AT11, AT12, AT37. Exigences : EE022, EE023, EE030, EE045.
Tables fonctionnelles principales : `payments`, `receipts`.

### O05

**Implémenter push/pull, ACK et conflits durables**

Lot L0 ; risque HIGH ; prérequis : P01, B04, O04. Regroupement 13 : L0-15.

Fichiers attendus : `app/Offline/PushCommands.php`, `app/Offline/PullChanges.php`, `app/Offline/ResolveConflict.php`, `resources/frontend/offline/sync/`.

Travaux :
1. Vérifier signatures/chaîne/grants puis appliquer mêmes actions centrales.
2. Persister ACK par commande et pages pull atomiques.
3. Implémenter finalisation ACCEPTED ou REJECTED en revue sans trou de journal.
4. Rejouer après timeout et conserver sources locales de conflit.

Sortie vérifiable :
- Un seul fait par ID/hash.
- Curseur ne saute pas une transaction non publiée.
- Conflit résolu ne bloque pas définitivement la caisse et n’invente pas un paiement.

Validation : Crash après commit avant ACK; Conflit de séquence N puis reprise N+1; Pull avec commits entrelacés.
Scénarios : AT38, AT39, AT42, AT45. Exigences : EE017, EE042, EE045.
Tables fonctionnelles principales : `sync_change_log`, `sync_command_results`, `sync_commands`, `sync_conflicts`.

### D01

**Générer et conserver les PDFs financiers hors-ligne**

Lot L0 ; risque HIGH ; prérequis : B04, F09. Regroupement 13 : L0-05, L0-06, L0-14.

Fichiers attendus : `resources/frontend/documents/financial/`, `app/Domain/Operations/StoreDocumentContent.php`, `resources/frontend/documents/fonts/`.

Travaux :
1. Générer depuis snapshot committé avec fontes locales.
2. Enregistrer PDF chiffré/hash et réimprimer original.
3. Uploader après ACK source, valider hash/type et stockage privé.

Sortie vérifiable :
- Facture et deux reçus imprimables réseau coupé.
- Panne PDF ne réencaisse jamais.
- READY central finalisé et téléchargement soumis à Policy.

Validation : Inspection A4/accents/longues lignes et PDF retry.
Scénarios : AT08, AT28, AT37. Exigences : EE014, EE021, EE022.
Tables fonctionnelles principales : `generated_documents`, `stored_files`.

### G1

**Prouver la tranche urgente persistante et rejouable**

Lot L0 ; risque HIGH ; prérequis : O05, D01, P02. Regroupement 13 : transverse/gate.

Fichiers attendus : `docs/evidence/G1.md`, `tests/e2e/first-slice.spec.ts`.

Travaux :
1. Préparer caisse et données fictives puis couper réseau.
2. Créer patient/facture/acompte/solde et PDFs ; fermer et reprendre.
3. Perdre ACK et rejouer ; comparer pièces/numéros/soldes.

Sortie vérifiable :
- Tranche traverse services réels.
- Même ID/numéro et aucun double paiement.
- Date restante recalculée à partir du temps observé.

Validation : Gate G1 et deux contextes de navigateur.
Scénarios : AT36, AT37, AT38, AT41. Exigences : EE021, EE022, EE042, EE045.

### B06

**Implémenter approbations exactes et réauthentification**

Lot L0 ; risque HIGH ; prérequis : B04, F05. Regroupement 13 : L0-07.

Fichiers attendus : `app/Domain/Access/RequestApproval.php`, `app/Domain/Access/DecideApproval.php`, `resources/frontend/pages/manager/Approvals.vue`.

Travaux :
1. Lier action/cible/hash/motif/durée et auteur gérante.
2. Consommer une seule fois après vérification payload.
3. Autoriser profil gérante présent offline sans PIN commun.

Sortie vérifiable :
- Payload changé ou décision expirée refusé.
- Permissions prix/examen/remise/correction restent distinctes.
- Décision auditée et partage limité.

Validation : Replay approbation et hash différent.
Scénarios : AT06, AT07, AT14, AT16, AT43. Exigences : EE016, EE018, EE024, EE025.
Tables fonctionnelles principales : `approvals`.

### B07

**Contre-écrire paiement et émettre avoir lié**

Lot L0 ; risque HIGH ; prérequis : B06. Regroupement 13 : L0-07.

Fichiers attendus : `app/Domain/Billing/ReversePayment.php`, `app/Domain/Billing/IssueCreditNote.php`, `resources/frontend/pages/sales/Corrections.vue`.

Travaux :
1. Plafonner cumul contre-écritures et lignes d’avoir.
2. Écrire objets liés sans modifier les originaux.
3. Distinguer erreur comptable, réduction de vente et retour physique.

Sortie vérifiable :
- Suppression paiement impossible.
- Total des corrections respecte originaux.
- Solde et CA reflètent faits à leurs dates.

Validation : Deux avoirs concurrents et erreur après insertion enfant.
Scénarios : AT13, AT14, AT24. Exigences : EE017, EE024, EE029, EE045.
Tables fonctionnelles principales : `credit_note_lines`, `credit_notes`, `payment_reversals`.

### B08

**Rembourser le crédit et conserver sa pièce**

Lot L0 ; risque HIGH ; prérequis : B07, B05. Regroupement 13 : L0-07.

Fichiers attendus : `app/Domain/Billing/RefundCredit.php`, `resources/frontend/pages/sales/RefundDialog.vue`, `resources/frontend/documents/refunds/`.

Travaux :
1. Vérifier crédit réellement disponible et approbation.
2. Enregistrer sortie réelle/mode/session et pièce numérotée.
3. Recalculer ledger sans modifier facture/avoir/reçu.

Sortie vérifiable :
- Remboursement ne dépasse pas crédit.
- Un seul remboursement par clé.
- Flux argent distinct de l’avoir et du stock.

Validation : Concurrence refund et session close.
Scénarios : AT14, AT25, AT37. Exigences : EE024, EE030, EE045.
Tables fonctionnelles principales : `refunds`.

### I01

**Gérer réservations et remises sous quota**

Lot L0 ; risque HIGH ; prérequis : I00, B03, B06, O03. Regroupement 13 : L0-08.

Fichiers attendus : `app/Domain/Inventory/`, `app/Domain/Inventory/ConfirmDelivery.php`, `resources/frontend/pages/stock/`.

Travaux :
1. Réutiliser le service de réserve I00 et ajouter les écrans d’exploitation.
2. Remettre quantités par facture et quota avec effets rangés.
3. Refuser dernier article concurrent, lot expiré et remise à crédit sans décision.

Sortie vérifiable :
- Réservation différente d’une sortie.
- Quota non soustrait deux fois.
- Facture/patient/article/lot correspondent.

Validation : Dernier article, livraison partielle et vecteur 5/3/1.
Scénarios : AT15, AT16, AT17, AT40. Exigences : EE025, EE026, EE027, EE045.
Tables fonctionnelles principales : `deliveries`, `delivery_lines`.

### I02

**Enregistrer retour physique et sorties exceptionnelles**

Lot L0 ; risque HIGH ; prérequis : I01, B07. Regroupement 13 : L0-08.

Fichiers attendus : `app/Domain/Inventory/RegisterPhysicalReturn.php`, `app/Domain/Inventory/RegisterStockMovement.php`, `resources/frontend/pages/stock/MovementForm.vue`.

Travaux :
1. Plafonner retours par remise originale et justifier inspection/destination.
2. Exiger gérante/motif pour casse/ajustement.
3. Appliquer plusieurs effets d’une commande avec movement_position stable.

Sortie vérifiable :
- Avoir seul ne rentre aucun stock.
- Retours/exceptions auditables et idempotents.
- Projections rapprochées aux faits.

Validation : Retour excessif, lot invalide et plusieurs mouvements par commande.
Scénarios : AT16, AT17, AT40. Exigences : EE025, EE026, EE027, EE045.
Tables fonctionnelles principales : `physical_returns`, `stock_movements`.

### O06

**Sécuriser mises à jour PWA et schéma local**

Lot L0 ; risque HIGH ; prérequis : G1. Regroupement 13 : L0-15.

Fichiers attendus : `resources/frontend/offline/update/`, `resources/frontend/offline/migrations/`, `contracts/compatibility.json`.

Travaux :
1. Proposer SW update sans remplacement forcé.
2. Migrer coffre additivement et conserver anciens templates/règles.
3. Vérifier pending, profils verrouillés, export et capacité avant changement.

Sortie vérifiable :
- Aucune purge automatique.
- Commandes ancienne version encore reprises.
- API/fichiers privés exclus du fallback SPA.

Validation : Upgrade avec commandes de deux profils et coupure en migration.
Scénarios : AT45. Exigences : EE042, EE044.

### O07

**Exporter et restaurer le poste sans cloner ses droits**

Lot L0 ; risque HIGH ; prérequis : O06. Regroupement 13 : L0-13, L0-15.

Fichiers attendus : `resources/frontend/offline/recovery/`, `app/Offline/ReconcileReplacement.php`, `docs/runbooks/local-recovery.md`.

Travaux :
1. Exporter journaux/PDF/manifeste chiffrés et enveloppes recovery.
2. Restaurer pièces émises sur poste neuf et réconcilier origine.
3. Enrôler nouveau device pour émissions futures avec nouvelles plages.

Sortie vérifiable :
- Mêmes IDs/numéros pour anciens faits.
- Aucun clone actif des droits non consommés.
- Ancien poste réapparu passe en revue.

Validation : Restauration sur nouveau contexte et reprise ancien appareil.
Scénarios : AT39, AT44, AT45. Exigences : EE040, EE041, EE042.

### G2

**Valider sécurité financière, stock et reprise**

Lot L0 ; risque HIGH ; prérequis : B08, I02, O07, O05. Regroupement 13 : transverse/gate.

Fichiers attendus : `docs/evidence/G2.md`, `tests/Concurrency/`, `tests/e2e/financial-safety.spec.ts`.

Travaux :
1. Exécuter montants/refus/idempotence/approbations.
2. Tester verrous MySQL et ressources offline sur deux contextes.
3. Reprendre après perte/défaut PDF/conflit et rapprocher diagnostics SQL.

Sortie vérifiable :
- Aucun défaut critique de pièce/argent/stock/numéro.
- Litiges conservés et procédure de résolution testée.
- Reprise après incident ne nécessite pas refaire l’architecture.

Validation : Gate G2 du fichier 25.
Scénarios : AT08, AT09, AT10, AT11, AT12, AT13, AT14, AT15, AT16, AT17, AT38, AT39, AT40, AT41, AT43, AT44, AT45. Exigences : EE021, EE022, EE024, EE025, EE042, EE045.

### P03

**Créer visite, file du jour et contrôle paiement examen**

Lot L0 ; risque NORMAL ; prérequis : P01, B04, B06. Regroupement 13 : L0-03.

Fichiers attendus : `app/Domain/Patients/RegisterVisit.php`, `app/Domain/Clinical/StartConsultation.php`, `resources/frontend/pages/today/`.

Travaux :
1. Différencier examen/achat/retrait/contrôle.
2. Relier frais exacts à cette visite ou dérogation explicite.
3. Préparer preuve locale dans le compartiment autorisé.

Sortie vérifiable :
- Pas d’examen fictif pour achat.
- Examen non payé bloqué.
- File et nouvelle visite n’écrasent pas l’historique.

Validation : Paiement autre prestation ne débloque pas cet examen.
Scénarios : AT05, AT06, AT21. Exigences : EE002, EE018, EE019.
Tables fonctionnelles principales : `visits`.

### C01

**Saisir toutes rubriques narratives du dossier DP**

Lot L0 ; risque NORMAL ; prérequis : P03, F08. Regroupement 13 : L0-09.

Fichiers attendus : `app/Domain/Clinical/SaveClinicalStep.php`, `resources/frontend/pages/clinical/ConsultationWizard.vue`, `resources/frontend/domain/clinical/`.

Travaux :
1. Construire étapes motif/lunettes/antécédents/conclusion/orientation.
2. Utiliser termes multiples, Aucun connu exclusif et Autre justifié.
3. Sauvegarder localement chaque étape avec version et auteur.

Sortie vérifiable :
- Tous champs DP couverts sans omission.
- Incomplet conservé explicitement.
- Aucune donnée clinique dans le rôle caisse.

Validation : Référentiels, Autre, absent et exclusivité Aucun connu.
Scénarios : AT18, AT19, AT36. Exigences : EE004, EE005, EE011, EE013, EE016.
Tables fonctionnelles principales : `clinical_terms`, `consultation_ametropias`, `consultation_complaints`, `consultation_histories`, `consultation_history_terms`, `consultation_referral_reasons`, `consultations`.

### C02

**Saisir acuités, autorefracteur et réfraction distinctes**

Lot L0 ; risque NORMAL ; prérequis : C01. Regroupement 13 : L0-09.

Fichiers attendus : `resources/frontend/components/OpticalMeasurements.vue`, `app/Domain/Clinical/Measurements.php`, `contracts/vectors/clinical/`.

Travaux :
1. Implémenter 12 acuités pré-réfraction et 3 après.
2. Séparer AUTOREFRACTOR/OBJECTIVE/SUBJECTIVE et DP.
3. Préserver échelle, signe, zéro, vide et conventions professionnelles.

Sortie vérifiable :
- OD/OG/OU exacts sur desktop et mobile.
- Pas de conversion médicale implicite.
- Incompatibilité d’échelles visible.

Validation : Vecteurs numériques et consultation DP intégrale.
Scénarios : AT18, AT21, AT28. Exigences : EE006, EE007, EE008, EE044.
Tables fonctionnelles principales : `autorefractor_centering`, `refraction_measurements`, `visual_acuities`, `visual_acuity_scales`.

### C03

**Finaliser, corriger et comparer les révisions**

Lot L0 ; risque HIGH ; prérequis : C02. Regroupement 13 : L0-10.

Fichiers attendus : `app/Domain/Clinical/CompleteConsultation.php`, `app/Domain/Clinical/AmendConsultation.php`, `resources/frontend/pages/clinical/History.vue`.

Travaux :
1. Créer snapshot complet au commit de finalisation.
2. Nouvelle correction avec motif/auteur, sans écraser ancienne version.
3. Conflit compare base/central/local et garde proposition.

Sortie vérifiable :
- Historique chronologique et réimpression ancienne source.
- Deux consultations restent distinctes.
- Aucune stratégie dernier écrit gagnant.

Validation : Deux postes et historique après correction.
Scénarios : AT19, AT21, AT42. Exigences : EE003, EE013, EE017.
Tables fonctionnelles principales : `clinical_revisions`.

### D02

**Compléter templates et validation visuelle des documents**

Lot L0 ; risque NORMAL ; prérequis : D01, C03, B08. Regroupement 13 : L0-10, L0-14.

Fichiers attendus : `resources/frontend/documents/clinical/`, `resources/frontend/documents/credits/`, `tests/fixtures/document-snapshots/`.

Travaux :
1. Ajouter prescription/dossier/avoir/remboursement et relevé de compte.
2. Couvrir lignes longues, unités, pagination, logo/mentions TEST.
3. Inspecter tous PDFs et impression réelle A4.

Sortie vérifiable :
- Aucun total/champ coupé.
- Ancienne version conserve ses fontes/modèle.
- Document clinique uniquement au profil autorisé.

Validation : Snapshots PDF fictifs et revue visuelle.
Scénarios : AT20, AT21, AT28, AT37. Exigences : EE014, EE021, EE044.

### C04

**Prescrire optique, médicaments et orientation**

Lot L0 ; risque NORMAL ; prérequis : C03, D02. Regroupement 13 : L0-10.

Fichiers attendus : `app/Domain/Clinical/ValidatePrescription.php`, `resources/frontend/pages/clinical/Prescription.vue`, `app/Domain/Clinical/MedicationOrders.php`.

Travaux :
1. Copie explicite de mesure puis édition indépendante.
2. Valider yeux/axe/DP/hauteur/usage/indice/traitements et plusieurs médicaments.
3. Préserver prescription externe identifiée et version validée.

Sortie vérifiable :
- Aucune posologie/diagnostic inféré.
- Prescription validée immuable et imprimable.
- Vente et stock ne sont pas créés automatiquement.

Validation : Valeurs DP complètes, deux médicaments et origine externe.
Scénarios : AT20, AT21. Exigences : EE009, EE010, EE011, EE014.
Tables fonctionnelles principales : `medication_orders`, `optical_prescription_eyes`, `optical_prescription_treatments`, `optical_prescriptions`.

### P04

**Vérifier parcours entre profils et postes préparés**

Lot L0 ; risque HIGH ; prérequis : C04, G1, O07. Regroupement 13 : L0-03, L0-15.

Fichiers attendus : `resources/frontend/offline/profile-switch/`, `tests/e2e/profile-flow.spec.ts`, `docs/runbooks/profile-flow.md`.

Travaux :
1. Traverser accueil/caisse/professionnel sur le poste principal avec compartiments séparés.
2. Fournir preuve administrative/examen minimale signée aux profils autorisés.
3. Activer transfert QR/export multiposte seulement si équipement et tests Q02 acquis.

Sortie vérifiable :
- Parcours complet garanti sur poste principal.
- Pas de fuite clinique ni duplication d’ID lors de changement de profil.
- Aucun parcours multiposte déconnecté non testé promis.

Validation : Switch profil offline et attente de sync d’un autre compartiment.
Scénarios : AT02, AT05, AT36, AT39. Exigences : EE016, EE018, EE042, EE045.

### S01

**Configurer et utiliser l’agenda interne**

Lot L0 ; risque NORMAL ; prérequis : P03, F07. Regroupement 13 : L0-11.

Fichiers attendus : `app/Domain/Scheduling/`, `resources/frontend/pages/agenda/`, `app/Domain/Scheduling/AvailabilityService.php`.

Travaux :
1. Configurer ressources/services/horaires/exceptions.
2. Réserver sous verrou, déplacer/annuler avec version.
3. Conserver offline comme proposition à confirmer.

Sortie vérifiable :
- Créneau concurrent refusé.
- Aucun faux RDV confirmé sans réseau.
- Historique administratif distinct du dossier clinique.

Validation : Deux réservations du même créneau et annulation.
Scénarios : AT23. Exigences : EE019, EE031.
Tables fonctionnelles principales : `appointment_services`, `appointments`, `calendar_blocks`, `resource_exceptions`, `resource_opening_hours`, `scheduling_resources`.

### S02

**Calculer suivis et tâches de contact internes**

Lot L0 ; risque NORMAL ; prérequis : C04, S01. Regroupement 13 : L0-11.

Fichiers attendus : `app/Domain/Scheduling/FollowUpCalculator.php`, `app/Domain/Communication/ContactTasks.php`, `resources/frontend/pages/follow-ups/`.

Travaux :
1. Calcul date civile depuis origine explicitement choisie.
2. Créer tâches uniques source/échéance et invalider anciennes programmations.
3. Enregistrer contacté/sans réponse/refus/reporté avec tentatives.

Sortie vérifiable :
- 3 mois et 10–14 jours sans décision médicale automatique.
- Aucun doublon de tâche après retry.
- L0 ne déclenche aucun SMS/WhatsApp réel.

Validation : Fin de mois et changement RDV pendant génération des tâches.
Scénarios : AT22, AT23. Exigences : EE012, EE032, EE033, EE034.
Tables fonctionnelles principales : `contact_attempts`, `contact_tasks`, `follow_up_plans`, `reminder_policies`.

### R01

**Afficher soldes, caisse et finance avec fraîcheur**

Lot L0 ; risque NORMAL ; prérequis : B08, B05, I02, O05. Regroupement 13 : L0-12.

Fichiers attendus : `app/Domain/Billing/FinancialReports.php`, `resources/frontend/pages/reports/Financial.vue`.

Travaux :
1. Utiliser agrégats distincts des vues et périodes cabinet.
2. Distinguer CA/encaissements/dettes/crédits, reprise papier et corrections.
3. Montrer dernière sync et totaux distants incomplets.

Sortie vérifiable :
- Pas de fanout paiement/avoir.
- Finance globale uniquement gérante.
- Local et central ne doublonnent pas les faits ACK.

Validation : Dates effectives, plusieurs avoirs/paiements et caisse close.
Scénarios : AT24, AT25, AT45. Exigences : EE023, EE029, EE030.

### R02

**Afficher statistiques DP et activité patient**

Lot L0 ; risque NORMAL ; prérequis : C03, S02. Regroupement 13 : L0-12.

Fichiers attendus : `app/Domain/Clinical/ActivityReports.php`, `resources/frontend/pages/reports/Clinical.vue`.

Travaux :
1. Définir nouveaux/anciens, distincts du jour et consultations.
2. Calculer sexes/âges/motifs/ordonnances/orientations/contrôles.
3. Afficher inconnus, définitions et période.

Sortie vérifiable :
- Tous tableaux DP présents.
- Aucune agrégation d’échelle incompatible.
- Accès administratif et clinique séparé.

Validation : Patients importés et plusieurs visites même période.
Scénarios : AT25, AT29. Exigences : EE015, EE044.

### X01

**Importer catalogue, stock et créances papier contrôlés**

Lot L0 ; risque HIGH ; prérequis : B04, B08, G1. Regroupement 13 : L0-13.

Fichiers attendus : `app/Domain/Operations/ImportLegacyBalances.php`, `app/Domain/Operations/ImportCatalog.php`, `resources/frontend/pages/manager/Imports.vue`.

Travaux :
1. Prévisualiser/rejeter lignes invalides sans écriture partielle.
2. Certifier références papier et reprise du seul solde.
3. Affecter créance à caisse avant paiement offline.

Sortie vérifiable :
- 80000 déjà payé30000 → reprise50000 sans CA/encaissement initial.
- Paiement20000 crée reçu et dette30000.
- Aucun ancien acompte ni stock de vente dupliqué.

Validation : Import rejoué et référence papier doublonnée.
Scénarios : AT26, AT39. Exigences : EE040, EE041, EE045.
Tables fonctionnelles principales : `legacy_balance_imports`.

### X02

**Exporter données et sauvegarder base/fichiers/clés**

Lot L0 ; risque NORMAL ; prérequis : O07, F07, D02, X01. Regroupement 13 : L0-13.

Fichiers attendus : `app/Domain/Operations/ExportData.php`, `app/Console/Commands/BackupClinic.php`, `docs/runbooks/server-backup.md`.

Travaux :
1. Appliquer Policy/export réauthentifié et manifeste.
2. Sauvegarder DB, pièces privées, clés récupérables et paramètres.
3. Planifier via cron, copier chiffré hors serveur, tracer échec et rétention.

Sortie vérifiable :
- Restitution utilisable avec dictionnaire et pièces.
- Backup serveur ne prétend pas couvrir pending locaux.
- RPO observé distingué de cible.

Validation : Échec stockage distant et restauration des secrets.
Scénarios : AT27, AT44. Exigences : EE040, EE041.
Tables fonctionnelles principales : `backup_runs`, `export_requests`.

### X03

**Restaurer et réconcilier l’environnement serveur**

Lot L0 ; risque HIGH ; prérequis : X02. Regroupement 13 : L0-13.

Fichiers attendus : `docs/runbooks/server-restore.md`, `docs/evidence/restore-rehearsal.md`, `app/Domain/Operations/RestoreVerifier.php`.

Travaux :
1. Restaurer sur environnement isolé et mesurer durée/perte.
2. Vérifier hash/pièces/ledger/séquences/quotas et diagnostics.
3. Rapprocher pièces locales encore non synchronisées avant réouverture.

Sortie vérifiable :
- Clés et pièces réellement récupérées.
- Pas de remise à zéro des séquences.
- RTO/RPO constatés avec écarts documentés.

Validation : Perte serveur simulée et dernier reçu retrouvé.
Scénarios : AT27, AT44. Exigences : EE040, EE041, EE042.
Tables fonctionnelles principales : `restore_tests`.

### U01

**Corriger l’usage et les impressions sur appareils ciblés**

Lot L0 ; risque NORMAL ; prérequis : P04, R01, R02, S02, D02. Regroupement 13 : L0-14.

Fichiers attendus : `tests/e2e/usability.spec.ts`, `docs/evidence/usability.md`, `resources/frontend/components/`.

Travaux :
1. Faire parcours opérateur complet avec chronométrage.
2. Tester 360 px/tablette/1366 px/clavier/zoom200.
3. Tester impression réelle et déverrouillage/reprise sur navigateurs convenus.

Sortie vérifiable :
- Patient puis facture/reçu sans ressaisie.
- Caisse et tableaux lisibles.
- Défauts bloquants d’usage corrigés.

Validation : Recette opérateur et imprimante réelle.
Scénarios : AT28, AT29, AT36, AT37. Exigences : EE044, EE043.

### G3

**Exécuter toute la recette L0 et figer le candidat**

Lot L0 ; risque HIGH ; prérequis : G2, C04, P04, S02, R01, R02, X01, X03, U01. Regroupement 13 : L0-14, L0-15.

Fichiers attendus : `docs/evidence/G3.md`, `docs/evidence/acceptance-L0.json`.

Travaux :
1. Exécuter tous AT01–29 et AT36–45.
2. Examiner permissions/transactions/PDF/recovery et défauts.
3. Fixer build candidat et paramètres production restant à acquérir.

Sortie vérifiable :
- 39 scénarios L0 prouvés.
- Zéro défaut critique ouvert.
- Aucun test app remplacé par le rapport SQL existant.

Validation : Gate G3, revue sécurité et preuves par scénario.
Scénarios : AT01, AT02, AT03, AT04, AT05, AT06, AT07, AT08, AT09, AT10, AT11, AT12, AT13, AT14, AT15, AT16, AT17, AT18, AT19, AT20, AT21, AT22, AT23, AT24, AT25, AT26, AT27, AT28, AT29, AT36, AT37, AT38, AT39, AT40, AT41, AT42, AT43, AT44, AT45. Exigences : EE001, EE002, EE003, EE004, EE005, EE006, EE007, EE008, EE009, EE010, EE011, EE012, EE013, EE014, EE015, EE016, EE017, EE018, EE019, EE020, EE021, EE022, EE023, EE024, EE025, EE026, EE027, EE029, EE030, EE040, EE041, EE042, EE044, EE045.

### H01

**Déployer sur hébergement simple et préparer la bascule**

Lot L0 ; risque HIGH ; prérequis : G3. Regroupement 13 : L0-14.

Fichiers attendus : `docs/runbooks/deploy-web-hosting.md`, `docs/runbooks/cutover.md`, `docs/evidence/hosting-readiness.md`.

Travaux :
1. Valider PHP/MySQL/TRIGGER/VIEW/cron/public/privé/HTTPS sur offre réelle.
2. Construire release sans secrets et appliquer migrations contrôlées.
3. Paramétrer mentions/taxes/catalogue réels, former profils, préparer plages/quotas/imprimante.
4. Rapprocher dernière série papier et créances, vérifier secours/rollback.

Sortie vérifiable :
- Recette sur hébergement cible réalisée.
- Postes/profils préparés et sauvegardes hors serveur actives.
- Bascule décision explicite du responsable, sans vente test réelle involontaire.

Validation : Checklist de bascule du fichier 25.
Scénarios : AT27, AT28, AT29, AT36, AT37, AT44. Exigences : EE040, EE041, EE043, EE044.

### G4

**Recevoir la première livraison opérationnelle**

Lot L0 ; risque HIGH ; prérequis : H01. Regroupement 13 : transverse/gate.

Fichiers attendus : `docs/evidence/G4.md`, `docs/evidence/delivery-L0.md`.

Travaux :
1. Vérifier tous prérequis de production et recette du build déployé.
2. Exécuter préflight caisse et exercice de secours.
3. Consigner décision de bascule, limites et bilan des exigences.

Sortie vérifiable :
- L0 effectivement utilisable.
- Absence d’accès client bloque production mais pas faussement code livré.
- Compte rendu ne présente pas L1/L2/L3 comme actifs.

Validation : Gate G4 et PV L0.
Scénarios : AT01, AT02, AT27, AT29, AT36, AT37, AT44, AT45. Exigences : EE040, EE043, EE042.

### L1D01

**Gérer fournisseurs et réceptions validées**

Lot L1 ; risque NORMAL ; prérequis : G4. Regroupement 13 : L1-01.

Fichiers attendus : `app/Domain/Inventory/GoodsReceipts.php`, `resources/frontend/pages/purchasing/`.

Travaux :
1. Créer fournisseur et réception brouillon.
2. Valider lignes/coûts/lots/emplacements puis mouvements.
3. Conserver réception et enfants finalisés immuables.

Sortie vérifiable :
- Mouvements uniques par effet.
- Coût inconnu explicite.
- Stock augmenté seulement par réception validée.

Validation : Réception multi-lignes et replay.
Scénarios : AT30. Exigences : EE026, EE027.
Tables fonctionnelles principales : `goods_receipt_lines`, `goods_receipts`, `suppliers`.

### L1D02

**Compter inventaires et valoriser les écarts**

Lot L1 ; risque HIGH ; prérequis : L1D01. Regroupement 13 : L1-01.

Fichiers attendus : `app/Domain/Inventory/InventoryCounts.php`, `resources/frontend/pages/inventory/`.

Travaux :
1. Figer périmètre/date/comptage.
2. Vérifier mouvements/quotas pendant comptage et approuver écarts.
3. Valoriser coûts connus et isoler inconnus.

Sortie vérifiable :
- Aucun remplacement brut de stock.
- Écarts justifiés et mouvements append-only.
- Quotas locaux ne disparaissent pas au comptage.

Validation : Comptage avec vente concurrente et coût manquant.
Scénarios : AT30. Exigences : EE026, EE030.
Tables fonctionnelles principales : `inventory_count_lines`, `inventory_counts`.

### L1D03

**Activer seuils, péremption et supervision stock**

Lot L1 ; risque NORMAL ; prérequis : L1D02. Regroupement 13 : L1-01.

Fichiers attendus : `app/Domain/Inventory/StockAlerts.php`, `resources/frontend/pages/stock/Alerts.vue`.

Travaux :
1. Calculer seuil et péremption par article/lot.
2. Dédupliquer alertes et gérer résolution.
3. Expliquer disponible physique/réservé/quota.

Sortie vérifiable :
- Alertes ne créent aucun mouvement.
- Blocage de lot reste effectif offline.
- Dépassement traité sans spam de tâches.

Validation : Scheduler répété et lot nouvellement expiré.
Scénarios : AT30, AT17. Exigences : EE026, EE027.
Tables fonctionnelles principales : `stock_alerts`.

### L1D04

**Implémenter atelier et bon laboratoire**

Lot L1 ; risque HIGH ; prérequis : G4, L1D01. Regroupement 13 : L1-02.

Fichiers attendus : `app/Domain/Workshop/`, `resources/frontend/pages/workshop/`, `resources/frontend/documents/workshop/`.

Travaux :
1. Lier patient/facture/ligne et prescription/version.
2. Enregistrer caractéristiques OD/OG, laboratoire et étapes.
3. Contrôle qualité, reprises, retard et Prêt idempotent ; Livré après remise confirmée.

Sortie vérifiable :
- Pas de version prescription changée après fabrication.
- Bon labo sans données patient inutiles.
- Notification Prêt attend L2 sans bloquer atelier.

Validation : Ordre complet, reprise et notification par révision.
Scénarios : AT31. Exigences : EE028, EE009.
Tables fonctionnelles principales : `workshop_events`, `workshop_order_eyes`, `workshop_orders`.

### L1D05

**Ajouter dépenses et clôture avancée**

Lot L1 ; risque NORMAL ; prérequis : G4, B05. Regroupement 13 : L1-03.

Fichiers attendus : `app/Domain/Cash/CashMovements.php`, `resources/frontend/pages/cash/Expenses.vue`.

Travaux :
1. Catégoriser et autoriser dépenses/entrées hors vente.
2. Rapprocher espèces théoriques/comptées.
3. Conserver preuve et distinguer correction historique/sortie réelle.

Sortie vérifiable :
- Pas de paiement client simulé pour dépense.
- Clôture figée.
- Écart motivé et autorisé.

Validation : Dépense après clôture refusée.
Scénarios : AT25, AT30. Exigences : EE030.
Tables fonctionnelles principales : `cash_movements`, `expense_categories`.

### L1D06

**Rapprocher MoMo/virements et exporter comptabilité**

Lot L1 ; risque NORMAL ; prérequis : L1D05. Regroupement 13 : L1-03.

Fichiers attendus : `app/Domain/Cash/PaymentReconciliation.php`, `resources/frontend/pages/cash/Reconciliation.vue`.

Travaux :
1. Importer relevé fictif puis format réel convenu.
2. Associer lignes de relevé aux écritures sans nouvelle vente.
3. Conserver écarts/frais et validation du rapprochement.

Sortie vérifiable :
- Aucune confirmation fournisseur fictive.
- Un règlement ne se duplique pas lors de rapprochement.
- Format comptable Q13 validé avant export réel.

Validation : Relevé rejoué, références manquantes et doublons.
Scénarios : AT30, AT24. Exigences : EE030, EE029.
Tables fonctionnelles principales : `payment_reconciliation_lines`, `payment_reconciliations`.

### L1D07

**Compléter devis versionnés et conversion en facture**

Lot L1 ; risque NORMAL ; prérequis : G4, B02, D02. Regroupement 13 : transverse/gate.

Fichiers attendus : `app/Domain/Billing/Quotes.php`, `resources/frontend/pages/sales/Quotes.vue`, `resources/frontend/documents/quotes/`.

Travaux :
1. Brouillon/émission/révision du devis avec snapshot.
2. Convertir une version choisie en facture via règles existantes.
3. Préserver provenance sans facture/paiement automatique.

Sortie vérifiable :
- Devis ne compte pas dans CA ni encaissé.
- Conversion idempotente et prix explicitement validés.
- Ancienne version réimprimable.

Validation : Devis révisé puis conversion unique.
Scénarios : AT08, AT11. Exigences : EE020, EE021, EE045.
Tables fonctionnelles principales : `quote_lines`, `quote_revisions`, `quotes`.

### L1D08

**Fusionner identités et approfondir comparaison clinique**

Lot L1 ; risque HIGH ; prérequis : G4, C03. Regroupement 13 : L1-04.

Fichiers attendus : `app/Domain/Patients/MergePatients.php`, `resources/frontend/pages/manager/PatientMerge.vue`, `resources/frontend/pages/clinical/Compare.vue`.

Travaux :
1. Prévisualiser références, homonymes et identité cible.
2. Fusion gérante auditée avec conservation IDs/numéros/snapshots.
3. Approfondir comparaison sans convertir acuités incompatibles.

Sortie vérifiable :
- Aucune fusion automatique.
- Factures émises gardent identité de leur snapshot.
- Révisions et liens financiers restent consultables.

Validation : Fusion avec ventes anciennes et consultation en conflit.
Scénarios : AT04, AT19, AT21, AT42. Exigences : EE001, EE003, EE017.
Tables fonctionnelles principales : `patient_merges`.

### L1D09

**Compléter tableaux et exports de gestion**

Lot L1 ; risque NORMAL ; prérequis : L1D03, L1D04, L1D06, L1D07, L1D08. Regroupement 13 : L1-04.

Fichiers attendus : `app/Domain/Operations/ManagementExports.php`, `resources/frontend/pages/reports/Advanced.vue`.

Travaux :
1. Ajouter tableaux/comparaisons demandés avec définitions.
2. Filtrer export par rôle/période et conserver manifestes.
3. Vérifier cohérence finance/stock/atelier/patients sur même dataset.

Sortie vérifiable :
- Aucun graphique requis ne masque ses inconnus.
- Export complet inclut dictionnaire/provenance.
- Pas de double comptage devis/legacy/corrections.

Validation : Rapprochement vues et exports.
Scénarios : AT24, AT25, AT27, AT30, AT31. Exigences : EE015, EE029, EE030, EE041.

### G5

**Recevoir stock, atelier et gestion avancée**

Lot L1 ; risque NORMAL ; prérequis : L1D09. Regroupement 13 : transverse/gate.

Fichiers attendus : `docs/evidence/G5.md`.

Travaux :
1. Exécuter AT30/31 et non-régression L0 affectée.
2. Vérifier formation stock/atelier et réconciliation.
3. Bilan fonctions actives et formats comptables.

Sortie vérifiable :
- L1 testé sans régression financière/offline.
- Templates/étapes validés.
- Fournisseurs externes non actifs clairement signalés.

Validation : Gate G5 et rapprochement.
Scénarios : AT30, AT31. Exigences : EE026, EE028, EE030.

### L2D01

**Construire adaptateurs et supervision des intégrations**

Lot L2 ; risque HIGH ; prérequis : G5, F07. Regroupement 13 : L2-01, L2-02.

Fichiers attendus : `app/Integrations/`, `app/Domain/Operations/IntegrationHealth.php`, `resources/frontend/pages/manager/Integrations.vue`.

Travaux :
1. Configurer comptes/secrets chiffrés et scopes minimaux.
2. Construire outbox/queue workers courts et retries bornés.
3. Tester doubles de fournisseur et panne sans annuler vente.

Sortie vérifiable :
- Connecteurs désactivés par défaut.
- Secrets récupérables mais jamais loggés.
- Échec visible et aucun message réel des tests.

Validation : Timeout inconnu, rate limit et rotation de secret.
Scénarios : AT34. Exigences : EE035, EE036, EE040.
Tables fonctionnelles principales : `external_links`, `integration_accounts`.

### L2D02

**Synchroniser Google Calendar et les disponibilités**

Lot L2 ; risque HIGH ; prérequis : L2D01, S01. Regroupement 13 : L2-01.

Fichiers attendus : `app/Integrations/GoogleCalendar/`, `app/Domain/Scheduling/CalendarReconciliation.php`.

Travaux :
1. OAuth dédié, mapping minimal et syncTokens.
2. Créer/modifier/annuler avec provenance ; gérer 410/push expiré.
3. Revalider disponibilité externe et signaler conflit humain.

Sortie vérifiable :
- Aucun dossier clinique dans Google.
- Indisponibilité externe ne supprime pas patient interne.
- Réservation ne prétend pas atomicité avec API externe.

Validation : Sync complète, delta, renouvellement et conflit simultané.
Scénarios : AT32, AT33. Exigences : EE031.
Tables fonctionnelles principales : `calendar_sync_states`.

### L2D03

**Raccorder Kommo et miroir commercial minimal**

Lot L2 ; risque NORMAL ; prérequis : L2D01. Regroupement 13 : L2-02.

Fichiers attendus : `app/Integrations/Kommo/`, `app/Domain/Crm/`, `resources/frontend/pages/communication/CrmLinks.vue`.

Travaux :
1. Configurer OAuth/canaux autorisés et identifiants externes.
2. Mapper prospect/patient sous revue sans fusion automatique.
3. Conserver conversations par références, Kommo maître des échanges.

Sortie vérifiable :
- Pas de second CRM complet.
- Aucun diagnostic/paiement poussé comme donnée commerciale.
- Handoff référençable et synchronisé.

Validation : Homonyme/numéro partagé et compte révoqué.
Scénarios : AT34, AT35. Exigences : EE036.
Tables fonctionnelles principales : `crm_conversations`, `crm_leads`, `prospect_contacts`.

### L2D04

**Versionner FAQ et tarifs publics autorisés**

Lot L2 ; risque NORMAL ; prérequis : L2D01. Regroupement 13 : L2-03.

Fichiers attendus : `app/Domain/Crm/KnowledgeBase.php`, `resources/frontend/pages/communication/Knowledge.vue`.

Travaux :
1. Créer brouillons et versions approuvées.
2. Restreindre corpus aux faits publics/FAQ validés.
3. Relier réponses aux versions connues et dates de validité.

Sortie vérifiable :
- Modification gérante publiée explicitement.
- Prix non validé non inventé.
- Aucun dossier clinique comme corpus.

Validation : Question hors corpus et ancienne FAQ.
Scénarios : AT35. Exigences : EE038.
Tables fonctionnelles principales : `knowledge_entries`, `knowledge_entry_versions`.

### L2D05

**Développer assistant limité et reprise humaine**

Lot L2 ; risque HIGH ; prérequis : L2D02, L2D03, L2D04. Regroupement 13 : L2-03.

Fichiers attendus : `app/Domain/Crm/AssistantTools.php`, `app/Domain/Crm/HumanHandoff.php`, `resources/frontend/pages/communication/Handoffs.vue`.

Travaux :
1. Implémenter cinq outils limités du fichier 08.
2. Qualifier/proposer créneaux puis demande de booking validée.
3. Reprise humaine suspend bot, résumé minimal et audit assistant_runs.

Sortie vérifiable :
- Aucune action finance/clinique/stock accessible à IA.
- Demande diagnostic ou humain transmise.
- Double demande ne réserve pas deux fois.

Validation : Injection de consigne, outil interdit et bot suspendu.
Scénarios : AT33, AT35. Exigences : EE037, EE038.
Tables fonctionnelles principales : `assistant_runs`, `conversation_handoffs`.

### L2D06

**Gérer consentements et templates versionnés**

Lot L2 ; risque HIGH ; prérequis : L2D01, S02. Regroupement 13 : L2-04.

Fichiers attendus : `app/Domain/Communication/ConsentLedger.php`, `app/Domain/Communication/MessageTemplates.php`, `resources/frontend/pages/communication/Consents.vue`.

Travaux :
1. Événements par sujet/destination/canal/finalité.
2. Preuve et révocation, numéro partagé/changé pris en compte.
3. Templates/providers/variables/fenêtre de service selon capacités réelles.

Sortie vérifiable :
- Dernier événement GRANTED requis.
- Révocation ne se transforme pas en simple champ patient.
- Destinataire exact vérifié avant envoi.

Validation : Opt-out et changement de numéro.
Scénarios : AT34. Exigences : EE012, EE035.
Tables fonctionnelles principales : `communication_consents`, `message_template_versions`, `message_templates`.

### L2D07

**Activer rappels et notifications transactionnelles**

Lot L2 ; risque HIGH ; prérequis : L2D06, L2D02, L1D04. Regroupement 13 : L2-04.

Fichiers attendus : `app/Domain/Communication/SendTransactionalMessage.php`, `app/Integrations/WhatsApp/`, `app/Integrations/Sms/`.

Travaux :
1. Relier tâches/RDV/atelier à destinataire et version exacts.
2. Revalider consentement juste avant envoi.
3. Dédupliquer, garder attempts et gérer résultat fournisseur inconnu sans replay aveugle.

Sortie vérifiable :
- Aucun envoi avant ACK source.
- Rappels anciens invalidés.
- Message minimal et supervision humaine de l’inconnu.

Validation : Déplacement RDV et consentement révoqué dans la queue.
Scénarios : AT23, AT31, AT34. Exigences : EE012, EE028, EE032, EE033, EE034, EE035.
Tables fonctionnelles principales : `message_attempts`.

### L2D08

**Planifier campagnes ciblées et relances contrôlées**

Lot L2 ; risque HIGH ; prérequis : L2D07. Regroupement 13 : L2-05.

Fichiers attendus : `app/Domain/Communication/Campaigns.php`, `resources/frontend/pages/communication/Campaigns.vue`.

Travaux :
1. Définir segment administratif et audience figée.
2. Prévisualiser/valider budget, template, destinataires et consentements.
3. Réexaminer opt-out avant chaque envoi ; suivre erreurs et pause.

Sortie vérifiable :
- Pas de diffusion groupée exposant une dette.
- Aucun diagnostic dans segmentation commerciale.
- Reprise n’envoie pas deux fois.

Validation : Audience gelée puis opt-out et reprise de campagne.
Scénarios : AT34. Exigences : EE034, EE035.
Tables fonctionnelles principales : `audience_segments`, `campaign_definitions`, `campaign_recipients`.

### L2D09

**Traiter webhooks et réconcilier les fournisseurs**

Lot L2 ; risque HIGH ; prérequis : L2D01, L2D02, L2D03, L2D07. Regroupement 13 : L2-01, L2-02, L2-04.

Fichiers attendus : `app/Integrations/Webhooks/`, `app/Console/Commands/ReconcileIntegrations.php`.

Travaux :
1. Valider protocole fournisseur, challenge/origine/taille et inbox durable.
2. Dédupliquer puis traiter hors réponse rapide.
3. Réconcilier événements non vérifiables et états perdus via API autorisée.

Sortie vérifiable :
- OAuth callback ne sert pas de signature universelle webhook.
- Aucun paiement confirmé par message commercial.
- État fournisseur inconnu reste visible.

Validation : Replay, événement falsifié et timeout de callback.
Scénarios : AT32, AT34. Exigences : EE031, EE035, EE036.
Tables fonctionnelles principales : `webhook_inbox`.

### L2D10

**Valider intégrations et sécurité sur comptes autorisés**

Lot L2 ; risque HIGH ; prérequis : L2D05, L2D08, L2D09. Regroupement 13 : transverse/gate.

Fichiers attendus : `docs/evidence/integrations.md`, `tests/Feature/Integrations/`, `docs/runbooks/integration-incidents.md`.

Travaux :
1. Passer doubles puis tests réels autorisés avec contacts contrôlés.
2. Vérifier coupure/opt-out/handoff/Google et supervision.
3. Archiver permissions fournisseur, limites et coûts confirmés.

Sortie vérifiable :
- AT32–35 prouvés et fournisseurs actifs identifiés.
- Aucun test envoyé à un patient réel sans instruction.
- Reprise manuelle documentée.

Validation : Recette externe autorisée et non-régression L0.
Scénarios : AT32, AT33, AT34, AT35. Exigences : EE031, EE035, EE036, EE037, EE038.

### G6

**Recevoir automatisations et canaux externes**

Lot L2 ; risque NORMAL ; prérequis : L2D10. Regroupement 13 : transverse/gate.

Fichiers attendus : `docs/evidence/G6.md`.

Travaux :
1. Contrôler scénarios, comptes et preuves réels.
2. Confirmer supervision humaine et procédures de pause.
3. Vérifier consentements et aucune fuite clinique.

Sortie vérifiable :
- Seuls canaux réellement configurés annoncés actifs.
- Réservation et reprise humaine reçues.
- Coûts/licences restent ceux validés par responsables.

Validation : Gate G6.
Scénarios : AT32, AT33, AT34, AT35. Exigences : EE031, EE035, EE036, EE037, EE038.

### L3D01

**Auditer le site existant et fixer son raccordement**

Lot L3 ; risque NORMAL ; prérequis : G4. Regroupement 13 : L3-01.

Fichiers attendus : `docs/implementation/existing-site.md`, `docs/implementation/public-contract.md`.

Travaux :
1. Inventorier CMS/hébergement/accès/charte et éléments déjà disponibles.
2. Choisir raccordement minimal sans refaire un site fonctionnel inutilement.
3. Isoler sessions/secrets/private API et public API.

Sortie vérifiable :
- Q12 résolu avant modification réelle.
- Site existant préservé.
- Aucun accès public aux dossiers.

Validation : Revue origine/cookies et API publiques.
Scénarios : AT35. Exigences : EE039, EE040.

### L3D02

**Publier showroom et contenus approuvés**

Lot L3 ; risque NORMAL ; prérequis : L3D01, G5. Regroupement 13 : L3-01.

Fichiers attendus : `app/Domain/PublicSite/Publications.php`, `resources/frontend/pages/public/`, `resources/frontend/pages/manager/Publications.vue`.

Travaux :
1. Versionner publication/médias/droits et approbation.
2. Exposer seulement catalogue public sélectionné.
3. Relier WhatsApp/horaires/localisation et CTA convenus.

Sortie vérifiable :
- Pas de prix achat/stock privé exposé.
- Aucune donnée patient en contenu.
- Responsive et prévisualisation avant publication.

Validation : Contrôle des champs publics et médias.
Scénarios : AT35. Exigences : EE039, EE044.
Tables fonctionnelles principales : `catalog_media`, `catalog_publications`, `public_contents`.

### L3D03

**Connecter réservation publique et protection anti-abus**

Lot L3 ; risque HIGH ; prérequis : L3D02, L2D02, G6. Regroupement 13 : L3-01.

Fichiers attendus : `app/Http/Controllers/PublicBookingController.php`, `app/Domain/PublicSite/PublicBooking.php`, `tests/e2e/public-booking.spec.ts`.

Travaux :
1. Exposer services/créneaux administratifs sans identités.
2. Traiter demande idempotente sous verrou et disponibilité Google.
3. Rate limit, consentement, référence publique et conflit humain.

Sortie vérifiable :
- Aucun compte back-office public.
- Téléphone ne révèle pas existence d’un patient.
- Confirmation correspond à réservation effective.

Validation : Deux demandes même slot et énumération téléphone.
Scénarios : AT33, AT35. Exigences : EE031, EE039.
Tables fonctionnelles principales : `public_booking_requests`.

### L3D04

**Configurer emails et vérifications du domaine**

Lot L3 ; risque NORMAL ; prérequis : L3D01, G4. Regroupement 13 : L3-02.

Fichiers attendus : `docs/runbooks/domain-email.md`, `docs/evidence/domain-checks.md`.

Travaux :
1. Configurer direction/contact si comptes disponibles.
2. Vérifier HTTPS/DNS/SMTP et records mail accessibles.
3. Tester délivrabilité vers boîte contrôlée et reprise incident.

Sortie vérifiable :
- Aucun achat ou changement DNS non autorisé.
- Comptes appartenant au client.
- Secrets distincts des accès développeur.

Validation : Certificat, SMTP et enregistrement des accès.
Scénarios : contrôles d’ingénierie, sans scénario métier autonome. Exigences : EE040.

### L3D05

**Remettre documentation, formation et propriété**

Lot L3 ; risque NORMAL ; prérequis : L3D03, L3D04. Regroupement 13 : L3-02.

Fichiers attendus : `docs/user-guide/`, `docs/runbooks/handover.md`, `docs/evidence/delivery-final.md`.

Travaux :
1. Former profils, exploitation et vidéo smartphone PC.
2. Remettre code/builds/dictionnaire/exports/manifeste et accès contrôlés.
3. Documenter garantie/support, limites et jalons commerciaux sans les modifier.

Sortie vérifiable :
- Chaque EE a statut et preuve ou écart explicite.
- Restauration/export reproduits par responsable.
- Livrables de formation/remise identifiés et reçus.

Validation : PV final et exercice de sortie du prestataire.
Scénarios : AT27, AT29, AT35. Exigences : EE040, EE041, EE043.

### G7

**Clôturer la recette globale et la remise**

Lot L3 ; risque NORMAL ; prérequis : L3D05. Regroupement 13 : transverse/gate.

Fichiers attendus : `docs/evidence/G7.md`, `docs/evidence/requirements-final.json`.

Travaux :
1. Revoir EE001–46 et AT01–45.
2. Rapprocher fonctions actives, contrats et accès client.
3. Consigner écarts restant acceptés et support.

Sortie vérifiable :
- Périmètre entier évalué avec preuves.
- Aucune simulation annoncée comme intégration active.
- Archive de remise sans secret ni données patient de test réelles.

Validation : Gate G7.
Scénarios : AT01, AT02, AT03, AT04, AT05, AT06, AT07, AT08, AT09, AT10, AT11, AT12, AT13, AT14, AT15, AT16, AT17, AT18, AT19, AT20, AT21, AT22, AT23, AT24, AT25, AT26, AT27, AT28, AT29, AT30, AT31, AT32, AT33, AT34, AT35, AT36, AT37, AT38, AT39, AT40, AT41, AT42, AT43, AT44, AT45. Exigences : EE041, EE043, EE046.

## Responsabilité fonctionnelle des 117 tables

F04 crée tout le schéma dès le départ. La table suivante attribue chaque table à la première tâche responsable de son comportement métier ; plusieurs autres tâches peuvent aussi la lire/écrire selon les actions autorisées. Avoir un propriétaire ici prouve un travail prévu, pas un module déjà implémenté.

| Domaine du modèle | Table | Tâche principale |
| --- | --- | --- |
| Référentiels globaux | `clinics` | F05 |
| Référentiels globaux | `roles` | F05 |
| Référentiels globaux | `permissions` | F05 |
| Référentiels globaux | `role_permissions` | F05 |
| Référentiels globaux | `clinical_terms` | C01 |
| Référentiels globaux | `catalog_categories` | B01 |
| Référentiels globaux | `units` | B01 |
| Accès et gouvernance | `users` | F05 |
| Accès et gouvernance | `user_roles` | F05 |
| Accès et gouvernance | `user_permissions` | F05 |
| Documents et exploitation | `stored_files` | D01 |
| Accès et gouvernance | `approvals` | B06 |
| Accès et gouvernance | `audit_events` | F07 |
| Accès et gouvernance | `idempotency_requests` | F07 |
| Patients et clinique | `patient_records` | P01 |
| Patients et clinique | `patient_merges` | L1D08 |
| CRM et assistant | `prospect_contacts` | L2D03 |
| Agenda et suivi | `scheduling_resources` | S01 |
| Agenda et suivi | `appointment_services` | S01 |
| Agenda et suivi | `resource_opening_hours` | S01 |
| Agenda et suivi | `resource_exceptions` | S01 |
| Agenda et suivi | `appointments` | S01 |
| Agenda et suivi | `calendar_blocks` | S01 |
| Patients et clinique | `visits` | P03 |
| Patients et clinique | `consultations` | C01 |
| Patients et clinique | `consultation_complaints` | C01 |
| Patients et clinique | `consultation_histories` | C01 |
| Patients et clinique | `consultation_history_terms` | C01 |
| Patients et clinique | `consultation_ametropias` | C01 |
| Patients et clinique | `consultation_referral_reasons` | C01 |
| Patients et clinique | `visual_acuity_scales` | C02 |
| Patients et clinique | `visual_acuities` | C02 |
| Patients et clinique | `refraction_measurements` | C02 |
| Patients et clinique | `autorefractor_centering` | C02 |
| Patients et clinique | `clinical_revisions` | C03 |
| Patients et clinique | `optical_prescriptions` | C04 |
| Patients et clinique | `optical_prescription_eyes` | C04 |
| Patients et clinique | `optical_prescription_treatments` | C04 |
| Patients et clinique | `medication_orders` | C04 |
| Agenda et suivi | `follow_up_plans` | S02 |
| Catalogue et facturation | `tax_rules` | B01 |
| Catalogue et facturation | `catalog_items` | B01 |
| Catalogue et facturation | `quotes` | L1D07 |
| Catalogue et facturation | `quote_lines` | L1D07 |
| Documents et exploitation | `document_sequences` | O03 |
| Documents et exploitation | `document_number_usages` | B03 |
| Caisse et rapprochement | `cash_registers` | B05 |
| Caisse et rapprochement | `cash_sessions` | B05 |
| Caisse et rapprochement | `expense_categories` | L1D05 |
| Catalogue et facturation | `invoices` | B02 |
| Catalogue et facturation | `invoice_lines` | B02 |
| Catalogue et facturation | `payments` | B04 |
| Catalogue et facturation | `receipts` | B04 |
| Catalogue et facturation | `payment_reversals` | B07 |
| Catalogue et facturation | `credit_notes` | B07 |
| Catalogue et facturation | `credit_note_lines` | B07 |
| Catalogue et facturation | `refunds` | B08 |
| Catalogue et facturation | `legacy_balance_imports` | X01 |
| Caisse et rapprochement | `cash_movements` | L1D05 |
| Caisse et rapprochement | `payment_reconciliations` | L1D06 |
| Caisse et rapprochement | `payment_reconciliation_lines` | L1D06 |
| Stock achats et inventaires | `stock_locations` | I00 |
| Stock achats et inventaires | `suppliers` | L1D01 |
| Stock achats et inventaires | `goods_receipts` | L1D01 |
| Stock achats et inventaires | `stock_positions` | I00 |
| Stock achats et inventaires | `stock_lots` | I00 |
| Stock achats et inventaires | `goods_receipt_lines` | L1D01 |
| Stock achats et inventaires | `inventory_counts` | L1D02 |
| Stock achats et inventaires | `inventory_count_lines` | L1D02 |
| Stock achats et inventaires | `stock_reservations` | I00 |
| Stock achats et inventaires | `deliveries` | I01 |
| Stock achats et inventaires | `delivery_lines` | I01 |
| Stock achats et inventaires | `physical_returns` | I02 |
| Stock achats et inventaires | `stock_movements` | I02 |
| Stock achats et inventaires | `stock_alerts` | L1D03 |
| Atelier optique | `workshop_orders` | L1D04 |
| Atelier optique | `workshop_order_eyes` | L1D04 |
| Atelier optique | `workshop_events` | L1D04 |
| Intégrations | `integration_accounts` | L2D01 |
| Intégrations | `external_links` | L2D01 |
| Intégrations | `calendar_sync_states` | L2D02 |
| Intégrations | `webhook_inbox` | L2D09 |
| Intégrations | `outbox_events` | F07 |
| CRM et assistant | `crm_leads` | L2D03 |
| CRM et assistant | `crm_conversations` | L2D03 |
| CRM et assistant | `conversation_handoffs` | L2D05 |
| CRM et assistant | `knowledge_entries` | L2D04 |
| CRM et assistant | `knowledge_entry_versions` | L2D04 |
| CRM et assistant | `assistant_runs` | L2D05 |
| Communications et relances | `reminder_policies` | S02 |
| Communications et relances | `contact_tasks` | S02 |
| Communications et relances | `contact_attempts` | S02 |
| Communications et relances | `communication_consents` | L2D06 |
| Communications et relances | `message_templates` | L2D06 |
| Communications et relances | `message_template_versions` | L2D06 |
| Communications et relances | `audience_segments` | L2D08 |
| Communications et relances | `campaign_definitions` | L2D08 |
| Communications et relances | `campaign_recipients` | L2D08 |
| Communications et relances | `message_attempts` | L2D07 |
| Showroom et réservation publique | `catalog_publications` | L3D02 |
| Showroom et réservation publique | `catalog_media` | L3D02 |
| Showroom et réservation publique | `public_contents` | L3D02 |
| Showroom et réservation publique | `public_booking_requests` | L3D03 |
| Hors-ligne et synchronisation | `enrolled_devices` | O02 |
| Hors-ligne et synchronisation | `offline_grants` | O02 |
| Hors-ligne et synchronisation | `document_number_allocations` | O03 |
| Hors-ligne et synchronisation | `invoice_write_owners` | O03 |
| Hors-ligne et synchronisation | `device_stock_allocations` | O03 |
| Hors-ligne et synchronisation | `sync_commands` | O05 |
| Hors-ligne et synchronisation | `sync_command_results` | O05 |
| Hors-ligne et synchronisation | `sync_conflicts` | O05 |
| Hors-ligne et synchronisation | `sync_change_log` | O05 |
| Documents et exploitation | `generated_documents` | D01 |
| Documents et exploitation | `export_requests` | X02 |
| Documents et exploitation | `backup_runs` | X02 |
| Documents et exploitation | `restore_tests` | X03 |
| Catalogue et facturation | `quote_revisions` | L1D07 |

## Correspondance avec les tickets de livraison 13

| Ticket 13 | Tâches détaillées |
| --- | --- |
| L0-01 | F01, F02, F03, F04, F05, F06, F07 |
| L0-02 | P01, P02 |
| L0-03 | P03, P04 |
| L0-04 | B01, B02 |
| L0-05 | F08, B02, B03, D01 |
| L0-06 | F08, B04, D01 |
| L0-07 | B06, B07, B08 |
| L0-08 | O03, I00, I01, I02 |
| L0-09 | C01, C02 |
| L0-10 | C03, D02, C04 |
| L0-11 | S01, S02 |
| L0-12 | B05, R01, R02 |
| L0-13 | O07, X01, X02, X03 |
| L0-14 | F09, F10, D01, D02, U01, G3, H01 |
| L0-15 | F06, F07, F08, F10, O01, O02, O03, O04, I00, O05, O06, O07, P04, G3 |
| L1-01 | L1D01, L1D02, L1D03 |
| L1-02 | L1D04 |
| L1-03 | L1D05, L1D06 |
| L1-04 | L1D08, L1D09 |
| L2-01 | L2D01, L2D02, L2D09 |
| L2-02 | L2D01, L2D03, L2D09 |
| L2-03 | L2D04, L2D05 |
| L2-04 | L2D06, L2D07, L2D09 |
| L2-05 | L2D08 |
| L3-01 | L3D01, L3D02, L3D03 |
| L3-02 | L3D04, L3D05 |
