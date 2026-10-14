"""Build the executable planning artifacts; does not implement the application."""
from pathlib import Path
import json, re, hashlib
from collections import defaultdict

ROOT = Path(__file__).resolve().parents[2]
SPEC = ROOT / 'specifications'
OUT = SPEC / 'developpement'
TASKS = []

def task(tid, lot, title, deps, files, steps, done, tests='', reqs='', legacy='', checks='', risk='NORMAL'):
    TASKS.append(dict(id=tid, lot=lot, title=title, depends_on=deps.split(),
        expected_files=files.split(), steps=steps.split(' / '), exit_criteria=done.split(' / '),
        acceptance_scenarios=tests.split(), requirements=reqs.split(), legacy_tickets=legacy.split(),
        engineering_checks=checks.split(' / ') if checks else [], risk=risk,
        status='NOT_STARTED', evidence=[], implemented=False))

task('F01','L0','Confirmer la baseline et les contraintes de lancement','',
 'docs/implementation/startup.md docs/implementation/hosting-audit.md',
 'Lire les autorités et décisions ; inventorier le dossier sans le modifier / Auditer versions locales et offre web si accès disponibles / Distinguer défaut bloquant local et information manquante avant production ; consigner Q01–Q04/Q14',
 'Architecture 23 retenue et écarts documentés / Aucun VPS ni donnée réelle dans la préparation / Environnement local à préparer identifié',
 reqs='EE040 EE042 EE044 EE046',legacy='L0-01',checks='Audit versions, accès, cron et droits DB')
task('F02','L0','Créer le dépôt applicatif et verrouiller les dépendances','F01',
 'composer.json composer.lock package.json package-lock.json vite.config.ts tsconfig.json .env.example',
 'Intégrer Laravel à la racine sans écraser les sources / Ajouter Vue Router, TS strict, Tailwind, PWA, Dexie, PDF et outils de tests / Fixer noms des tables techniques et configurations dev/test/recette',
 'Build PHP/frontend réussit avec lockfiles / Aucun serveur Node requis en production / Secrets absents des fichiers suivis',
 reqs='EE040 EE044 EE046',legacy='L0-01',checks='Installation reproductible et build vide')
task('F03','L0','Installer les contrôles CI et MySQL isolé','F02',
 '.github/workflows/quality.yml phpunit.xml vitest.config.ts playwright.config.ts docs/implementation/testing.md',
 'Créer scripts de qualité PHP/TS et build / Préparer base MySQL test distincte, fixtures et garde environnement / Configurer PHPUnit, Vitest et Playwright sans fournisseur réel',
 'CI exécute une preuve minimale dans chaque runtime / Destruction de base impossible avec configuration production / Logs de CI sans secret ni identité réelle',
 reqs='EE045 EE046',legacy='L0-01',checks='Commande de tests locale et CI documentées')
task('F04','L0','Traduire le schéma complet et prouver son équivalence','F03',
 'database/migrations/ app/Console/Commands/VerifySchema.php app/Console/Commands/AdoptBaseline.php docs/evidence/schema-parity.json',
 'Générer tables puis FK, référentiels, vues et guards selon 23 / Ajouter les seules tables techniques déclarées, sessions ULID incluses / Importer par migrations sur base neuve ; comparer toutes les métadonnées / Vérifier adoption d’un import SQL équivalent et refus d’une divergence',
 '117 tables métier, 464 FK, 484 CHECK, 88 guards et 10 vues équivalents à la baseline / 4 rôles, 49 permissions et 76 termes cliniques présents / Colonnes générées, charset, defaults et référentiels préservés / SQL livré et migration initiale ne sont pas exécutés deux fois',
 reqs='EE046',legacy='L0-01',checks='Metadata MySQL et 41 assertions SQL existantes sur base isolée / Comparaison catalogue–migrations et cas de divergence',risk='HIGH')
task('G0','L0','Valider installation et modèle de toute l’application','F04',
 'docs/evidence/G0.md',
 'Relire équivalence et logs réels / Refaire installation neuve reproductible / Identifier les données réelles et accès encore manquants',
 'Build et schéma complet validés / Développement fictif autorisé par les configurations / Aucune activation financière réelle',
 reqs='EE046',checks='Gate G0 du fichier 25',risk='HIGH')
task('F05','L0','Adapter identité, permissions et bootstrap gérante','G0',
 'app/Models/User.php app/Domain/Access/ app/Policies/ app/Console/Commands/SetupClinic.php',
 'Adapter login/password_hash/ULID/active et session Sanctum / Utiliser rôles/permissions existants et révocations / Créer cabinet/première gérante sans mot de passe universel ; sécuriser reset et réauthentification',
 'Quatre profils démontrables / URL/API/PDF refusés au mauvais rôle / Session invalidée et coffre verrouillé sans perte des pièces',
 'AT01 AT02 AT43','EE016 EE040',legacy='L0-01',checks='CSRF, 401/419, désactivation, reset contrôlé et autre cabinet',risk='HIGH')
task('F06','L0','Matérialiser les contrats et versions','F02 F04',
 'contracts/openapi.yaml contracts/commands/ contracts/events/ contracts/compatibility.json',
 'Définir DTO/routes 08 et compléments 23 / Fixer envelopes, types exacts, erreurs, ACK et versions / Ajouter fixtures de request/response et schémas des snapshots',
 'Contrats versionnés validables / IDs de tous les effets définis avant commit local / Aucun endpoint métier nécessaire laissé implicitement à inventer',
 reqs='EE042 EE045 EE046',legacy='L0-01 L0-15',checks='Validation des fixtures JSON et génération des types TS')
task('F07','L0','Construire le chemin central de commandes','F05 F06',
 'app/Application/CommandBus.php app/Domain/Shared/CommandContext.php app/Domain/Shared/BusinessError.php app/Application/TransactionRunner.php',
 'Implémenter autorisation, contexte historique, ordre des verrous et idempotence / Écrire audit, outbox et change log au commit / Traiter deadlock, rollback, hash différent et réponse perdue',
 'Une mutation critique passe toujours par ce chemin / Aucun effet externe dans la transaction / Publication pull ordonnée sous verrou clinic',
 'AT11 AT12 AT13 AT19 AT38','EE017 EE024 EE045',legacy='L0-01 L0-15',checks='Deux connexions MySQL avec interleavings contrôlés / Rollback de chaque effet après erreur injectée',risk='HIGH')
task('F08','L0','Établir règles pures et vecteurs PHP/TypeScript','F06',
 'app/Domain/Shared/ValueObjects/ resources/frontend/domain/ contracts/vectors/',
 'Implémenter nombres/dates/parsing/null et calculs exacts / Définir mêmes vecteurs acceptés et refusés dans PHP et TS / Fixer bornes SQL, arrondis et versions de règles',
 'Aucun flottant monétaire / Résultats identiques sur tous vecteurs / Fin de mois, année, fuseau, zéro et vide distingués',
 'AT08 AT10 AT14 AT22 AT26 AT40','EE021 EE022 EE033 EE045',legacy='L0-05 L0-06 L0-15',checks='Vitest et PHPUnit sur fichiers JSON communs',risk='HIGH')
task('F09','L0','Construire shell responsive et composants réutilisés','F02',
 'resources/frontend/app/ resources/frontend/components/ resources/frontend/styles/',
 'Créer navigation selon permissions et activation / Construire champs, tableau optique, erreurs, confirmations et états de sauvegarde / Appliquer palette, clavier, zoom et 360 px de 04',
 'Shell accessible et agréable / Aucun menu de module futur présenté comme livré / Composants utilisables dans patient et caisse',
 'AT28 AT29','EE044',legacy='L0-14',checks='Contrastes, focus, clavier et viewport')
task('F10','L0','Définir lectures locales et états de synchronisation','F06 F09',
 'resources/frontend/app/services/ resources/frontend/offline/projections/ resources/frontend/components/SyncStatus.vue',
 'Créer interface de lecture commune et dispatcher de mutation / Distinguer local durable, pending, ACK, conflit et PDF / Préserver formulaire lors de conflit/session expirée ; afficher fraîcheur distante',
 'Aucune écriture Dexie directe depuis page / Aucun total local+serveur compté deux fois / États sauvegarde/sync distincts du réseau',
 'AT29 AT42 AT45','EE023 EE042 EE044',legacy='L0-14 L0-15',checks='Sélecteurs sur entités locales et consolidées avec mêmes IDs')
