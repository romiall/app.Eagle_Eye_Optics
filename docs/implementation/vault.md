# Coffre local chiffré et compartiments (O01)

Tâche O01 du backlog (L0, risque **HIGH** ; prérequis F05 et F09 ; regroupement L0-15). Sorties
attendues : la fermeture et la reprise retrouvent le coffre ; un mauvais profil ne déchiffre ni
l'index sensible ni un PDF ; le verrouillage masque la mémoire et l'écran et préserve le chiffré.
Validation : inspection d'IndexedDB et du cache, tests de déchiffrement refusé. Scénarios AT02, AT36,
AT45 ; exigences EE016, EE042. Références : fichier 19 (« Préparation du poste », « Conservation et
incidents locaux »), fichier 23 (« Compartiments », « Tables locales », « Chiffrement »), fichier 09.

**Statut : conception version 2, avant implémentation.** La version 1 a été relue par trois angles
(cryptographie, exploitation hors ligne, compartiments et conformité) puis chaque constat important
a été soumis à un sceptique : 73 constats retenus (4 bloquants, 42 majeurs, 27 mineurs). Cette version
tranche chacun : adopté (D), renvoyé à une tâche précise (R) ou écarté (E), avec sa raison (section
« Registre des décisions »). La section « Implémentation » s'ajoutera à la livraison.

## Périmètre

O01 livre, sans écran métier :

1. les primitives cryptographiques et leur auto-test (AES-GCM avec AAD, PBKDF2, HKDF, enveloppes) ;
2. la hiérarchie de clés : une clé aléatoire par compartiment, enveloppée par profil et par la clé de
   récupération ; un emplacement réservé à la clé de signature du poste (O02) ;
3. le coffre Dexie (tables, durabilité stricte, versions) et ses opérations : création, profils,
   déverrouillage limité en tentatives, verrouillage, changement de secret, récupération, retrait ;
4. l'écriture et la lecture chiffrées d'entités, de documents et de brouillons, par compartiment ;
5. la session du coffre : autorité persistée, plusieurs onglets, inactivité, verrouillage à la
   déconnexion ;
6. l'annuaire administratif cherché en mémoire ;
7. le classement des permissions et des commandes par compartiment (contrat partagé) ;
8. l'intégration à l'application : route de déverrouillage, écran de verrouillage qui remplace la
   coquille, état dans l'en-tête, identité d'action ;
9. l'état du stockage (persistance, quota, aller-retour) pour O02 ;
10. la preuve en navigateur réel (IndexedDB brut, disque, cache).

Hors O01 : enrôlement et grants signés (O02), plages de numéros (O03), journal, commit local et
attestations de frais d'examen signées (O04), synchronisation (O05), mise à jour du schéma et de la
PWA (O06), export et restauration (O07). **Rotation des clés de compartiment : non planifiée** (voir
« Écarts de planning »).

## Registre des décisions

Adoptées (D), renvoyées (R) ou écartées (E). Le numéro sert de référence dans le code et les tests.

