# Contrats d’ingénierie et conventions d’implémentation

Version 3.0, 14 octobre 2026. Ces décisions de conception complètent 05/08/19/21 pour supprimer les ambiguïtés de codage. Elles restent distinctes des demandes textuelles PC/CDC/DP. Le fichier 15 les référence ; le backlog 24 les met en œuvre. Aucun contrat décrit ici n’est annoncé déjà implémenté.

## Dépôt et frontières

Créer Laravel à la racine sans écraser CLAUDE.md, specifications/, database/ ni les originaux. Initialiser éventuellement le squelette dans un répertoire de préparation puis intégrer les fichiers explicitement. Préserver les générateurs SQL existants. Composer et npm ont chacun un lockfile versionné. Le frontend réside dans resources/frontend/ et produit des assets locaux ; la SPA est servie par Laravel avec une seule origine HTTPS.

```text
app/
  Domain/
    Shared/        ValueObjects, CommandContext, Clock, BusinessError
    Access/        identité, permissions, approbations
    Patients/      patients et visites
    Clinical/      examens, prescriptions et révisions
    Billing/       pièces, ledger et encaissements
    Cash/          sessions et rapprochements
    Inventory/     positions, quotas et mouvements
    Scheduling/    disponibilités, contrôles et RDV
    Workshop/      ordres et étapes
    Communication/ consentements et tâches
    Crm/           miroir autorisé et reprise humaine
    PublicSite/    publications et réservation publique
    Operations/    export, backup et restauration
  Application/     CommandBus, transaction et projections
  Offline/         grants, préparation, push, pull, conflits
  Integrations/    interfaces fournisseur et adaptateurs
  Http/            controllers minces, requests, resources
  Policies/
resources/frontend/
  app/             routeur, bootstrap, navigation et profil
  components/      contrôles accessibles et composants métier
  domain/          règles pures, erreurs et types
  offline/         coffre, commandes, projections, sync et recovery
  documents/       templates versionnés et fontes
  pages/           patients, clinique, ventes, agenda, gérante
contracts/
  openapi.yaml
  commands/        schémas JSON versionnés
  events/          schémas d’événements minimaux
  vectors/         données fictives PHP/TS communes
  compatibility.json
database/migrations/
tests/Unit/ tests/Feature/ tests/Concurrency/
tests/frontend/ tests/e2e/ tests/fixtures/
docs/implementation/ docs/runbooks/ docs/evidence/
```

Organisation modulaire interne, pas de microservices. Actions nommées conformément à 08, avec classes une action/unité métier. Les controllers vérifient format/session et appellent l’action ; ils ne calculent pas les soldes. Les observers Eloquent ne portent aucun effet financier ou message caché. Les composants Vue appellent un service d’application, jamais une table Dexie directement.

Les sélecteurs/projections locales sont une interface de lecture commune aux pages. Les écrans ne tentent pas d’assembler arbitrairement un total serveur et un total local ; la projection tient compte des IDs ACK pour ne pas compter deux fois le même fait.

## Schéma, migrations et bootstrap

Le catalogue existant constitue la baseline de 117 tables métier. Ne pas remplacer patient_records par patients, password_hash par password ou les codes rôles par les tables d’un package. Les clés simples ULID permettent les modèles Eloquent ; les relations et mutations restent explicitement filtrées par clinic_id. La protection FK composite ne remplace pas ce filtrage.

Produire quatre étapes initiales déterministes : création de toutes tables/index/CHECK/colonnes générées, ajout de toutes FK après les tables, référentiels/vues, puis guards. Générer des instructions SQL explicites depuis la définition canonique existante, encapsulées dans les migrations si le Schema Builder ne restitue pas exactement une expression. Une liste structurée d’instructions est préférable à un parseur SQL maison.