task('O01','L0','Implémenter le coffre et les compartiments','F05 F09',
 'resources/frontend/offline/vault/ resources/frontend/offline/keys/ tests/e2e/vault.spec.ts',
 'Créer schéma Dexie et chiffrement AES-GCM avec AAD / Envelopper clés par profil et recovery ; préparer recherche en mémoire / Installer PWA et assets sans réponses médicales en cache',
 'Fermeture/reprise retrouve le coffre / Mauvais profil ne déchiffre ni index sensible ni PDF / Verrouillage masque mémoire/UI et préserve ciphertext',
 'AT02 AT36 AT45','EE016 EE042',legacy='L0-15',checks='Inspection IndexedDB/cache et tests de déchiffrement refusé',risk='HIGH')
task('O02','L0','Enrôler le poste et émettre les grants signés','O01 F07',
 'app/Offline/EnrollDevice.php app/Offline/PrepareGrant.php resources/frontend/offline/enrollment/ contracts/vectors/signatures/',
 'Préparer ES256 appareil et JWS grants / Lier utilisateur, droits, versions, durée et snapshot catalogue privé / Vérifier signatures croisées, expiry, révocation différée et horloge suspecte',
 'Algorithme/clé imposés et grant immuable / Catalogue figé vérifiable conservé / Autorité locale bornée sans bearer permanent',
 'AT36 AT43','EE016 EE020 EE042',legacy='L0-15',checks='JCS/JWS PHP–Web Crypto et corruption/alg=none/clé substituée',risk='HIGH')
task('O03','L0','Allouer plages, propriétaires et quotas sans chevauchement','O02 F08',
 'app/Offline/PrepareResources.php app/Offline/ReleaseResources.php resources/frontend/offline/allocations/',
 'Réserver plages sous verrous et exclure numéros centraux / Attribuer factures existantes et epochs ; préparer quotas stock / Restituer seulement après reprise complète, jamais à expiration simple',
 'Plages disjointes et numéros non recyclés / Un seul writer financier par facture / Physique/réserve centrale/quota local non doublement soustraits',
 'AT09 AT39 AT40 AT43','EE021 EE025 EE042',legacy='L0-08 L0-15',checks='Concurrence allocation, année nouvelle et release prématuré refusé',risk='HIGH')
task('O04','L0','Implémenter le commit local et journal commun au poste','O03 F10',
 'resources/frontend/offline/LocalCommandBus.ts resources/frontend/offline/command-journal/ resources/frontend/offline/locks/',
 'Calculer/chiffrer/signer candidat hors transaction / Vérifier CAS versions/head puis committer faits, numéros, journal et ressources ensemble / Gérer deux onglets et profils sans deux journaux financiers parallèles',
 'Aucune pièce affichée avant commit / Collision locale recalcule sans double effet / Commande retrouve résultat idempotent après reprise',
 'AT36 AT37 AT41','EE017 EE042 EE045',legacy='L0-15',checks='Pannes avant/après commit et deux onglets concurrents',risk='HIGH')
task('P01','L0','Créer et modifier patients dans les deux moteurs','O04 F07',
 'app/Domain/Patients/CreatePatient.php app/Domain/Patients/UpdatePatient.php resources/frontend/pages/patients/ resources/frontend/domain/patients/',
 'Valider nom/contact/naissance absente justifiée et versions / Créer ULID/numéro central ou plage locale / Adapter les corrections par rôle et suggestions de doublon sans fusion',
 'Même ID après sync / Téléphone partagé accepté / Dates futures et écrasements silencieux refusés',
 'AT03 AT04 AT36 AT42','EE001 EE045',legacy='L0-02',checks='Patient offline après restart et version conflictuelle')
task('P02','L0','Rechercher patients et ouvrir identité sans ressaisie','P01',
 'app/Domain/Patients/SearchPatients.php resources/frontend/pages/patients/PatientList.vue resources/frontend/components/PatientPicker.vue',
 'Recherche nom accentué/téléphone/dossier paginée / Recherche mémoire sur annuaire autorisé du poste / Signaler serveur indisponible pour dossiers non préparés',
 'Identité réutilisée en vente/visite / Absence de cache ne signifie pas absence certaine du patient / Aucun résumé médical montré à la caisse',
 'AT03 AT04 AT29','EE001 EE044 EE045',legacy='L0-02',checks='Accents/homonymes et recherche privée par rôle')
task('B01','L0','Créer catalogue et paramètres prix/taxes','P01 F08 O03',
 'app/Domain/Billing/Catalog/ resources/frontend/pages/sales/CatalogPicker.vue resources/frontend/domain/billing/',
 'Implémenter catégorie/unité/mode stock et recherche deux caractères / Limiter prix achat à gérante ; figer version de tarif préparée / Utiliser taxe fictive TEST tant que régime réel manque',
 'Articles stockés/services/sur mesure distincts / Unité paire non transformée automatiquement en deux / Prix et taxe officiels non inventés',
 'AT07 AT15','EE020 EE027',legacy='L0-04',checks='Snapshot catalogue et changement central pendant grant')
task('B02','L0','Implémenter les brouillons de facture','B01 F07 O04',
 'app/Domain/Billing/CreateInvoiceDraft.php app/Domain/Billing/UpdateDraft.php resources/frontend/pages/sales/InvoiceEditor.vue',
 'Ajouter lignes, quantités exactes, prix autorisés et total / Réutiliser patient sélectionné et version brouillon / Préparer tous IDs des effets et schéma de snapshot',
 'Brouillon durable sans numéro définitif / Conflit conserve formulaire / Remise non autorisée refusée',
 'AT07 AT08','EE020 EE021 EE045',legacy='L0-04 L0-05',checks='Calculs exacts et modifications concurrentes de brouillon')
task('I00','L0','Préparer le service de réserve utilisé dès l’émission','B01 O03 F07',
 'app/Domain/Inventory/ReservationService.php resources/frontend/domain/inventory/reservations.ts app/Domain/Inventory/OpeningStock.php',
 'Créer positions/lots et stock fictif ou comptage validé / Implémenter réserve exacte centrale ou sous quota local et blocage péremption / Fournir le même port transactionnel à IssueInvoice et future ConfirmDelivery',
 'Émission ne peut valider une vente stockée sans capacité réservée / Plusieurs lignes et lots contrôlés atomiquement / Aucune nouvelle logique de stock à inventer après la caisse',
 'AT15 AT17 AT40','EE025 EE026 EE027 EE045',legacy='L0-08 L0-15',checks='Réserve dernier article et plusieurs lignes sous quota',risk='HIGH')
task('B03','L0','Émettre facture immuable et propriété locale','B02 I00',
 'app/Domain/Billing/IssueInvoice.php resources/frontend/domain/billing/issueInvoice.ts resources/frontend/pages/sales/InvoiceDetails.vue',
 'Écrire enfants avant finalisation, snapshot et usage de numéro dans même commit / Créer writer pour facture locale et ledger initial / Séparer émis, dette et statut PDF',
 'Facture et numéro ne changent plus / Toute erreur rollback toutes ressources / Reprise ne renumérote jamais',
 'AT08 AT09 AT37','EE021 EE023 EE045',legacy='L0-05',checks='Guards et émission concurrente/plage épuisée',risk='HIGH')
task('B05','L0','Ouvrir et clôturer les sessions de caisse','B03 F05',
 'app/Domain/Cash/OpenSession.php app/Domain/Cash/CloseSession.php resources/frontend/pages/cash/',
 'Créer une session ouverte par caisse/caissière / Journaliser ouverture/fermeture également sur poste offline / Figer attendu/compté/écart motivé et fermer seulement après les opérations précédentes',
 'Encaissement CASH exige session admissible / Session close immuable et nouvelles opérations refusées / Correction ultérieure ne réécrit pas le comptage',
 'AT25 AT41','EE030',legacy='L0-12',checks='Deux ouvertures concurrentes et sync ordonnée avant clôture',risk='HIGH')