| N° | Décision | Constat à l'origine |
| --- | --- | --- |
| D1 | **Clés de session non extractables, KEK jamais conservée.** Au déverrouillage : dérivation de la KEK, `unwrapKey` non extractable de chaque compartiment, KEK abandonnée. Toute opération qui ré-enveloppe (ajout de profil, changement de secret, réémission, remplacement de la récupération) **ressaisit un secret** (profil acteur ou clé de récupération), re-dérive la KEK, déballe en extractable dans une portée fermée, enveloppe pour la cible, ne laisse aucune référence. `wrapKey` refuse une clé non extractable : « extractable le temps de l'envelopper » était faux | Bloquant ×3 |
| D2 | **Trois phases pour toute opération sur les clés.** A : tout `crypto.subtle` (dérivation, enveloppes, IV), hors transaction. B : vérification en mémoire (déballage de chaque enveloppe candidate, aller-retour d'un vecteur de test) ; échec = rien d'écrit. C : une seule transaction `rw` qui compare une époque (CAS) et n'écrit que des lignes déjà prêtes. Un test échoue si `crypto.subtle` est appelé pendant `Dexie.currentTransaction`. Les modules `crypto/` et `keys/` n'importent pas la base ; `vault/store` n'importe pas `crypto.subtle` (ESLint) | Majeurs |
| D3 | **AAD** `["eeo-vault-v1", but, cabinet, coffre, …]` : tableau JSON de chaînes non vides, nombres en décimal sans zéro initial, but obligatoire (`entity`, `document`, `draft`, `envelope`, `kcv`), octets figés par des vecteurs. **`vaultId`** (ULID aléatoire créé avec le coffre, immuable) remplace `device_id` : le poste n'est connu qu'après l'enrôlement (O02 dépend d'O01) et un poste de remplacement (O07) reçoit un nouveau `device_id`. `deviceId` est consigné une fois dans `vault_meta` (`bindDevice`) et figure dans le journal signé. Écart assumé avec la ligne « appareil » du fichier 23, à consigner en décision | Majeurs |
| D4 | **`keyId`** (entier par compartiment, 1 au départ) dans l'AAD, les enveloppes et les lignes dès la version 1 : une rotation ultérieure n'impose pas de migrer le format | Majeur |
| D5 | **Une seule KEK par profil** : `profiles.kdf = {salt (16 octets), iterations, normalization: 'NFKC'}` et `kekVersion`, lus et bornés ([600 000 ; 5 000 000] en production, `KDF_PARAMS_REJECTED` sans calcul sinon) ; un **témoin de validation de la KEK** (`kcv`, AES-GCM d'une constante) distingue mauvais secret et enveloppe corrompue | Majeurs |
| D6 | **Politique PBKDF2 injectée à la construction** (minimum, maximum, cible de calibration). La production la fixe en dur dans `offline/api` ; les tests passent 1 000 itérations. Un test Playwright lit les itérations dans l'IndexedDB réelle et exige ≥ 600 000 | Mineurs |
| D7 | **Pas de compteur de chiffrements ni de plafond 2^31** (code mort et ligne chaude). Nonce de 96 bits aléatoires généré **dans** `seal()`, `tagLength` 128 explicite. La borne NIST SP 800-38D (2^32 par clé) est documentée ; une rotation (R-rotation) la rend inatteignable | Majeurs / mineurs |
| D8 | **Secret local** : normalisé NFKC avant PBKDF2 (vecteur de test NFC/NFD), sans rognage (espaces de tête ou de fin refusés), au moins 14 caractères, ne contenant pas le login, refus des motifs triviaux (`SECRET_TOO_WEAK`). L'application propose un secret généré : 4 mots prononçables de 5 lettres (consonne-voyelle ×2 puis consonne, tirage uniforme sans biais), environ 68 bits. Pas de bibliothèque d'estimation d'entropie (poids de la PWA) ni de liste de mots tierce (licence). Le champ n'est pas mémorisé par le navigateur | Majeur |
| D9 | **Clé de récupération** : 32 octets aléatoires + 2 octets de contrôle, Crockford base32 en 11 groupes de 5 caractères ; dérivation HKDF-SHA256 (sel = `vaultId`, info `eeo-vault-v1/recovery/kek` et `…/recovery/verifier`). Création **en deux phases** : préparation en mémoire, affichage, ressaisie complète, puis seulement l'écriture. `verifyRecoveryKey` (non destructif) et `replaceRecoveryKey` | Majeurs |
| D10 | **Tentatives** : `reserveAttempt` (transaction courte) **avant** la dérivation, résolution après ; 0 s jusqu'à 4 échecs, puis `min(900 s, 30 s × 2^(n−5))` ; même chemin de calcul (dérivation factice) pour un profil inconnu ; message uniforme `AUTH_FAILED`. L'horloge est celle du navigateur (limite déclarée) | Majeurs / mineurs |
| D11 | **Session du coffre persistée** (`vault_meta.session`) avec époque et comparaison en transaction (`assertSession`) ; `BroadcastChannel` n'est qu'un accélérateur. Chaque onglet se déverrouille lui-même (aucun partage de clés). Un autre profil qui se déverrouille termine la session du premier | Majeurs |
| D12 | **Verrouillage** : l'intention est immédiate (époque + état « en verrouillage »), les opérations en vol rejettent `VAULT_LOCKED` à leur résolution, des crochets `onBeforeLock` (délai de 2 s) enregistrent les brouillons, puis références aux clés et à l'annuaire supprimées. Promesse honnête : le coffre devient inaccessible à l'application et à l'interface ; **la mémoire physique n'est pas effacée** | Majeurs |
| D13 | **Inactivité** : horodatage d'activité persisté (écriture au plus toutes les 15 s) ; chaque onglet verrouille quand `maintenant − dernière activité ≥ 30 min` (avertissement à 28 min) ; un recul d'horloge verrouille (`CLOCK`) | Majeur |
| D14 | **La coquille métier est démontée** quand le coffre est verrouillé (pas un simple recouvrement) ; seul l'écran de déverrouillage reste ; titre de l'onglet et URL d'objet révoqués ; aucun document ouvert hors de `openDocument` | Majeur |
| D15 | **Déconnexion** : le verrouillage local passe en premier, synchrone, sans réseau ; la déconnexion du serveur suit au mieux (reprise marquée). Une session serveur expirée (401, 419) ou non vérifiable ne verrouille pas | Majeur |
| D16 | **Compartiments fondés sur une fonction totale** des 49 permissions (`ADMIN`, `CLINICAL`, `FINANCIAL`, `MANAGER`, `NONE`), dans un contrat partagé `contracts/compartments.json` (permissions, commandes, visibilités). Un profil sans compartiment est refusé (`PROFILE_WITHOUT_COMPARTMENT`) ; le témoin KCV rend le déverrouillage indépendant des enveloppes | Majeurs |
| D17 | **`exam_fee.collect` ne donne pas `FINANCIAL`** : la clé financière ouvrirait toutes les ventes à l'accueil, contre la ligne « finance seulement caisse/gérante » du fichier 09. Les pièces de frais d'examen relèvent d'`ADMINISTRATIVE` (lisibles par le professionnel qui vérifie le paiement avant la consultation). Leur discrimination (champ `scope` des factures, attestation signée) est **à contractualiser avant B02 et P03** (R-examen). Conséquence documentée : `cash.own_session` donne la clé financière entière | Bloquant / majeurs |
| D18 | **Chaque commande est scellée entière dans un seul compartiment**, fonction pure de `command_name`, détenu par tous les rôles porteurs de sa permission. Registre des 26 commandes dans `contracts/compartments.json`, test d'exhaustivité contre `contracts/commands/index.json` ; le coffre refuse un couple (type, compartiment) incohérent (`COMPARTMENT_MISMATCH`) et un type inconnu (`ENTITY_TYPE_UNREGISTERED`) avant tout appel de chiffrement | Majeurs |
| D19 | **`key_envelopes` générique** : emplacement `ADMINISTRATIVE`, `CLINICAL`, `FINANCIAL`, `MANAGER` ou `DEVICE_SIGNING`, format `raw` ou `pkcs8`. La paire ES256 du poste est générée par O02 extractable, sa clé publique JWK est conservée en clair, la privée est enveloppée pour chaque profil habilité à signer et rechargée non extractable (`['sign']`). Elle n'entre pas dans la récupération (pas de clone actif des droits). Tout profil signataire peut signer au nom d'un autre profil du même poste : limite déclarée | Majeurs |
| D20 | **Durabilité** : `chromeTransactionDurability: 'strict'` ; le rapport de stockage dit si la durabilité est stricte. Firefox et Safari restent relâchés : prescription matérielle (onduleur ou portable sur batterie) au runbook | Bloquant (ops) |
| D21 | **Version de format** `vault_meta.format = {formatVersion, minWriterVersion}` ; un client trop ancien ou une base trop récente refuse d'écrire (`VAULT_FORMAT_TOO_NEW`) ; `versionchange` ferme la base ; registre ordonné des versions de schéma, ajouts seulement ; `vault_formats` déclaré dans `contracts/compatibility.json` | Majeur |
| D22 | **Brouillons** : table `encrypted_drafts` par profil, `putDraft` / `getDraft` / `deleteDraft` / `listDrafts`, crochet `onBeforeLock`. Les écrans s'y branchent (P01, C01…) | Majeur |
| D23 | **Journal de sécurité local** `vault_events` (append-only, sans secret ni donnée patient) pour création, profils, secrets, récupération, retraits, verrouillages par échecs. Il remontera par O05 | Mineur |
| D24 | **Module d'état du stockage** (`storageHealth`) avec port injectable : persistance demandée, quota, aller-retour d'écriture, soupçon de mode privé (heuristique, jamais une preuve), durabilité. O01 le livre, O02 l'appelle à l'enrôlement | Majeur |
| D25 | **Auto-test Web Crypto** au premier démarrage, à la création et à la préparation : `wrapKey` avec AAD doit réussir avec le bon AAD et échouer avec un autre ; sinon `WEBCRYPTO_SELFTEST_FAILED` et coffre refusé | Mineur |
| D26 | **Façade `@/offline/api`** : seule importable depuis `app/` avec les projections (règle ESLint étendue) ; elle n'expose ni table ni `CryptoKey`. Erreurs typées `VaultError` à code stable. `seal` / `open` purs et `assertSession(tx)` fournis à O04 | Majeurs |
| D27 | **Intégration application** : état du coffre (`unknown`, `absent`, `locked`, `unlocked`, `damaged`), route publique de déverrouillage avant la connexion, identité d'action unique (profil du coffre hors ligne, compte du serveur en ligne, divergence signalée), `useMutation` et `ReauthDialog` rattachés à cette identité, issue `vault-locked` du répartiteur | Majeurs |
| D28 | **Preuves navigateur** : bundle de test séparé injecté (jamais dans le build de production, test d'architecture), IndexedDB brut parcouru dans la page, disque du profil Chromium scanné dans quatre encodages avec un témoin de détectabilité, redémarrage complet par contexte persistant, Cache Storage vide d'API | Majeurs |
| D29 | **Tests Vitest en environnement Node** (`// @vitest-environment node`), `fake-indexeddb`, horloge, canal, verrous Web Locks, contexte sécurisé et politique PBKDF2 injectés ; un test réel à 600 000 itérations | Majeurs / mineurs |
| R-rotation | Rotation d'une clé de compartiment : **aucune fiche du backlog ne la porte** (O06 et O07 non plus). Décision de planning à prendre avant G2 | Majeur |
| R-export | Export brut, manifeste, ouverture d'une archive et restauration : O07. O01 impose seulement des contraintes (fonctions de `keys/` pures, `vaultId` et `keyId` conservés, aucune clé en clair) | Majeur |
| R-examen | Attestation de frais d'examen signée (JWS de la clé du poste), champ `scope` des factures, variante d'AT02 : B02, B04, O04, P03 | Bloquant / majeur |
| R-approbations | Compartiment des approbations selon `action_code` (B06) ; O01 classe provisoirement `RequestApproval` et `DecideApproval` en `ADMINISTRATIVE` | Majeur |
| E1 | Partage de `CryptoKey` entre onglets par `postMessage` : écarté (chaque onglet se déverrouille ; surface d'attaque et complexité) | Majeur |
| E2 | Compteur d'activité diffusé et copie dans `localStorage` : écarté ; remplacé par D13 | Majeur |
| E3 | `rawExport` / `openLegacy` / `importFrom` dans O01 : écarté (R-export) | Majeur |
| E4 | Argon2id, zxcvbn et liste de mots tierce : écartés (CSP sans WebAssembly, poids, licence) | Mineurs |
| E5 | Clé d'index HMAC pour masquer les identifiants liés : écartée en L0 ; `keyId` permet de l'ajouter | Mineur |
| E6 | Worker dédié pour l'annuaire : écarté en L0 (chargement par lots avec cession du fil, borne de 20 000 fiches, couverture dans le résultat) | Mineur |

## Modèle de menace

| Menace | Réponse | Limite |
| --- | --- | --- |
| Poste volé ou disque lu hors ligne | Données personnelles et médicales chiffrées au repos (AES-256-GCM) ; clés enveloppées par un secret local dérivé par PBKDF2-HMAC-SHA256 calibré (≥ 600 000) | Un secret faible tombe à une attaque hors ligne ; la limite de tentatives de l'interface ne protège pas contre elle |
| Mauvais profil sur le même poste | Une clé par compartiment ; un profil n'a que les enveloppes de ses compartiments | Un profil qui a connu une clé la garde (voir « Limites ») |
| Écriture dans IndexedDB par un tiers | AAD lié au cabinet, au coffre, au compartiment, à la génération de clé, au type, à l'identifiant et à la version : une ligne déplacée ne se déchiffre pas | Le retour à une ancienne version cohérente de la même ligne n'est pas détecté localement : la chaîne du journal signé (O04, O05) le détecte à la synchronisation |
| Script injecté pendant que le coffre est ouvert | Clés non extractables, CSP stricte (F03), verrouillage à l'inactivité | Une page compromise peut **utiliser** les clés tant que le coffre est ouvert : non extractable empêche leur copie, pas leur emploi |
| Service worker et cache | Aucune réponse de l'API en cache | — |
| Perte du secret d'un profil | Clé de récupération de la gérante, hors du poste | Sans elle ni la gérante, les données du profil sont perdues |

## Compartiments

Quatre compartiments (fichier 23) : `ADMINISTRATIVE`, `CLINICAL`, `FINANCIAL`, `MANAGER`. Un
compartiment protège la **confidentialité** ; le droit d'écrire se vérifie dans le moteur de
commandes (O04) contre le grant. Une entité appartient à un seul compartiment.

### Permissions

`contracts/compartments.json` classe **chacune des 49 permissions** du référentiel
(`database/02_reference_data.sql`) dans une classe fermée ; une permission non classée ou en trop
fait échouer le test. `compartmentsOf(permissions)` en déduit l'ensemble des compartiments.

| Classe | Compartiment gagné | Permissions |
| --- | --- | --- |
| `ADMIN` | `ADMINISTRATIVE` | `patient.read`, `patient.create`, `patient.update`, `patient.merge`, `visit.register`, `agenda.read`, `agenda.write`, `follow_up.write`, `catalog.read`, `stock.read`, `approval.request`, `statistics.activity`, `exam_fee.collect` |
| `CLINICAL` | `CLINICAL` | `clinical.read`, `clinical.write`, `clinical.complete`, `clinical.amend`, `clinical.export`, `statistics.clinical` |
| `FINANCIAL` | `FINANCIAL` | `invoice.read`, `invoice.create`, `invoice.issue`, `payment.collect`, `payment.reverse`, `credit_note.issue`, `refund.execute`, `delivery.confirm`, `cash.own_session`, `cash.manage` |
| `MANAGER` | `MANAGER` | `approval.decide`, `finance.global.read`, `finance.reconcile`, `catalog.manage`, `catalog.purchase_cost.read`, `stock.receive`, `stock.adjust`, `stock.inventory`, `stock.valuation.read`, `users.manage`, `audit.read`, `export.global`, `backup.manage`, `offline.enroll` |
| `NONE` | aucun (traitements du serveur) | `workshop.manage`, `communication.manage`, `conversation.takeover`, `knowledge.manage`, `site.publish`, `integration.manage` |

Résultat avec les rôles du référentiel : accueil → `ADMINISTRATIVE` ; professionnel →
`ADMINISTRATIVE` + `CLINICAL` ; caisse → `ADMINISTRATIVE` + `FINANCIAL` ; gérante → les quatre. Un
compte avec plusieurs rôles ou des dérogations reçoit l'union. `exam_fee.collect` seul donne
`ADMINISTRATIVE` (D17). Un compte sans aucune permission donnant un compartiment est refusé.

### Commandes (D18)

Chaque commande signée est scellée entière dans **un** compartiment, fonction pure de
`command_name` :

| Compartiment | Commandes |
| --- | --- |
| `ADMINISTRATIVE` (7) | `CreatePatient`, `UpdatePatient`, `RegisterVisit`, `CreateAppointment`, `RecordContactAttempt`, `RequestApproval`, `DecideApproval` |
| `CLINICAL` (7) | `StartConsultation`, `SaveClinicalStep`, `CompleteConsultation`, `AmendConsultation`, `CreatePrescription`, `ValidatePrescription`, `CreateFollowUp` |
| `FINANCIAL` (11) | `CreateInvoiceDraft`, `UpdateDraft`, `IssueInvoice`, `RegisterPayment`, `ReversePayment`, `IssueCreditNote`, `RefundCredit`, `OpenCashSession`, `CloseCashSession`, `ConfirmDelivery`, `RegisterPhysicalReturn` |
| `MANAGER` (1) | `RegisterStockMovement` (porte `unit_cost`) |

Tests : exhaustivité contre `contracts/commands/index.json` ; pour chaque commande et chaque rôle du
référentiel porteur de sa permission, le compartiment de la commande appartient aux compartiments du
rôle (`RegisterStockMovement` n'est accordée qu'à qui a `catalog.purchase_cost.read`). Conséquence
pour O05 : une commande scellée dans un compartiment que le profil synchronisant n'a pas bloque, par
l'ordre `device_sequence`, les commandes suivantes jusqu'à l'ouverture du profil porteur ; le tableau
de bord nomme le compartiment en attente (fichier 23).

## Hiérarchie de clés

```text
secret local ──NFKC──PBKDF2-HMAC-SHA256(sel, itérations)──► KEK du profil (AES-GCM 256)
                                                              │ wrapKey AES-GCM + AAD « envelope »
clé de récupération (256 bits, hors poste) ──HKDF-SHA256──► KEK de récupération
                                                              │ wrapKey AES-GCM + AAD « envelope »
                              clé de compartiment (AES-GCM 256, aléatoire, par compartiment et keyId)
                              clé de signature du poste (ECDSA P-256, pkcs8, emplacement DEVICE_SIGNING)
                                                              │ AES-GCM, nonce 96 bits, AAD
                              entités, documents et brouillons du compartiment
```

- **Session** : ne contient que les clés de compartiment **non extractables** (`unwrapKey` avec
  `extractable: false`) et la clé de signature non extractable (`['sign']`). Ni KEK, ni secret, ni
  clé de récupération, ni clé extractable (D1).
- **Portée de ré-enveloppement** : `withExtractableKeys(opération)` déballe en extractable, enveloppe
  pour la cible, puis libère ; aucune référence dans un module, un état réactif ou une fermeture.
- **Création** : clés générées extractables, enveloppées sous la KEK du profil et sous la KEK de
  récupération, vérifiées par `unwrapKey` non extractable, objets extractables abandonnés.
- **Auto-test** (D25) et **bornes** (D5, D6) s'appliquent avant toute dérivation.

## Tables locales (Dexie)

Version 1 du schéma, base `eeo-device`. Les index ne portent que des identifiants opaques et des
classifications ; aucun nom, téléphone ni contenu clinique n'y figure.

| Table | Clé et index | Contenu |
| --- | --- | --- |
| `vault_meta` | `key` | `format`, `identity` (`vaultId`, `clinicId`, `deviceId` ou null, création), `keys` (`keyId` courant par compartiment), `recovery` (vérificateur, `ackedAt`, génération), `session` (autorité), `activity`, `storage`, `logoutPending` |
| `profiles` | `id`, `userId` | Identifiant, utilisateur, login, nom affiché, état, `kdf`, `kekVersion`, `kcv`, tentatives et déblocage, dates. Les permissions et compartiments du profil sont scellés (`profile_access`, `ADMINISTRATIVE`) : jamais une autorisation lue en clair |
| `key_envelopes` | `id`, `[profileId+slot]` | Propriétaire (profil ou `RECOVERY`), emplacement, `keyId`, format, nonce, clé enveloppée, `kekVersion` |
| `encrypted_entities` | `id`, `[compartment+type]` | Compartiment, type, version, `keyId`, nonce, texte chiffré |
| `encrypted_documents` | `id`, `[compartment+kind]`, `entityId` | Compartiment, genre, identifiant opaque de la pièce, version, `keyId`, nonce, texte chiffré |
| `encrypted_drafts` | `id`, `[profileId+compartment]` | Brouillon d'un profil : compartiment, version, `keyId`, nonce, texte chiffré, date |
| `vault_events` | `++seq` | Événement de sécurité : type, instant, profil, détail non sensible |

Les tables du journal, des allocations et des curseurs arrivent avec O03 à O05 en versions suivantes
du schéma (ajouts seulement). Une migration ne détruit jamais la base tant que des pièces attendent.

## Opérations

| Opération | Autorité exigée | Effet |
| --- | --- | --- |
| `prepareVault` puis `commitVault` | Secret choisi par la gérante ; ressaisie de la clé de récupération | Crée le coffre dans une transaction ; refuse si un coffre existe (`VAULT_ALREADY_EXISTS`, verrou Web Locks) |
| `unlock(profil, secret)` | Secret du profil | Session ; tentatives limitées (D10) |
| `lock(raison)` | Aucune | D12 |
| `addProfile(acteur, profil, secretDuTitulaire)` | Secret de l'acteur ressaisi | Le titulaire saisit lui-même son secret sur place (aucun secret initial connu de la gérante) |
| `reissueProfile(acteur, profil, permissions, secretDuTitulaire)` | Secret de l'acteur ressaisi | Change les compartiments d'un profil existant ; si les compartiments diminuent : `rotationRequired` |
| `changeSecret(profil, actuel, nouveau)` | Secret actuel | Nouvelle KEK ; anciennes enveloppes remplacées dans la même transaction après vérification |
| `resetSecretWithRecovery(clé, profil, nouveau)` | Clé de récupération | Ré-enveloppe sous un nouveau secret ; remet les tentatives à zéro |
| `removeProfile(acteur, profil)` | Secret de l'acteur ressaisi | Supprime les enveloppes, garde l'identité (état `REMOVED`) ; refuse la dernière gérante déterminée dans la transaction ; `rotationRequired` |
| `verifyRecoveryKey(saisie)` / `replaceRecoveryKey(acteur)` | — / secret ressaisi | D9 |
| `putEntity` / `getEntity` / `listEntities`, `putDocument` / `getDocument`, `putDraft`… | Session active | Compartiment vérifié avant tout chiffrement ; version monotone |
| `seal` / `open` / `assertSession(tx)` | Session active | Primitives pures pour O04 (écrire des lignes scellées et le journal dans **une** transaction) |

Phase B de toute opération sur les clés : vérifier en mémoire que chaque enveloppe candidate s'ouvre
avec la KEK candidate avant la transaction. Une erreur typée `VaultError` (code stable, aucun secret
dans le message) : `INSECURE_CONTEXT`, `WEBCRYPTO_SELFTEST_FAILED`, `VAULT_ABSENT`,
`VAULT_ALREADY_EXISTS`, `VAULT_DAMAGED`, `VAULT_FORMAT_TOO_NEW`, `VAULT_LOCKED`,
`VAULT_SESSION_STALE`, `AUTH_FAILED`, `AUTH_THROTTLED` (`retryAt`), `KDF_PARAMS_REJECTED`,
`SECRET_TOO_WEAK`, `STEP_UP_FAILED`, `COMPARTMENT_NOT_AVAILABLE`, `COMPARTMENT_MISMATCH`,
`ENTITY_TYPE_UNREGISTERED`, `PROFILE_WITHOUT_COMPARTMENT`, `PROFILE_EXISTS`, `PROFILE_NOT_FOUND`,
`LAST_MANAGER_REQUIRED`, `ROW_DECRYPT_FAILED` (`table`, `id`), `ENVELOPE_CORRUPT` (`profileId`,
`slot`), `RECOVERY_KEY_INVALID`, `VERSION_NOT_NEWER`, `STORAGE_NOT_DURABLE`.

## Session, onglets, inactivité

- **Autorité** : `vault_meta.session = {sessionId, profileId, startedAt, endedAt, endReason,
  lastActivityAt}`. Un déverrouillage du même profil rejoint la session ouverte ; un autre profil
  la termine (`SUPERSEDED`). `assertSession(tx)` relit la ligne dans la transaction d'écriture :
  session terminée ou différente = `VAULT_SESSION_STALE`.
- **Diffusion** : `BroadcastChannel` (`lock`, `profile-removed`, `secret-changed`,
  `compartments-reduced`, `directory-changed`) accélère la propagation ; sans lui, le prochain
  `assertSession`, le retour de focus et la prochaine vérification d'activité suffisent.
- **Inactivité** (D13), **déconnexion** (D15), **verrouillage** (D12, D14).
- **Brouillons** (D22) : les écrans enregistrent un brouillon chiffré au plus toutes les 5 s quand le
  formulaire est modifié ; le crochet `onBeforeLock` (2 s) force un dernier enregistrement ; la
  reprise propose le brouillon après déverrouillage du même profil seulement.

## Intégration à l'application

- `app/services/vault.ts` : `VaultPort` (types), état réactif, `useVault()`, injection depuis la
  racine de composition ; la façade `@/offline/api` fournit l'implémentation.
- **Démarrage hors ligne (AT36)** :

| Coffre | Serveur | Comportement |
| --- | --- | --- |
| absent | quelconque | Flux actuel (connexion au serveur) |
| verrouillé | quelconque | Route publique `/deverrouiller` **avant** `/connexion` ; profils listés (login et nom) |
| déverrouillé | injoignable ou session non vérifiée | Permissions du profil local ; les routes marquées `offlineCapable` restent ouvertes |
| déverrouillé | session ouverte | Permissions du serveur ; divergence de compte signalée |
| endommagé / trop récent | quelconque | Écran d'état explicite, aucune écriture |

- **Identité d'action unique** (`app/actor.ts`) : calculée à partir du coffre et du serveur ;
  `can()`, `useMutation`, `ReauthDialog` et la garde du routeur la lisent. Une commande n'est jamais
  rejouée au nom d'un autre compte.
- **Répartiteur** : issue `vault-locked` (saisie conservée, reprise avec la même clé après
  déverrouillage) ; un coffre verrouillé ne fait jamais retomber une commande de poste sur la route
  directe.
- **Écran de verrouillage** (`LockScreen`) : remplace la coquille (`v-if`), titre de l'onglet
  réinitialisé, focus sur le champ de secret, délai restant annoncé après des échecs. `VaultStatus`
  dans l'en-tête : « Coffre verrouillé » / « Coffre déverrouillé : nom ».

## Stockage et PWA

`storageHealth` (D24) : persistance, quota, aller-retour, soupçon de mode privé, durabilité. Le
service worker ne met en cache que la coquille, les assets et les icônes (F03). Preuve en navigateur
réel (D28) : aucun cache du navigateur ne contient `/api/`, et ni IndexedDB ni le disque du profil ne
contiennent de texte clair.

## Tests

- **Vitest (Node)** : vecteurs d'AAD et d'enveloppes figés ; sélection d'octets (ligne déplacée,
  version rejouée, compartiment ou `keyId` changé) ; hiérarchie de clés (aucune KEK ni clé extractable
  en session, détecté par espionnage de `crypto.subtle`) ; trois phases et absence d'appel crypto dans
  une transaction ; création atomique ; mauvais secret, profil inconnu, tentatives et délai ;
  témoin KCV contre enveloppe corrompue ; mauvais profil devant chaque compartiment (entité,
  document, annuaire, brouillon) ; verrouillage (époque, opérations en vol, chiffré identique octet
  pour octet) ; plusieurs onglets (deux instances, canal factice) ; inactivité (horloge injectée) ;
  changement de secret, récupération (clé saisie en minuscules, avec I/L/O) ; retrait de la dernière
  gérante ; format trop récent ; classement des 49 permissions et des 26 commandes ; clé de signature
  (aller-retour ECDSA) ; recherche en mémoire.
- **Playwright** (`tests/e2e/vault.spec.ts`) : persistance après fermeture complète du navigateur ;
  itérations réelles ≥ 600 000 et temps de déverrouillage ; IndexedDB brut sans texte clair
  (témoin de détectabilité) ; disque du profil sans texte clair ; mauvais profil ; verrouillage ;
  Cache Storage sans API ; contexte sécurisé.
- **Mutations** ciblées sur les décisions D1 à D18 avant la revue.

## Interfaces pour les tâches suivantes

| Tâche | Reçoit d'O01 |
| --- | --- |
| O02 | `createVault` indépendant du serveur puis `bindDevice(deviceId)` ; emplacement `DEVICE_SIGNING` (`attachSigningKey`) ; `storageHealth` ; `compartmentsOf` pour émettre et vérifier `OfflineGrant.compartments` |
| O04 | `seal` / `open` purs, `assertSession(tx)`, `writeSealed(tx, lignes)`, registre de versions de schéma, registre type → compartiment |
| O05 | Un profil ouvert synchronise ce qu'il peut déchiffrer ; compartiment en attente nommé ; événements de sécurité à remonter |
| O06 | `format` / `minWriterVersion`, `versionchange` géré, ajouts seulement |
| O07 | `keys/` pur et sans état de session, `vaultId` et `keyId` stables, enveloppes `RECOVERY`, clé de récupération vérifiable ; l'export, le manifeste et la restauration restent à écrire |

## Limites déclarées

- **Rotation des clés** : retirer un profil ou réduire ses compartiments supprime ses enveloppes
  locales mais **ne révoque pas** une clé qu'il a déjà connue ; `changeSecret` ne révoque rien contre
  qui détient une copie antérieure du disque. `rotationRequired` signale le besoin à la gérante ; la
  rotation n'est pas planifiée (voir « Écarts de planning »).
- **Navigateur compromis** : une page compromise utilise les clés tant que le coffre est ouvert.
- **Mémoire** : `lock()` supprime les références aux clés et à l'annuaire ; l'effacement physique de
  la mémoire n'est pas garanti par JavaScript.
- **Secret faible** : longueur minimale et coût de dérivation retardent une attaque hors ligne, ne
  l'empêchent pas. Argon2id est exclu (CSP sans WebAssembly).
- **Métadonnées en clair** : nombre de lignes par type, dates, noms et logins des profils, tailles des
  textes chiffrés, identifiants opaques. Acceptées en L0.
- **Rejeu** d'une ancienne version entière d'une ligne : non détecté par le chiffrement seul.
- **Écriture** : qui détient une clé de compartiment peut écrire n'importe quelle entité de ce
  compartiment (AES-GCM n'authentifie pas l'auteur) ; la séparation des écritures vient des commandes
  signées et du serveur. La clé de signature du poste est commune à ses profils signataires.
- **Horloge** : délais de tentatives et inactivité s'appuient sur l'horloge du navigateur.
- **Durabilité** : Firefox et Safari ne garantissent pas le vidage disque à chaque transaction.
- **Compartiments plus gros que les permissions** : `cash.own_session` donne toute la clé
  financière ; une dérogation sur une permission `MANAGER` donne toute la clé `MANAGER`. Le
  formulaire de droits (U01) devra l'afficher à l'attribution.

## Écarts de planning et décisions à confirmer

1. **Rotation des clés de compartiment** : absente du backlog. Proposition : sous-tâche d'O07 ou
   tâche dédiée avant G2 (nouvelle clé, `keyId + 1`, rechiffrement par lots reprenables, enveloppes
   re-émises, anciennes supprimées). À décider.
2. **Frais d'examen** : décision D17 à confirmer avec le cabinet, avec le champ `scope` des factures
   (migration additive, contrats `CreateInvoiceDraft` et `UpdateDraft`, règles de refus) avant B02.
3. **`cash.own_session` pour l'accueil** : si l'accueil doit encaisser des espèces, il reçoit la clé
   financière entière. Pour L0, hypothèse : l'accueil n'encaisse pas d'espèces.
4. **Écart de format** : `vaultId` à la place de `device_id` dans l'AAD (D3), à consigner au registre
   des décisions.