Pour les triggers, chaque CREATE TRIGGER complet est une instruction PDO distincte. DELIMITER est une instruction de client SQL, à exclure des instructions PDO ; découper aveuglément sur les points-virgules détruit BEGIN/END. Construire les listes de guards depuis le générateur, avec les mêmes noms et corps, et comparer les métadonnées. L’import initial partiel n’est pas rendu atomique par une transaction PHP : MySQL DDL peut committer implicitement.

Installation normale : migrations sur une base neuve, sans import manuel préalable du fichier complet. Si une base a déjà été créée par le SQL livré, ajouter une commande d’adoption contrôlée : vérifier tables/colonnes/index/FK/CHECK/vues/triggers/référentiels avant de marquer la baseline acquise. Refuser toute divergence ; pas de IF NOT EXISTS/INSERT IGNORE pour la dissimuler. Aucun double import.

Les tables techniques du framework sont séparées et inventoriées : migrations, fw_sessions, fw_cache, fw_cache_locks, fw_jobs, fw_job_batches, fw_failed_jobs et, si utilisé, fw_password_reset_tokens. Adapter fw_sessions.user_id au ULID ; les IDs internes de jobs peuvent suivre les conventions du framework. Leur présence ne change pas le compteur des 117 tables métier. Paramétrer les drivers pour ces noms ; ne pas détourner outbox_events comme table de queue Laravel. Le broker de reset est adapté au login/email réel, sans email fictif ou inscription publique.

Les contrôles de métadonnées applicatifs comparent le sous-ensemble de tables du catalogue canonique et inventorient séparément le framework. Ne pas exiger artificiellement 117 tables au total dans une base comprenant aussi fw_sessions/migrations ; ne pas retirer une table métier pour conserver ce total. Les smoke tests SQL existants restent séparés et exécutés sur une base isolée, leurs fixtures étant rollbackées.

Migrations historiques sans suppression automatique de pièces : en production, corriger par migration additive versionnée. Les méthodes down qui supprimeraient les tables métier refusent l’exécution automatique. Réinitialiser uniquement les bases isolées de tests par une procédure dédiée qui vérifie environnement et nom de base. Aucune commande migrate:fresh/refresh/reset dans un runbook production.

Premier compte : commande CLI contrôlée et interactive, ou procédure locale d’installation sécurisée sur hébergement sans SSH. Créer clinic puis users ; attribuer le rôle avec assigned_by égal au premier utilisateur désormais existant. La démo utilise des fixtures séparées, jamais un mot de passe gérante universel. Une page web d’installation, si indispensable, doit être protégée par un secret d’amorçage à usage unique et désactivée après succès.