task('B04','L0','Encaisser acompte et solde avec reçu unique','B03 B05',
 'app/Domain/Billing/RegisterPayment.php app/Domain/Billing/InvoiceLedger.php resources/frontend/pages/sales/PaymentDialog.vue',
 'Contrôler writer/ledger_version/solde et canal / Créer paiement confirmé puis reçu/document PENDING atomiquement / Réutiliser même intention sur double clic/timeout et afficher dette exacte',
 '80000 puis 30000 puis 50000 donnent deux reçus et dette zéro / Aucun UPDATE de facture émise / Paiement au-delà du solde refusé',
 'AT10 AT11 AT12 AT37','EE022 EE023 EE030 EE045',legacy='L0-06',checks='Concurrence MySQL et locale, cash/modes non cash',risk='HIGH')
task('O05','L0','Implémenter push/pull, ACK et conflits durables','P01 B04 O04',
 'app/Offline/PushCommands.php app/Offline/PullChanges.php app/Offline/ResolveConflict.php resources/frontend/offline/sync/',
 'Vérifier signatures/chaîne/grants puis appliquer mêmes actions centrales / Persister ACK par commande et pages pull atomiques / Implémenter finalisation ACCEPTED ou REJECTED en revue sans trou de journal / Rejouer après timeout et conserver sources locales de conflit',
 'Un seul fait par ID/hash / Curseur ne saute pas une transaction non publiée / Conflit résolu ne bloque pas définitivement la caisse et n’invente pas un paiement',
 'AT38 AT39 AT42 AT45','EE017 EE042 EE045',legacy='L0-15',checks='Crash après commit avant ACK / Conflit de séquence N puis reprise N+1 / Pull avec commits entrelacés',risk='HIGH')
task('D01','L0','Générer et conserver les PDFs financiers hors-ligne','B04 F09',
 'resources/frontend/documents/financial/ app/Domain/Operations/StoreDocumentContent.php resources/frontend/documents/fonts/',
 'Générer depuis snapshot committé avec fontes locales / Enregistrer PDF chiffré/hash et réimprimer original / Uploader après ACK source, valider hash/type et stockage privé',
 'Facture et deux reçus imprimables réseau coupé / Panne PDF ne réencaisse jamais / READY central finalisé et téléchargement soumis à Policy',
 'AT08 AT28 AT37','EE014 EE021 EE022',legacy='L0-05 L0-06 L0-14',checks='Inspection A4/accents/longues lignes et PDF retry',risk='HIGH')
task('G1','L0','Prouver la tranche urgente persistante et rejouable','O05 D01 P02',
 'docs/evidence/G1.md tests/e2e/first-slice.spec.ts',
 'Préparer caisse et données fictives puis couper réseau / Créer patient/facture/acompte/solde et PDFs ; fermer et reprendre / Perdre ACK et rejouer ; comparer pièces/numéros/soldes',
 'Tranche traverse services réels / Même ID/numéro et aucun double paiement / Date restante recalculée à partir du temps observé',
 'AT36 AT37 AT38 AT41','EE021 EE022 EE042 EE045',checks='Gate G1 et deux contextes de navigateur',risk='HIGH')
task('B06','L0','Implémenter approbations exactes et réauthentification','B04 F05',
 'app/Domain/Access/RequestApproval.php app/Domain/Access/DecideApproval.php resources/frontend/pages/manager/Approvals.vue',
 'Lier action/cible/hash/motif/durée et auteur gérante / Consommer une seule fois après vérification payload / Autoriser profil gérante présent offline sans PIN commun',
 'Payload changé ou décision expirée refusé / Permissions prix/examen/remise/correction restent distinctes / Décision auditée et partage limité',
 'AT06 AT07 AT14 AT16 AT43','EE016 EE018 EE024 EE025',legacy='L0-07',checks='Replay approbation et hash différent',risk='HIGH')
task('B07','L0','Contre-écrire paiement et émettre avoir lié','B06',
 'app/Domain/Billing/ReversePayment.php app/Domain/Billing/IssueCreditNote.php resources/frontend/pages/sales/Corrections.vue',
 'Plafonner cumul contre-écritures et lignes d’avoir / Écrire objets liés sans modifier les originaux / Distinguer erreur comptable, réduction de vente et retour physique',
 'Suppression paiement impossible / Total des corrections respecte originaux / Solde et CA reflètent faits à leurs dates',
 'AT13 AT14 AT24','EE017 EE024 EE029 EE045',legacy='L0-07',checks='Deux avoirs concurrents et erreur après insertion enfant',risk='HIGH')
task('B08','L0','Rembourser le crédit et conserver sa pièce','B07 B05',
 'app/Domain/Billing/RefundCredit.php resources/frontend/pages/sales/RefundDialog.vue resources/frontend/documents/refunds/',
 'Vérifier crédit réellement disponible et approbation / Enregistrer sortie réelle/mode/session et pièce numérotée / Recalculer ledger sans modifier facture/avoir/reçu',
 'Remboursement ne dépasse pas crédit / Un seul remboursement par clé / Flux argent distinct de l’avoir et du stock',
 'AT14 AT25 AT37','EE024 EE030 EE045',legacy='L0-07',checks='Concurrence refund et session close',risk='HIGH')
task('I01','L0','Gérer réservations et remises sous quota','I00 B03 B06 O03',
 'app/Domain/Inventory/ app/Domain/Inventory/ConfirmDelivery.php resources/frontend/pages/stock/',
 'Réutiliser le service de réserve I00 et ajouter les écrans d’exploitation / Remettre quantités par facture et quota avec effets rangés / Refuser dernier article concurrent, lot expiré et remise à crédit sans décision',
 'Réservation différente d’une sortie / Quota non soustrait deux fois / Facture/patient/article/lot correspondent',
 'AT15 AT16 AT17 AT40','EE025 EE026 EE027 EE045',legacy='L0-08',checks='Dernier article, livraison partielle et vecteur 5/3/1',risk='HIGH')
task('I02','L0','Enregistrer retour physique et sorties exceptionnelles','I01 B07',
 'app/Domain/Inventory/RegisterPhysicalReturn.php app/Domain/Inventory/RegisterStockMovement.php resources/frontend/pages/stock/MovementForm.vue',
 'Plafonner retours par remise originale et justifier inspection/destination / Exiger gérante/motif pour casse/ajustement / Appliquer plusieurs effets d’une commande avec movement_position stable',
 'Avoir seul ne rentre aucun stock / Retours/exceptions auditables et idempotents / Projections rapprochées aux faits',
 'AT16 AT17 AT40','EE025 EE026 EE027 EE045',legacy='L0-08',checks='Retour excessif, lot invalide et plusieurs mouvements par commande',risk='HIGH')
task('O06','L0','Sécuriser mises à jour PWA et schéma local','G1',
 'resources/frontend/offline/update/ resources/frontend/offline/migrations/ contracts/compatibility.json',
 'Proposer SW update sans remplacement forcé / Migrer coffre additivement et conserver anciens templates/règles / Vérifier pending, profils verrouillés, export et capacité avant changement',
 'Aucune purge automatique / Commandes ancienne version encore reprises / API/fichiers privés exclus du fallback SPA',
 'AT45','EE042 EE044',legacy='L0-15',checks='Upgrade avec commandes de deux profils et coupure en migration',risk='HIGH')
task('O07','L0','Exporter et restaurer le poste sans cloner ses droits','O06',
 'resources/frontend/offline/recovery/ app/Offline/ReconcileReplacement.php docs/runbooks/local-recovery.md',
 'Exporter journaux/PDF/manifeste chiffrés et enveloppes recovery / Restaurer pièces émises sur poste neuf et réconcilier origine / Enrôler nouveau device pour émissions futures avec nouvelles plages',
 'Mêmes IDs/numéros pour anciens faits / Aucun clone actif des droits non consommés / Ancien poste réapparu passe en revue',
 'AT39 AT44 AT45','EE040 EE041 EE042',legacy='L0-13 L0-15',checks='Restauration sur nouveau contexte et reprise ancien appareil',risk='HIGH')
task('G2','L0','Valider sécurité financière, stock et reprise','B08 I02 O07 O05',
 'docs/evidence/G2.md tests/Concurrency/ tests/e2e/financial-safety.spec.ts',
 'Exécuter montants/refus/idempotence/approbations / Tester verrous MySQL et ressources offline sur deux contextes / Reprendre après perte/défaut PDF/conflit et rapprocher diagnostics SQL',
 'Aucun défaut critique de pièce/argent/stock/numéro / Litiges conservés et procédure de résolution testée / Reprise après incident ne nécessite pas refaire l’architecture',
 'AT08 AT09 AT10 AT11 AT12 AT13 AT14 AT15 AT16 AT17 AT38 AT39 AT40 AT41 AT43 AT44 AT45','EE021 EE022 EE024 EE025 EE042 EE045',checks='Gate G2 du fichier 25',risk='HIGH')
task('P03','L0','Créer visite, file du jour et contrôle paiement examen','P01 B04 B06',
 'app/Domain/Patients/RegisterVisit.php app/Domain/Clinical/StartConsultation.php resources/frontend/pages/today/',
 'Différencier examen/achat/retrait/contrôle / Relier frais exacts à cette visite ou dérogation explicite / Préparer preuve locale dans le compartiment autorisé',
 'Pas d’examen fictif pour achat / Examen non payé bloqué / File et nouvelle visite n’écrasent pas l’historique',
 'AT05 AT06 AT21','EE002 EE018 EE019',legacy='L0-03',checks='Paiement autre prestation ne débloque pas cet examen')
task('C01','L0','Saisir toutes rubriques narratives du dossier DP','P03 F08',
 'app/Domain/Clinical/SaveClinicalStep.php resources/frontend/pages/clinical/ConsultationWizard.vue resources/frontend/domain/clinical/',
 'Construire étapes motif/lunettes/antécédents/conclusion/orientation / Utiliser termes multiples, Aucun connu exclusif et Autre justifié / Sauvegarder localement chaque étape avec version et auteur',
 'Tous champs DP couverts sans omission / Incomplet conservé explicitement / Aucune donnée clinique dans le rôle caisse',
 'AT18 AT19 AT36','EE004 EE005 EE011 EE013 EE016',legacy='L0-09',checks='Référentiels, Autre, absent et exclusivité Aucun connu')
task('C02','L0','Saisir acuités, autorefracteur et réfraction distinctes','C01',
 'resources/frontend/components/OpticalMeasurements.vue app/Domain/Clinical/Measurements.php contracts/vectors/clinical/',
 'Implémenter 12 acuités pré-réfraction et 3 après / Séparer AUTOREFRACTOR/OBJECTIVE/SUBJECTIVE et DP / Préserver échelle, signe, zéro, vide et conventions professionnelles',
 'OD/OG/OU exacts sur desktop et mobile / Pas de conversion médicale implicite / Incompatibilité d’échelles visible',
 'AT18 AT21 AT28','EE006 EE007 EE008 EE044',legacy='L0-09',checks='Vecteurs numériques et consultation DP intégrale')
task('C03','L0','Finaliser, corriger et comparer les révisions','C02',
 'app/Domain/Clinical/CompleteConsultation.php app/Domain/Clinical/AmendConsultation.php resources/frontend/pages/clinical/History.vue',
 'Créer snapshot complet au commit de finalisation / Nouvelle correction avec motif/auteur, sans écraser ancienne version / Conflit compare base/central/local et garde proposition',
 'Historique chronologique et réimpression ancienne source / Deux consultations restent distinctes / Aucune stratégie dernier écrit gagnant',
 'AT19 AT21 AT42','EE003 EE013 EE017',legacy='L0-10',checks='Deux postes et historique après correction',risk='HIGH')
task('D02','L0','Compléter templates et validation visuelle des documents','D01 C03 B08',
 'resources/frontend/documents/clinical/ resources/frontend/documents/credits/ tests/fixtures/document-snapshots/',
 'Ajouter prescription/dossier/avoir/remboursement et relevé de compte / Couvrir lignes longues, unités, pagination, logo/mentions TEST / Inspecter tous PDFs et impression réelle A4',
 'Aucun total/champ coupé / Ancienne version conserve ses fontes/modèle / Document clinique uniquement au profil autorisé',
 'AT20 AT21 AT28 AT37','EE014 EE021 EE044',legacy='L0-10 L0-14',checks='Snapshots PDF fictifs et revue visuelle')
task('C04','L0','Prescrire optique, médicaments et orientation','C03 D02',
 'app/Domain/Clinical/ValidatePrescription.php resources/frontend/pages/clinical/Prescription.vue app/Domain/Clinical/MedicationOrders.php',
 'Copie explicite de mesure puis édition indépendante / Valider yeux/axe/DP/hauteur/usage/indice/traitements et plusieurs médicaments / Préserver prescription externe identifiée et version validée',
 'Aucune posologie/diagnostic inféré / Prescription validée immuable et imprimable / Vente et stock ne sont pas créés automatiquement',
 'AT20 AT21','EE009 EE010 EE011 EE014',legacy='L0-10',checks='Valeurs DP complètes, deux médicaments et origine externe')
task('P04','L0','Vérifier parcours entre profils et postes préparés','C04 G1 O07',
 'resources/frontend/offline/profile-switch/ tests/e2e/profile-flow.spec.ts docs/runbooks/profile-flow.md',
 'Traverser accueil/caisse/professionnel sur le poste principal avec compartiments séparés / Fournir preuve administrative/examen minimale signée aux profils autorisés / Activer transfert QR/export multiposte seulement si équipement et tests Q02 acquis',
 'Parcours complet garanti sur poste principal / Pas de fuite clinique ni duplication d’ID lors de changement de profil / Aucun parcours multiposte déconnecté non testé promis',
 'AT02 AT05 AT36 AT39','EE016 EE018 EE042 EE045',legacy='L0-03 L0-15',checks='Switch profil offline et attente de sync d’un autre compartiment',risk='HIGH')
task('S01','L0','Configurer et utiliser l’agenda interne','P03 F07',
 'app/Domain/Scheduling/ resources/frontend/pages/agenda/ app/Domain/Scheduling/AvailabilityService.php',
 'Configurer ressources/services/horaires/exceptions / Réserver sous verrou, déplacer/annuler avec version / Conserver offline comme proposition à confirmer',
 'Créneau concurrent refusé / Aucun faux RDV confirmé sans réseau / Historique administratif distinct du dossier clinique',
 'AT23','EE019 EE031',legacy='L0-11',checks='Deux réservations du même créneau et annulation')
task('S02','L0','Calculer suivis et tâches de contact internes','C04 S01',
 'app/Domain/Scheduling/FollowUpCalculator.php app/Domain/Communication/ContactTasks.php resources/frontend/pages/follow-ups/',
 'Calcul date civile depuis origine explicitement choisie / Créer tâches uniques source/échéance et invalider anciennes programmations / Enregistrer contacté/sans réponse/refus/reporté avec tentatives',
 '3 mois et 10–14 jours sans décision médicale automatique / Aucun doublon de tâche après retry / L0 ne déclenche aucun SMS/WhatsApp réel',
 'AT22 AT23','EE012 EE032 EE033 EE034',legacy='L0-11',checks='Fin de mois et changement RDV pendant génération des tâches')
task('R01','L0','Afficher soldes, caisse et finance avec fraîcheur','B08 B05 I02 O05',
 'app/Domain/Billing/FinancialReports.php resources/frontend/pages/reports/Financial.vue',
 'Utiliser agrégats distincts des vues et périodes cabinet / Distinguer CA/encaissements/dettes/crédits, reprise papier et corrections / Montrer dernière sync et totaux distants incomplets',
 'Pas de fanout paiement/avoir / Finance globale uniquement gérante / Local et central ne doublonnent pas les faits ACK',
 'AT24 AT25 AT45','EE023 EE029 EE030',legacy='L0-12',checks='Dates effectives, plusieurs avoirs/paiements et caisse close')
task('R02','L0','Afficher statistiques DP et activité patient','C03 S02',
 'app/Domain/Clinical/ActivityReports.php resources/frontend/pages/reports/Clinical.vue',
 'Définir nouveaux/anciens, distincts du jour et consultations / Calculer sexes/âges/motifs/ordonnances/orientations/contrôles / Afficher inconnus, définitions et période',
 'Tous tableaux DP présents / Aucune agrégation d’échelle incompatible / Accès administratif et clinique séparé',
 'AT25 AT29','EE015 EE044',legacy='L0-12',checks='Patients importés et plusieurs visites même période')