L’emploi de migrations standard et les contraintes d’un schema dump sont documentés officiellement : [Laravel migrations](https://laravel.com/framework/docs/13.x/migrations). La liste SQL embarquée permet ici une installation sans dépendre d’un client mysql présent chez l’hébergeur.

## Identité et droits

User utilise id chaîne non incrémentale, login, display_name, password_hash et active. Adapter getAuthPasswordName/getAuthPassword à password_hash. Désactiver « rester connecté » tant qu’aucun stockage de token compatible n’est prévu ; ne pas ajouter remember_token implicitement. La connexion cherche dans le cabinet configuré côté serveur. L’utilisateur ne choisit pas un clinic_id arbitraire de confiance.

Le serveur vérifie active et les permissions effectives à chaque point d’entrée sensible. user_roles/user_permissions prennent en compte revoked_at et les dates définies ; les 49 permissions seedées restent l’autorité des codes. La matrice 09 traduit les besoins des quatre rôles. Ne pas installer un package de rôles créant un deuxième référentiel parallèle.

Utiliser Sanctum pour la session SPA, cookie du seul sous-domaine, CSRF et expiration de session. Réponse 419 : conserver la saisie/coffre, renouveler le contexte de session et rejouer seulement la même commande idempotente lorsque l’utilisateur est réauthentifié. Aucune ouverture de routes de sync anonymes pour contourner une session expirée. [Le mode SPA de Sanctum](https://laravel.com/framework/docs/13.x/sanctum) s’appuie sur les sessions/cookies ; les Policies restent nécessaires.

Les profils locaux appartiennent à des comptes nominatifs préparés. La clé ES256 enrôlée est une clé d’appareil, car enrolled_devices conserve une clé publique par appareil. Les grants signés lient device_id, user_id et permissions ; l’attribution à l’auteur découle de ce contexte, pas d’une clé publique supplémentaire inventée par profil. La clé privée du poste est protégée/enveloppée pour les profils habilités à signer ; les clés de données restent compartimentées. Cette attribution technique ne revendique pas une signature personnelle certifiée.

Compartiments : ADMINISTRATIVE, CLINICAL, FINANCIAL et MANAGER, minimum opérationnel nécessaire à chaque profil. Un annuaire administratif peut être partagé entre profils autorisés avec des enveloppes de clé distinctes ; la caisse n’obtient pas la clé clinique. Aucun envoi automatique de tous les dossiers à tous les postes.

## Valeurs et sérialisation

| Valeur | Convention du contrat |
| --- | --- |
| IDs | ULID ASCII conservé ; IDs de tous les effets connus avant commit local |
| Monnaie | XAF ; entiers bornés conformément au SQL, sans flottants monétaires |
| Quantité, taux, mesure optique | Chaînes décimales exactes ; parsing explicite de la virgule à l’entrée |
| BIGINT compteur | Chaîne de chiffres dans transport et JCS ; pas de nombre JS hors plage sûre |
| Instants | UTC ISO 8601 ; précision contractuelle fixe à la milliseconde pour les événements locaux |
| Date civile | YYYY-MM-DD, calcul dans Africa/Douala configuré ; jamais conversion implicite minuit UTC |
| Vide et zéro | null différent de "0.00" ; aucun remplacement du vide par zéro |
| Texte | UTF-8 ; valeurs Unicode originales conservées, recherche normalisée séparément |

Les calculs suivent les expressions du schéma et le fichier 03. PHP utilise entiers/arithmétique décimale exacte, TS BigInt ou bibliothèque décimale verrouillée pour les étapes intermédiaires. Un résultat final doit respecter les bornes SQL avant commit. Ne pas convertir quantity en Number puis appeler Math.round pour décider un total.

Vecteurs communs minimum : 1.125 × 1001 → 1126 pour l’arrondi de ligne, remises/taxes selon configuration de démo, facture 80000/acompte30000/solde50000, avoir10000/paiements50000/dette20000, avoir total80000/paiement50000/remboursement50000/solde0, reprise papier50000/paiement20000/dette30000, physique5/quota3/remise1/physique4/quota2/disponible central2, fin de mois/année, zéro/vide et Unicode. Le PHP et le TS passent les mêmes fichiers JSON, y compris cas refusés.

## Deux versions distinctes en facturation

invoices.version est la version du brouillon. Après émission le guard interdit toute mise à jour de la ligne facture, y compris son version ou updated_at. Ne jamais incrémenter cette colonne lors d’un encaissement ou mettre un statut PAID inexistant.

Définir une projection ledger_version pour la concurrence financière : 1 + nombre de paiements confirmés + contre-écritures + avoirs émis + remboursements, tous liés à cette facture. Chaque événement reste append-only ; le nombre augmente lorsque son fait est enregistré. Calculer la projection sous le verrou de facture ; l’état payé/partiellement payé/dette/crédit est dérivé du solde.

expected_version signifie version brouillon pour UpdateDraft/IssueInvoice, version ledger pour RegisterPayment/ReversePayment/IssueCreditNote/RefundCredit. Les DTO répondent avec draft_version et ledger_version nommés. Ne pas faire correspondre ledger_version à une nouvelle colonne non prévue du SQL. Les livraisons ont leurs propres contrôles cumulés ; elles ne simulent pas un paiement.

À l’émission : insérer/compléter le parent dans l’état non final admis par son enum, écrire tous ses enfants et snapshots, puis passer au statut final dans la même transaction. Même ordre pour avoir, prescription, livraison, réception et inventaire : les guards refusent l’ajout d’un enfant à un parent déjà finalisé. Un encaissement crée d’abord payments confirmé, puis receipts associé ; le reçu ne précède pas le paiement.

## Chemin transactionnel central

CommandContext porte clinique/authentification, device/grant éventuel, source DIRECT/SYNC/JOB, correlation_id, temps fiable disponible et commande. Il distingue une Policy actuelle pour action directe et le contexte d’autorisation historique d’une commande locale ; une révocation aujourd’hui ne détruit pas une pièce hier valide. Les cas ambigus passent en revue.

Ordre des verrous de L0 : clinic → appareil si journal → idempotence → séquences/plages → propriété de facture → facture → session caisse → positions/lots/quotas par ID trié → approbation. Toutes les actions et opérations de préparation/transfert respectent cet ordre ; aucun controller verrouille en sens inverse. La ligne clinic sert aussi de verrou de publication par cabinet pour le journal pull. Ce choix simple sérialise les mutations centrales d’un cabinet ; mesurer ses performances avant de l’optimiser.

Dans une transaction : vérifier droits/contrat → acquérir verrous → trouver ou réserver la clé d’action → vérifier hash/version/ressources → recalculer → écrire faits/projections/audit/outbox/sync_change_log → enregistrer le résultat idempotent → commit. Réponse uniquement après commit. Deadlock : au plus trois tentatives internes avec même clé, attente bornée ; après échec retourner une erreur réessayable, sans effet partiel.

Un hash de payload différent pour la même clé est un conflit. Une action committée renvoie le résultat durable existant même si la première réponse a été perdue. Un refus métier sans effet ne devient pas COMMITTED. Définir la politique de réessai après refus dans le contrat : la nouvelle intention modifiée reçoit une nouvelle command_id ; les tentatives transport gardent l’ancienne.

Le verrou clinic, tenu avant l’insertion du change log jusqu’au commit, empêche un curseur de dépasser un numéro auto-incrémenté de ce cabinet encore non committé. Le pull balaie les changements déjà committés du même cabinet, sous filtrage de visibilité ; il avance aussi sur des entrées filtrées pour ne pas boucler. Une page a une borne supérieure et un curseur signés/opaques ; le client ne valide cette borne qu’après commit local de toute la page. Ne pas utiliser un MAX global observé pour avancer au-delà de changements du cabinet non publiés. Toute mutation officielle devant être diffusée passe par ce verrou ; les écritures SQL directes d’exploitation sont hors de ce protocole et requièrent maintenance/réconciliation.

L0 optimise la fiabilité avant le débit. Les tests de charge contrôlent les cibles 05 ; si elles échouent, affiner les verrous avec preuve équivalente d’ordre de publication, sans retirer les invariants.

## Journal local et commit

Un seul journal ordonné par device_id, commun aux profils habilités, et des payloads chiffrés séparément par compartiment. Les métadonnées de routage sans identité patient ni contenu clinique peuvent être communes. Chaque profil ouvert synchronise les commandes qu’il est autorisé à déchiffrer ; les commandes d’autres profils restent conservées. Préflight de fin de journée exige ouverture/sync de tous les profils avec pending, ou leur export/reprise autorisés.

La sync ne doit pas s’arrêter définitivement faute d’un ancien profil : recovery gérante inclut les enveloppes de clés nécessaires et tous les journaux. Un transfert de propriété exige l’absence de commandes non finalisées, tous profils confondus. Le dashboard indique le compartiment en attente, sans afficher ses données au mauvais rôle.

Service local de commande : lire entités/versions/head/grant → calculer les changements et attribuer IDs → chiffrer/signer hors transaction IDB → ouvrir transaction Dexie → vérifier une seconde fois head/versions/compteurs/idempotence → écrire tout ou rien. En cas de CAS obsolète, abandonner ce candidat non committé et recalculer. Aucune opération réseau ou Web Crypto longue n’est attendue au milieu de la transaction. [Dexie décrit les limites de durée et de zone transactionnelle](https://dexie.org/docs/Dexie/Dexie.transaction()).

Tables locales proposées : vault_meta, key_envelopes, grants, encrypted_entities, encrypted_documents, command_journal, command_status, allocations, aggregate_heads, pull_cursors, recovery_manifests. Les indexes contiennent seulement IDs opaques/classifications nécessaires ; données administratives recherchées en mémoire après déchiffrement. Index clinique, noms/téléphones ou snapshots ne restent pas en clair.

Toutes les ressources allouées et le command_journal sont atomiques avec les faits. Le verrou Web Locks/BroadcastChannel peut réduire la contention entre onglets, mais la preuve d’exclusion est le CAS transactionnel et les clés uniques. L’idempotence locale retrouve une commande déjà committée au lieu d’en écrire une autre.

## Signature et chiffrement

core contient command_id, command_name, device_id, actor_id, grant_id, clinic_id, device_sequence, previous_event_hash, schema_version, rules_version, occurred_at_local, last_server_time, entity_type, entity_id, expected_version, références de plages/quotas et payload métier. Les nulls/champs optionnels ont une forme fixée au schéma JSON ; aucune suppression variable selon runtime.

event_hash = SHA-256 des octets UTF-8 de JCS(core). signature est un JWS compact ES256 portant command_id, device_id et event_hash. La vérification serveur reconstitue core et son hash, puis vérifie le JWS et l’origine. Signature et event_hash sont exclus de core ; previous_event_hash y figure. Le protected header impose alg=ES256, typ et kid connus ; refuser alg=none et toute substitution de clé fournie dans le payload.

Employer des bibliothèques conformes à [RFC 8785 JCS](https://www.rfc-editor.org/info/rfc8785/) et [RFC 7518 JWA](https://www.rfc-editor.org/info/rfc7518/), avec des vecteurs croisés PHP/navigateur. ES256 : signature JWS R||S de longueur fixe ; les conversions DER éventuelles appartiennent à la bibliothèque. Ne pas fabriquer une canonicalisation par tri approximatif de JSON.stringify/json_encode ni une conversion de signature non testée.

Hashes en hexadécimal minuscule de 64 caractères, JWS compact base64url standard ; première previous_event_hash égale null. Les compteurs du core sont des chaînes décimales sans zéro non significatif, les montants bornés des entiers JSON sûrs. Ces conventions font partie des fixtures de F06/O02.

AES-GCM utilise une clé aléatoire par compartiment, nonce unique par chiffrement, AAD liant cabinet/appareil/compartiment/type/ID/version. Les clés sont enveloppées via mécanisme Web Crypto standard ; secret utilisateur distinct du mot de passe serveur, dérivation PBKDF2-HMAC-SHA256 avec sel et paramètres calibrés/stockés. Limiter les tentatives locales et mesurer le coût sur poste réel ; une limite UI ne protège pas contre une attaque hors ligne sur disque. Clé de récupération forte conservée hors du poste, sous contrôle gérante. Les opérations Web Crypto ont un contexte sécurisé : [Web Crypto](https://developer.mozilla.org/en-US/docs/Web/API/Web_Crypto_API).

L0 synchronise profil ouvert par HTTPS : déchiffrer en mémoire, envoyer core/JWS, chiffrer à nouveau à réception/stockage serveur selon classification. sync_commands.payload_ciphertext contient l’enveloppe sensible re-chiffrée serveur, avec encryption_key_id ; aucun plaintext clinique dans le service worker. Le profil verrouillé ne synchronise pas son contenu sensible. Le chiffrement hybride de background sync est une extension explicite, inutile pour tenir L0.

## Push, ACK et résolution

Le serveur conserve un inbox de commandes valides, même si l’application attend une dépendance. Il vérifie origin/permissions/taille/hash/signature avant de stocker une entrée métier de confiance. Une attaque non authentifiée n’avance jamais le journal. Il traite ensuite les numéros de séquence dans l’ordre de cet appareil.

| Situation | Résultat durable et action client |
| --- | --- |
| Appliquée | ACCEPTED, références des IDs d’origine, versions/projections ; marquer ACK après commit local |
| Déjà appliquée, même hash | ALREADY_ACCEPTED sur le transport, résultat stocké ACCEPTED ; aucune nouvelle écriture |
| Parent ou profil précédent manquant | WAITING_DEPENDENCY, liste des dépendances accessibles ; conserver et reprendre dans l’ordre |
| Conflit métier/auth historique | CONFLICT_REQUIRES_REVIEW ; originaux et projections centrales chiffrés, aucun écrasement |
| Résolution finale sans application | REJECTED motivé et autorisé, journal conservé ; afficher litige/rejet et exclure des faits centraux acceptés |

Le schéma sync_command_results prévoit REJECTED ; son ACK est ajouté au contrat 08/19. Une résolution de conflit ne réécrit jamais core/hash/signature. Une opération correctrice a une nouvelle command_id et référence explicitement le conflit/l’original.

last_accepted_sequence/hash désigne ici le préfixe du journal **finalisé et vérifié**, comprenant ACCEPTED et REJECTED décidé après revue. ACCEPTED garde seul le sens « fait métier appliqué ». RECEIVED/WAITING_DEPENDENCY/CONFLICT ne permettent pas de sauter la séquence. Cette distinction évite de bloquer toute la caisse pour toujours après un conflit ; elle doit être affichée et testée, jamais assimilée à « tout payé ».

Un reçu déjà imprimé en litige demeure une pièce locale conservée. La résolution financière exige rapprochement des faits réels et décision gérante ; elle ne détruit ni pièce ni encaissement physique constaté, et ne crée pas arbitrairement un second paiement central. Si le fait ne peut pas être accepté tel quel, préserver l’original en conflit finalisé, puis enregistrer une correction/reprise liée selon décision. Les futures commandes dépendant de l’ancien solde se revalident et peuvent rester en revue ; aucun ACK de succès fictif.

Limites initiales proposées et configurables : push 50 commandes, taille décompressée totale 2 Mio, pull 100 changements ; fichiers PDF sur endpoint distinct. Traiter chaque commande dans sa transaction, pas un lot de 50 comme une vente indivisible. Arrêter l’application du préfixe au premier non finalisé, préserver les commandes reçues ensuite. Retry transport borné avec délai progressif et bouton manuel ; timeout rejoue les mêmes IDs.

Création locale de facture : IssueInvoice importe la propriété de son device et epoch initial dans la même transaction ; pas de transfert anticipé par une API concurrente. Facture centrale existante : prepare attribue la propriété avant téléchargement. Grant expiré ne libère pas propriété/quota/plage. Accès de profils à une facture sur la même caisse ne clone pas son ledger.

## Documents, snapshots et notifications

IssueInvoice/RegisterPayment produisent source immuable + generated_documents PENDING dans leur transaction ; les IDs de paiement/reçu/document sont fournis de manière déterministe par la commande locale. Après commit, génération client, stockage chiffré PDF et hash ; états locaux READY/FAILED distincts du statut central du document.

Synchroniser d’abord la source ; uploader ensuite le PDF. Le serveur contrôle Policy, source_key/version/template, taille/type réel et SHA-256 des octets reçus, calcule lui-même private_path, puis crée stored_files et passe generated_documents READY. Un READY finalisé n’est plus modifié ; retry identique retrouve le fichier existant. Source non ACK : garder PDF local, ne pas exposer par une URL publique. Hash assure l’intégrité du fichier, pas la véracité fiscale de son contenu ; le contrôle de données et la recette visuelle restent requis.

L’ancien template et ses fontes restent disponibles pour toute source en attente. Réimpression ouvre l’original, jamais un recalcul sur identité/prix récents. Mentions manquantes → document TEST clairement marqué ; production_documents_enabled reste false jusqu’à validation des paramètres réels.

Audit et outbox sont écrits au commit métier. Une panne de worker/fournisseur n’annule pas un paiement. Queue technique exécute les traitements ; outbox garde leur état métier. claim de job court, verrou de non-chevauchement, retries bornés avec next_attempt_at et état d’échec visible. Aucun webhook ne calcule directement un paiement ou une prescription.

## Contrats à matérialiser avant chaque tranche

F06 crée OpenAPI 3.1 et JSON Schema 2020-12 : request/response/actions 08, envelopes/core/JWS/ACK/cursors, erreurs, idempotence et compatibilité. Les outils compatibles sont verrouillés ; les fixtures valent contrat exécutable. Ajouter les routes manquantes nécessaires au schéma sans renommer les routes existantes : sessions de caisse/open/close, retour physique, consultation/prescription brouillon et correction, stock initial, grants/conflits/export/restauration, administration des comptes/cabinet et lectures nécessaires aux écrans.

| Groupe | Routes complémentaires fixées pour L0, préfixe /api/v1 |
| --- | --- |
| Session | POST /auth/login, POST /auth/logout, GET /auth/me, POST /auth/reauthenticate ; endpoint CSRF du mécanisme Sanctum |
| Cabinet/comptes | GET/PATCH /clinic/settings ; GET/POST /staff ; PATCH /staff/{id} ; POST /staff/{id}/permissions ; réauthentification et Policy gérante |
| Dossier | GET /patients/{id}, GET /patients/{id}/history, GET /visits?date=..., GET /consultations/{id} ; réponse filtrée selon rôle |
| Prescription | POST /consultations/{id}/prescriptions, POST /prescriptions/external, PATCH /prescriptions/{id}, GET /prescriptions/{id}, POST /prescriptions/{id}/revisions ; validate reste route 08 |
| Ventes | GET /invoices/{id}, GET /invoices?status=... ; solde/ledger retournés, pas un statut PAID stocké |
| Caisse | GET /cash-sessions/current, POST /cash-sessions, POST /cash-sessions/{id}/close ; journal local pour caisse propriétaire |
| Stock | GET /stock/availability, POST /stock/opening, POST /deliveries/{id}/physical-returns ; mouvements et remises restent routes 08 |
| Agenda/suivi | GET /appointments?from=...&to=..., POST /appointments/{id}/cancel, GET /contact-tasks?due_date=..., GET /reports/activity ; aucune confirmation offline supposée |
| Poste/sync | Routes de 19 + GET /devices/{id}/health ; GET /sync/conflicts ; POST /sync/conflicts/{id}/resolution ; aucune résolution anonyme |
| Restitution | POST /exports, GET /exports/{id}, GET /exports/{id}/download ; exports privés sous Policy ; backup/restauration par commandes contrôlées et runbooks |

GET est sans mutation métier et paginé si collection. Les routes POST/PATCH de ce tableau suivent idempotence/version/Policy et le même bus que 08. Les scopes administratifs/cliniques/financiers de lecture sont explicites dans OpenAPI ; un nom de route commun ne donne pas toutes les rubriques au même rôle.

catalog_version lie le snapshot privé stored_files ou le contenu signé immuable ; rules_version et template_version sont des versions séparées. compatibility.json déclare pour chaque release les commandes/règles/coffres/templates qu’elle sait reprendre. Ne pas désactiver une ancienne version tant qu’un appareil possède des pièces pendantes dans cette version.

Tout endpoint sensible a un test interdit/mauvais cabinet/mauvais profil. Toute commande économique a calcul/refus/idempotence/retry/concurrence ; toute commande disponible offline a fermeture/reprise/ACK. Toutes les routes privées et les erreurs API sont exclues du fallback SPA, du cache médical générique et du site public. Les fichiers 24/25 définissent les preuves à produire.