task('X01','L0','Importer catalogue, stock et créances papier contrôlés','B04 B08 G1',
 'app/Domain/Operations/ImportLegacyBalances.php app/Domain/Operations/ImportCatalog.php resources/frontend/pages/manager/Imports.vue',
 'Prévisualiser/rejeter lignes invalides sans écriture partielle / Certifier références papier et reprise du seul solde / Affecter créance à caisse avant paiement offline',
 '80000 déjà payé30000 → reprise50000 sans CA/encaissement initial / Paiement20000 crée reçu et dette30000 / Aucun ancien acompte ni stock de vente dupliqué',
 'AT26 AT39','EE040 EE041 EE045',legacy='L0-13',checks='Import rejoué et référence papier doublonnée',risk='HIGH')
task('X02','L0','Exporter données et sauvegarder base/fichiers/clés','O07 F07 D02 X01',
 'app/Domain/Operations/ExportData.php app/Console/Commands/BackupClinic.php docs/runbooks/server-backup.md',
 'Appliquer Policy/export réauthentifié et manifeste / Sauvegarder DB, pièces privées, clés récupérables et paramètres / Planifier via cron, copier chiffré hors serveur, tracer échec et rétention',
 'Restitution utilisable avec dictionnaire et pièces / Backup serveur ne prétend pas couvrir pending locaux / RPO observé distingué de cible',
 'AT27 AT44','EE040 EE041',legacy='L0-13',checks='Échec stockage distant et restauration des secrets')
task('X03','L0','Restaurer et réconcilier l’environnement serveur','X02',
 'docs/runbooks/server-restore.md docs/evidence/restore-rehearsal.md app/Domain/Operations/RestoreVerifier.php',
 'Restaurer sur environnement isolé et mesurer durée/perte / Vérifier hash/pièces/ledger/séquences/quotas et diagnostics / Rapprocher pièces locales encore non synchronisées avant réouverture',
 'Clés et pièces réellement récupérées / Pas de remise à zéro des séquences / RTO/RPO constatés avec écarts documentés',
 'AT27 AT44','EE040 EE041 EE042',legacy='L0-13',checks='Perte serveur simulée et dernier reçu retrouvé',risk='HIGH')
task('U01','L0','Corriger l’usage et les impressions sur appareils ciblés','P04 R01 R02 S02 D02',
 'tests/e2e/usability.spec.ts docs/evidence/usability.md resources/frontend/components/',
 'Faire parcours opérateur complet avec chronométrage / Tester 360 px/tablette/1366 px/clavier/zoom200 / Tester impression réelle et déverrouillage/reprise sur navigateurs convenus',
 'Patient puis facture/reçu sans ressaisie / Caisse et tableaux lisibles / Défauts bloquants d’usage corrigés',
 'AT28 AT29 AT36 AT37','EE044 EE043',legacy='L0-14',checks='Recette opérateur et imprimante réelle')
task('G3','L0','Exécuter toute la recette L0 et figer le candidat','G2 C04 P04 S02 R01 R02 X01 X03 U01',
 'docs/evidence/G3.md docs/evidence/acceptance-L0.json',
 'Exécuter tous AT01–29 et AT36–45 / Examiner permissions/transactions/PDF/recovery et défauts / Fixer build candidat et paramètres production restant à acquérir',
 '39 scénarios L0 prouvés / Zéro défaut critique ouvert / Aucun test app remplacé par le rapport SQL existant',
 ' '.join(f'AT{i:02d}' for i in list(range(1,30))+list(range(36,46))),
 'EE001 EE002 EE003 EE004 EE005 EE006 EE007 EE008 EE009 EE010 EE011 EE012 EE013 EE014 EE015 EE016 EE017 EE018 EE019 EE020 EE021 EE022 EE023 EE024 EE025 EE026 EE027 EE029 EE030 EE040 EE041 EE042 EE044 EE045',
 legacy='L0-14 L0-15',checks='Gate G3, revue sécurité et preuves par scénario',risk='HIGH')
task('H01','L0','Déployer sur hébergement simple et préparer la bascule','G3',
 'docs/runbooks/deploy-web-hosting.md docs/runbooks/cutover.md docs/evidence/hosting-readiness.md',
 'Valider PHP/MySQL/TRIGGER/VIEW/cron/public/privé/HTTPS sur offre réelle / Construire release sans secrets et appliquer migrations contrôlées / Paramétrer mentions/taxes/catalogue réels, former profils, préparer plages/quotas/imprimante / Rapprocher dernière série papier et créances, vérifier secours/rollback',
 'Recette sur hébergement cible réalisée / Postes/profils préparés et sauvegardes hors serveur actives / Bascule décision explicite du responsable, sans vente test réelle involontaire',
 'AT27 AT28 AT29 AT36 AT37 AT44','EE040 EE041 EE043 EE044',legacy='L0-14',checks='Checklist de bascule du fichier 25',risk='HIGH')
task('G4','L0','Recevoir la première livraison opérationnelle','H01',
 'docs/evidence/G4.md docs/evidence/delivery-L0.md',
 'Vérifier tous prérequis de production et recette du build déployé / Exécuter préflight caisse et exercice de secours / Consigner décision de bascule, limites et bilan des exigences',
 'L0 effectivement utilisable / Absence d’accès client bloque production mais pas faussement code livré / Compte rendu ne présente pas L1/L2/L3 comme actifs',
 'AT01 AT02 AT27 AT29 AT36 AT37 AT44 AT45','EE040 EE043 EE042',checks='Gate G4 et PV L0',risk='HIGH')

task('L1-01','L1','Gérer fournisseurs et réceptions validées','G4',
 'app/Domain/Inventory/GoodsReceipts.php resources/frontend/pages/purchasing/',
 'Créer fournisseur et réception brouillon / Valider lignes/coûts/lots/emplacements puis mouvements / Conserver réception et enfants finalisés immuables',
 'Mouvements uniques par effet / Coût inconnu explicite / Stock augmenté seulement par réception validée',
 'AT30','EE026 EE027',legacy='L1-01',checks='Réception multi-lignes et replay')
task('L1-02','L1','Compter inventaires et valoriser les écarts','L1-01',
 'app/Domain/Inventory/InventoryCounts.php resources/frontend/pages/inventory/',
 'Figer périmètre/date/comptage / Vérifier mouvements/quotas pendant comptage et approuver écarts / Valoriser coûts connus et isoler inconnus',
 'Aucun remplacement brut de stock / Écarts justifiés et mouvements append-only / Quotas locaux ne disparaissent pas au comptage',
 'AT30','EE026 EE030',legacy='L1-01',checks='Comptage avec vente concurrente et coût manquant',risk='HIGH')
task('L1-03','L1','Activer seuils, péremption et supervision stock','L1-02',
 'app/Domain/Inventory/StockAlerts.php resources/frontend/pages/stock/Alerts.vue',
 'Calculer seuil et péremption par article/lot / Dédupliquer alertes et gérer résolution / Expliquer disponible physique/réservé/quota',
 'Alertes ne créent aucun mouvement / Blocage de lot reste effectif offline / Dépassement traité sans spam de tâches',
 'AT30 AT17','EE026 EE027',legacy='L1-01',checks='Scheduler répété et lot nouvellement expiré')
task('L1-04','L1','Implémenter atelier et bon laboratoire','G4 L1-01',
 'app/Domain/Workshop/ resources/frontend/pages/workshop/ resources/frontend/documents/workshop/',
 'Lier patient/facture/ligne et prescription/version / Enregistrer caractéristiques OD/OG, laboratoire et étapes / Contrôle qualité, reprises, retard et Prêt idempotent ; Livré après remise confirmée',
 'Pas de version prescription changée après fabrication / Bon labo sans données patient inutiles / Notification Prêt attend L2 sans bloquer atelier',
 'AT31','EE028 EE009',legacy='L1-02',checks='Ordre complet, reprise et notification par révision',risk='HIGH')
task('L1-05','L1','Ajouter dépenses et clôture avancée','G4 B05',
 'app/Domain/Cash/CashMovements.php resources/frontend/pages/cash/Expenses.vue',
 'Catégoriser et autoriser dépenses/entrées hors vente / Rapprocher espèces théoriques/comptées / Conserver preuve et distinguer correction historique/sortie réelle',
 'Pas de paiement client simulé pour dépense / Clôture figée / Écart motivé et autorisé',
 'AT25 AT30','EE030',legacy='L1-03',checks='Dépense après clôture refusée')
task('L1-06','L1','Rapprocher MoMo/virements et exporter comptabilité','L1-05',
 'app/Domain/Cash/PaymentReconciliation.php resources/frontend/pages/cash/Reconciliation.vue',
 'Importer relevé fictif puis format réel convenu / Associer lignes de relevé aux écritures sans nouvelle vente / Conserver écarts/frais et validation du rapprochement',
 'Aucune confirmation fournisseur fictive / Un règlement ne se duplique pas lors de rapprochement / Format comptable Q13 validé avant export réel',
 'AT30 AT24','EE030 EE029',legacy='L1-03',checks='Relevé rejoué, références manquantes et doublons')
task('L1-07','L1','Compléter devis versionnés et conversion en facture','G4 B02 D02',
 'app/Domain/Billing/Quotes.php resources/frontend/pages/sales/Quotes.vue resources/frontend/documents/quotes/',
 'Brouillon/émission/révision du devis avec snapshot / Convertir une version choisie en facture via règles existantes / Préserver provenance sans facture/paiement automatique',
 'Devis ne compte pas dans CA ni encaissé / Conversion idempotente et prix explicitement validés / Ancienne version réimprimable',
 'AT08 AT11','EE020 EE021 EE045',checks='Devis révisé puis conversion unique')
task('L1-08','L1','Fusionner identités et approfondir comparaison clinique','G4 C03',
 'app/Domain/Patients/MergePatients.php resources/frontend/pages/manager/PatientMerge.vue resources/frontend/pages/clinical/Compare.vue',
 'Prévisualiser références, homonymes et identité cible / Fusion gérante auditée avec conservation IDs/numéros/snapshots / Approfondir comparaison sans convertir acuités incompatibles',
 'Aucune fusion automatique / Factures émises gardent identité de leur snapshot / Révisions et liens financiers restent consultables',
 'AT04 AT19 AT21 AT42','EE001 EE003 EE017',legacy='L1-04',checks='Fusion avec ventes anciennes et consultation en conflit',risk='HIGH')
task('L1-09','L1','Compléter tableaux et exports de gestion','L1-03 L1-04 L1-06 L1-07 L1-08',
 'app/Domain/Operations/ManagementExports.php resources/frontend/pages/reports/Advanced.vue',
 'Ajouter tableaux/comparaisons demandés avec définitions / Filtrer export par rôle/période et conserver manifestes / Vérifier cohérence finance/stock/atelier/patients sur même dataset',
 'Aucun graphique requis ne masque ses inconnus / Export complet inclut dictionnaire/provenance / Pas de double comptage devis/legacy/corrections',
 'AT24 AT25 AT27 AT30 AT31','EE015 EE029 EE030 EE041',legacy='L1-04',checks='Rapprochement vues et exports')
task('G5','L1','Recevoir stock, atelier et gestion avancée','L1-09',
 'docs/evidence/G5.md',
 'Exécuter AT30/31 et non-régression L0 affectée / Vérifier formation stock/atelier et réconciliation / Bilan fonctions actives et formats comptables',
 'L1 testé sans régression financière/offline / Templates/étapes validés / Fournisseurs externes non actifs clairement signalés',
 'AT30 AT31','EE026 EE028 EE030',checks='Gate G5 et rapprochement')

task('L2-01','L2','Construire adaptateurs et supervision des intégrations','G5 F07',
 'app/Integrations/ app/Domain/Operations/IntegrationHealth.php resources/frontend/pages/manager/Integrations.vue',
 'Configurer comptes/secrets chiffrés et scopes minimaux / Construire outbox/queue workers courts et retries bornés / Tester doubles de fournisseur et panne sans annuler vente',
 'Connecteurs désactivés par défaut / Secrets récupérables mais jamais loggés / Échec visible et aucun message réel des tests',
 'AT34','EE035 EE036 EE040',legacy='L2-01 L2-02',checks='Timeout inconnu, rate limit et rotation de secret',risk='HIGH')
task('L2-02','L2','Synchroniser Google Calendar et les disponibilités','L2-01 S01',
 'app/Integrations/GoogleCalendar/ app/Domain/Scheduling/CalendarReconciliation.php',
 'OAuth dédié, mapping minimal et syncTokens / Créer/modifier/annuler avec provenance ; gérer 410/push expiré / Revalider disponibilité externe et signaler conflit humain',
 'Aucun dossier clinique dans Google / Indisponibilité externe ne supprime pas patient interne / Réservation ne prétend pas atomicité avec API externe',
 'AT32 AT33','EE031',legacy='L2-01',checks='Sync complète, delta, renouvellement et conflit simultané',risk='HIGH')
task('L2-03','L2','Raccorder Kommo et miroir commercial minimal','L2-01',
 'app/Integrations/Kommo/ app/Domain/Crm/ resources/frontend/pages/communication/CrmLinks.vue',
 'Configurer OAuth/canaux autorisés et identifiants externes / Mapper prospect/patient sous revue sans fusion automatique / Conserver conversations par références, Kommo maître des échanges',
 'Pas de second CRM complet / Aucun diagnostic/paiement poussé comme donnée commerciale / Handoff référençable et synchronisé',
 'AT34 AT35','EE036',legacy='L2-02',checks='Homonyme/numéro partagé et compte révoqué')
task('L2-04','L2','Versionner FAQ et tarifs publics autorisés','L2-01',
 'app/Domain/Crm/KnowledgeBase.php resources/frontend/pages/communication/Knowledge.vue',
 'Créer brouillons et versions approuvées / Restreindre corpus aux faits publics/FAQ validés / Relier réponses aux versions connues et dates de validité',
 'Modification gérante publiée explicitement / Prix non validé non inventé / Aucun dossier clinique comme corpus',
 'AT35','EE038',legacy='L2-03',checks='Question hors corpus et ancienne FAQ')
task('L2-05','L2','Développer assistant limité et reprise humaine','L2-02 L2-03 L2-04',
 'app/Domain/Crm/AssistantTools.php app/Domain/Crm/HumanHandoff.php resources/frontend/pages/communication/Handoffs.vue',
 'Implémenter cinq outils limités du fichier 08 / Qualifier/proposer créneaux puis demande de booking validée / Reprise humaine suspend bot, résumé minimal et audit assistant_runs',
 'Aucune action finance/clinique/stock accessible à IA / Demande diagnostic ou humain transmise / Double demande ne réserve pas deux fois',
 'AT33 AT35','EE037 EE038',legacy='L2-03',checks='Injection de consigne, outil interdit et bot suspendu',risk='HIGH')
task('L2-06','L2','Gérer consentements et templates versionnés','L2-01 S02',
 'app/Domain/Communication/ConsentLedger.php app/Domain/Communication/MessageTemplates.php resources/frontend/pages/communication/Consents.vue',
 'Événements par sujet/destination/canal/finalité / Preuve et révocation, numéro partagé/changé pris en compte / Templates/providers/variables/fenêtre de service selon capacités réelles',
 'Dernier événement GRANTED requis / Révocation ne se transforme pas en simple champ patient / Destinataire exact vérifié avant envoi',
 'AT34','EE012 EE035',legacy='L2-04',checks='Opt-out et changement de numéro',risk='HIGH')
task('L2-07','L2','Activer rappels et notifications transactionnelles','L2-06 L2-02 L1-04',
 'app/Domain/Communication/SendTransactionalMessage.php app/Integrations/WhatsApp/ app/Integrations/Sms/',
 'Relier tâches/RDV/atelier à destinataire et version exacts / Revalider consentement juste avant envoi / Dédupliquer, garder attempts et gérer résultat fournisseur inconnu sans replay aveugle',
 'Aucun envoi avant ACK source / Rappels anciens invalidés / Message minimal et supervision humaine de l’inconnu',
 'AT23 AT31 AT34','EE012 EE028 EE032 EE033 EE034 EE035',legacy='L2-04',checks='Déplacement RDV et consentement révoqué dans la queue',risk='HIGH')
task('L2-08','L2','Planifier campagnes ciblées et relances contrôlées','L2-07',
 'app/Domain/Communication/Campaigns.php resources/frontend/pages/communication/Campaigns.vue',
 'Définir segment administratif et audience figée / Prévisualiser/valider budget, template, destinataires et consentements / Réexaminer opt-out avant chaque envoi ; suivre erreurs et pause',
 'Pas de diffusion groupée exposant une dette / Aucun diagnostic dans segmentation commerciale / Reprise n’envoie pas deux fois',
 'AT34','EE034 EE035',legacy='L2-05',checks='Audience gelée puis opt-out et reprise de campagne',risk='HIGH')
task('L2-09','L2','Traiter webhooks et réconcilier les fournisseurs','L2-01 L2-02 L2-03 L2-07',
 'app/Integrations/Webhooks/ app/Console/Commands/ReconcileIntegrations.php',
 'Valider protocole fournisseur, challenge/origine/taille et inbox durable / Dédupliquer puis traiter hors réponse rapide / Réconcilier événements non vérifiables et états perdus via API autorisée',
 'OAuth callback ne sert pas de signature universelle webhook / Aucun paiement confirmé par message commercial / État fournisseur inconnu reste visible',
 'AT32 AT34','EE031 EE035 EE036',legacy='L2-01 L2-02 L2-04',checks='Replay, événement falsifié et timeout de callback',risk='HIGH')
task('L2-10','L2','Valider intégrations et sécurité sur comptes autorisés','L2-05 L2-08 L2-09',
 'docs/evidence/integrations.md tests/Feature/Integrations/ docs/runbooks/integration-incidents.md',
 'Passer doubles puis tests réels autorisés avec contacts contrôlés / Vérifier coupure/opt-out/handoff/Google et supervision / Archiver permissions fournisseur, limites et coûts confirmés',
 'AT32–35 prouvés et fournisseurs actifs identifiés / Aucun test envoyé à un patient réel sans instruction / Reprise manuelle documentée',
 'AT32 AT33 AT34 AT35','EE031 EE035 EE036 EE037 EE038',checks='Recette externe autorisée et non-régression L0',risk='HIGH')
task('G6','L2','Recevoir automatisations et canaux externes','L2-10',
 'docs/evidence/G6.md',
 'Contrôler scénarios, comptes et preuves réels / Confirmer supervision humaine et procédures de pause / Vérifier consentements et aucune fuite clinique',
 'Seuls canaux réellement configurés annoncés actifs / Réservation et reprise humaine reçues / Coûts/licences restent ceux validés par responsables',
 'AT32 AT33 AT34 AT35','EE031 EE035 EE036 EE037 EE038',checks='Gate G6')

task('L3-01','L3','Auditer le site existant et fixer son raccordement','G4',
 'docs/implementation/existing-site.md docs/implementation/public-contract.md',
 'Inventorier CMS/hébergement/accès/charte et éléments déjà disponibles / Choisir raccordement minimal sans refaire un site fonctionnel inutilement / Isoler sessions/secrets/private API et public API',
 'Q12 résolu avant modification réelle / Site existant préservé / Aucun accès public aux dossiers',
 'AT35','EE039 EE040',legacy='L3-01',checks='Revue origine/cookies et API publiques')
task('L3-02','L3','Publier showroom et contenus approuvés','L3-01 G5',
 'app/Domain/PublicSite/Publications.php resources/frontend/pages/public/ resources/frontend/pages/manager/Publications.vue',
 'Versionner publication/médias/droits et approbation / Exposer seulement catalogue public sélectionné / Relier WhatsApp/horaires/localisation et CTA convenus',
 'Pas de prix achat/stock privé exposé / Aucune donnée patient en contenu / Responsive et prévisualisation avant publication',
 'AT35','EE039 EE044',legacy='L3-01',checks='Contrôle des champs publics et médias')
task('L3-03','L3','Connecter réservation publique et protection anti-abus','L3-02 L2-02 G6',
 'app/Http/Controllers/PublicBookingController.php app/Domain/PublicSite/PublicBooking.php tests/e2e/public-booking.spec.ts',
 'Exposer services/créneaux administratifs sans identités / Traiter demande idempotente sous verrou et disponibilité Google / Rate limit, consentement, référence publique et conflit humain',
 'Aucun compte back-office public / Téléphone ne révèle pas existence d’un patient / Confirmation correspond à réservation effective',
 'AT33 AT35','EE031 EE039',legacy='L3-01',checks='Deux demandes même slot et énumération téléphone',risk='HIGH')
task('L3-04','L3','Configurer emails et vérifications du domaine','L3-01 G4',
 'docs/runbooks/domain-email.md docs/evidence/domain-checks.md',
 'Configurer direction/contact si comptes disponibles / Vérifier HTTPS/DNS/SMTP et records mail accessibles / Tester délivrabilité vers boîte contrôlée et reprise incident',
 'Aucun achat ou changement DNS non autorisé / Comptes appartenant au client / Secrets distincts des accès développeur',
 reqs='EE040',legacy='L3-02',checks='Certificat, SMTP et enregistrement des accès')
task('L3-05','L3','Remettre documentation, formation et propriété','L3-03 L3-04',
 'docs/user-guide/ docs/runbooks/handover.md docs/evidence/delivery-final.md',
 'Former profils, exploitation et vidéo smartphone PC / Remettre code/builds/dictionnaire/exports/manifeste et accès contrôlés / Documenter garantie/support, limites et jalons commerciaux sans les modifier',
 'Chaque EE a statut et preuve ou écart explicite / Restauration/export reproduits par responsable / Livrables de formation/remise identifiés et reçus',
 'AT27 AT29 AT35','EE040 EE041 EE043',legacy='L3-02',checks='PV final et exercice de sortie du prestataire')
task('G7','L3','Clôturer la recette globale et la remise','L3-05',
 'docs/evidence/G7.md docs/evidence/requirements-final.json',
 'Revoir EE001–46 et AT01–45 / Rapprocher fonctions actives, contrats et accès client / Consigner écarts restant acceptés et support',
 'Périmètre entier évalué avec preuves / Aucune simulation annoncée comme intégration active / Archive de remise sans secret ni données patient de test réelles',
 ' '.join(f'AT{i:02d}' for i in range(1,46)),'EE041 EE043 EE046',checks='Gate G7')

# One primary functional owner per table. F04 creates ALL of them immediately.
ownership = {
 'F05':'clinics roles permissions role_permissions users user_roles user_permissions',
 'F07':'audit_events idempotency_requests outbox_events',
 'B06':'approvals', 'D01':'stored_files generated_documents',
 'O03':'document_sequences document_number_allocations invoice_write_owners device_stock_allocations',
 'B03':'document_number_usages', 'X02':'export_requests backup_runs', 'X03':'restore_tests',
 'P01':'patient_records', 'L1-08':'patient_merges', 'P03':'visits',
 'C01':'clinical_terms consultations consultation_complaints consultation_histories consultation_history_terms consultation_ametropias consultation_referral_reasons',
 'C02':'visual_acuity_scales visual_acuities refraction_measurements autorefractor_centering',
 'C03':'clinical_revisions', 'C04':'optical_prescriptions optical_prescription_eyes optical_prescription_treatments medication_orders',
 'L2-03':'prospect_contacts crm_leads crm_conversations', 'L2-05':'conversation_handoffs assistant_runs',
 'L2-04':'knowledge_entries knowledge_entry_versions',
 'S01':'scheduling_resources appointment_services resource_opening_hours resource_exceptions appointments calendar_blocks',
 'S02':'follow_up_plans reminder_policies contact_tasks contact_attempts',
 'B01':'catalog_categories units tax_rules catalog_items',
 'L1-07':'quotes quote_lines quote_revisions', 'B02':'invoices invoice_lines',
 'B04':'payments receipts', 'B07':'payment_reversals credit_notes credit_note_lines',
 'B08':'refunds', 'X01':'legacy_balance_imports', 'B05':'cash_registers cash_sessions',
 'L1-05':'expense_categories cash_movements', 'L1-06':'payment_reconciliations payment_reconciliation_lines',
 'I00':'stock_locations stock_positions stock_lots stock_reservations', 'I01':'deliveries delivery_lines',
 'I02':'physical_returns stock_movements', 'L1-01':'suppliers goods_receipts goods_receipt_lines',
 'L1-02':'inventory_counts inventory_count_lines', 'L1-03':'stock_alerts',
 'L1-04':'workshop_orders workshop_order_eyes workshop_events',
 'L2-01':'integration_accounts external_links', 'L2-02':'calendar_sync_states', 'L2-09':'webhook_inbox',
 'L2-06':'communication_consents message_templates message_template_versions',
 'L2-08':'audience_segments campaign_definitions campaign_recipients', 'L2-07':'message_attempts',
 'L3-02':'catalog_publications catalog_media public_contents', 'L3-03':'public_booking_requests',
 'O02':'enrolled_devices offline_grants',
 'O05':'sync_commands sync_command_results sync_conflicts sync_change_log',
}
# Detailed IDs must not collide with the delivery-group IDs already used in 13.
detailed_ids = {f'L{lot}-{i:02d}':f'L{lot}D{i:02d}' for lot,limit in [(1,9),(2,10),(3,5)] for i in range(1,limit+1)}
for t in TASKS:
    t['id'] = detailed_ids.get(t['id'],t['id'])
    t['depends_on'] = [detailed_ids.get(d,d) for d in t['depends_on']]
ownership = {detailed_ids.get(tid,tid):names for tid,names in ownership.items()}
catalog = json.loads((ROOT/'database/schema_catalog.json').read_text(encoding='utf-8'))
tables = {t['name']:t for t in catalog['tables']}
by_id = {t['id']:t for t in TASKS}
assert len(by_id) == len(TASKS)
owners = {}
for tid,names in ownership.items():
    assert tid in by_id
    for name in names.split():
        assert name in tables and name not in owners, name
        owners[name] = tid
assert set(owners) == set(tables), sorted(set(tables)-set(owners))
for t in TASKS:
    t['primary_tables'] = sorted(n for n,tid in owners.items() if tid==t['id'])
    assert all(d in by_id and d!=t['id'] for d in t['depends_on'])
    assert len(t['steps'])>=2 and len(t['exit_criteria'])>=2 and t['expected_files']
    assert t['acceptance_scenarios'] or t['engineering_checks']
    for d in t['depends_on']:
        assert int(by_id[d]['lot'][1]) <= int(t['lot'][1]), (t['id'],d)

# Stable topological order: prefer lower lot then definition order among ready tasks.
rank = {t['id']:i for i,t in enumerate(TASKS)}
ordered=[]; emitted=set()
while len(ordered)<len(TASKS):
    ready=[t for t in TASKS if t['id'] not in emitted and set(t['depends_on'])<=emitted]
    assert ready, 'Dependency cycle'
    next_task=min(ready,key=lambda t:(t['lot'],rank[t['id']]))
    ordered.append(next_task); emitted.add(next_task['id'])
for i,t in enumerate(ordered,1): t['execution_order']=i
all_req={r for t in TASKS for r in t['requirements']}
all_at={a for t in TASKS for a in t['acceptance_scenarios']}
assert all_req == {f'EE{i:03d}' for i in range(1,47)}
assert all_at == {f'AT{i:02d}' for i in range(1,46)}
legacy_text=(SPEC/'13-plan-livraison-et-backlog.md').read_text(encoding='utf-8')
legacy_ids=set(re.findall(r'\b(?:L[0123]-\d{2})\b',legacy_text))
mapped_legacy={x for t in TASKS for x in t['legacy_tickets']}
assert legacy_ids <= mapped_legacy, sorted(legacy_ids-mapped_legacy)

payload=dict(version='3.0',date='2026-10-14',scope='ENTIRE_APPLICATION',
    application_implemented=False,baseline_table_count=len(tables),
    baseline_catalog_sha256=hashlib.sha256((ROOT/'database/schema_catalog.json').read_bytes()).hexdigest(),
    task_statuses=['NOT_STARTED','IN_PROGRESS','BLOCKED','IN_REVIEW','DONE'],
    task_count=len(TASKS),execution_order=[t['id'] for t in ordered],tasks=ordered,
    table_ownership=owners,
    coverage=dict(requirements=sorted(all_req),acceptance_scenarios=sorted(all_at),legacy_tickets=sorted(mapped_legacy)),
    instructions='Choose the earliest NOT_STARTED task with all direct dependencies DONE. Record real evidence before DONE. Production gates require verified external prerequisites and responsible approval; elapsed time is not approval.')
(OUT/'backlog.json').write_text(json.dumps(payload,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')

lines=['# Tâches détaillées de développement','',
 'Version 3.0, 14 octobre 2026. Généré depuis [construire_backlog.py](developpement/construire_backlog.py), avec le même contenu que [backlog.json](developpement/backlog.json). Les chemins de code ci-dessous sont des **fichiers à créer**, pas des fichiers applicatifs déjà livrés.',
 '',f'{len(TASKS)} tâches, dont {sum(t["lot"]=="L0" for t in TASKS)} pour L0 et ses gates. Tous les statuts sont NOT_STARTED ; les vérifications présentes dans ce dossier portent sur la cohérence du plan, pas sur les tests futurs. Le [plan 22](22-plan-developpement-detaille.md) et les [contrats 23](23-contrats-ingenierie.md) s’appliquent à chaque fiche.',
 '', '## Ordre exécutable','',
 '| Ordre | Tâche | Lot | Prérequis directs | Risque |', '| --- | --- | --- | --- | --- |']
for t in ordered: lines.append(f'| {t["execution_order"]} | [{t["id"]} {t["title"]}](#{t["id"].lower()}) | {t["lot"]} | {", ".join(t["depends_on"]) or "Aucun"} | {t["risk"]} |')
lines += ['', 'Les dépendances désignent des preuves acquises, pas la simple création d’un fichier. Une dépendance indirecte n’est pas répétée dans chaque fiche. Une tâche BLOCKED pour accès fournisseur n’autorise pas à simuler sa livraison ; prendre un autre travail prêt et consigner la cause.',
 '', 'Les tickets L0-01…L3-02 de 13 restent les regroupements de livraison. Les IDs F/O/P/B/etc. et L1Dxx/L2Dxx/L3Dxx précisent les tâches exécutables ; ils ne remplacent pas les regroupements du planning. Le champ « regroupement 13 » établit la correspondance.',
 '', '## Fiches exécutables']
for t in ordered:
    lines += ['',f'### {t["id"]}','',f'**{t["title"]}**','',
      f'Lot {t["lot"]} ; risque {t["risk"]} ; prérequis : {", ".join(t["depends_on"]) or "aucun"}. Regroupement 13 : {", ".join(t["legacy_tickets"]) or "transverse/gate"}.',
      '', 'Fichiers attendus : '+', '.join(f'`{f}`' for f in t['expected_files'])+'.','', 'Travaux :']
    lines += [f'{i}. {s}.' for i,s in enumerate(t['steps'],1)]
    lines += ['', 'Sortie vérifiable :']+[f'- {s}.' for s in t['exit_criteria']]
    lines += ['', 'Validation : '+('; '.join(t['engineering_checks']) or 'tests métier ci-dessous')+'.',
      'Scénarios : '+(', '.join(t['acceptance_scenarios']) or 'contrôles d’ingénierie, sans scénario métier autonome')+'. Exigences : '+(', '.join(t['requirements']) or 'socle transverse')+'.']
    if t['primary_tables']: lines += ['Tables fonctionnelles principales : '+', '.join(f'`{n}`' for n in t['primary_tables'])+'.']
lines += ['', '## Responsabilité fonctionnelle des 117 tables','',
 'F04 crée tout le schéma dès le départ. La table suivante attribue chaque table à la première tâche responsable de son comportement métier ; plusieurs autres tâches peuvent aussi la lire/écrire selon les actions autorisées. Avoir un propriétaire ici prouve un travail prévu, pas un module déjà implémenté.',
 '', '| Domaine du modèle | Table | Tâche principale |', '| --- | --- | --- |']
for name,t in tables.items(): lines.append(f'| {t["domain"]} | `{name}` | {owners[name]} |')
lines += ['', '## Correspondance avec les tickets de livraison 13','', '| Ticket 13 | Tâches détaillées |','| --- | --- |']
for lid in sorted(legacy_ids): lines.append(f'| {lid} | '+', '.join(t['id'] for t in ordered if lid in t['legacy_tickets'])+' |')
(SPEC/'24-taches-developpement.md').write_text('\n'.join(lines)+'\n',encoding='utf-8')
print(json.dumps(dict(tasks=len(TASKS),L0=sum(t['lot']=='L0' for t in TASKS),tables=len(owners),requirements=len(all_req),scenarios=len(all_at),dependency_graph='ACYCLIC',status='PLAN_ONLY'),ensure_ascii=True))
