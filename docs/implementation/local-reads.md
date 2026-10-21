# Lectures locales et états de synchronisation (F10)

Tâche F10 du backlog (L0, prérequis F06 et F09 ; regroupements L0-14, L0-15). Sorties attendues :
aucune écriture Dexie directe depuis un écran ; aucun total local et serveur compté deux fois ;
états d'enregistrement et de synchronisation distincts du réseau. Validation : sélecteurs sur des
entités locales et consolidées qui portent les mêmes identifiants (AT29, AT42, AT45 ; EE023, EE042,
EE044).

F10 fixe les interfaces et les règles. Le coffre chiffré (O01), le journal du poste et son commit
local (O04), puis l'envoi, le pull et les ACK (O05) s'y branchent sans les modifier. Avant eux,
aucun poste n'est préparé : rien n'est enregistré localement et aucun état de synchronisation n'est
affiché.

## Fichiers

| Rôle | Fichiers |
| --- | --- |
| Projections du moteur local (sans Dexie) | `resources/frontend/offline/projections/{syncStates,merge,ledger}.ts` |
| Services d'application (seul accès des écrans) | `resources/frontend/app/services/{commandIndex,mutations,useMutation,reads,sync,projections}.ts` |
| Session et client HTTP | `resources/frontend/app/{session,api,network}.ts` |
| Composants | `resources/frontend/components/SyncStatus.vue`, `components/feedback/{SyncBadge,FreshnessNote}.vue`, `components/session/ReauthDialog.vue` |
| Identifiants du poste | `resources/frontend/domain/values/ulid.ts` |
| Règle d'architecture | `eslint.config.js` (blocs écrans et composants, services d'application, règles pures et modèles) |
| Tests | `tests/frontend/offline/projections.test.ts`, `tests/frontend/services/{mutations,reads,direct-body-contract}.test.ts`, `tests/frontend/components/sync.test.ts`, `tests/frontend/domain/ulid.test.ts`, `tests/frontend/architecture.test.ts`, `tests/frontend/support/contracts.ts` |

## Couches et accès

```text
écran / composant ──► app/services (lecture, mutation, état de synchronisation)
                          │
                          ├──► API du serveur (routes directes du contrat F06)
                          └──► offline/projections (fusion, solde)  ──►  offline/… (coffre, journal : O01, O04)
```

La règle ESLint est éprouvée par `tests/frontend/architecture.test.ts` sur du code d'exemple, puis
lancée sur le code réel. Elle couvre les imports statiques (alias, chemin relatif, sous-chemin de
Dexie), les imports dynamiques et l'accès global à `indexedDB` :

| Dossier | Autorisé | Interdit |
| --- | --- | --- |
| `pages/`, `components/` | `app/services` | Dexie, tout `offline/`, `indexedDB` |
| `app/` | `offline/projections/…` | Dexie, le point d'entrée `offline`, les tables et le journal (`offline/db`, `offline/journal`, `offline/vault/…`), `indexedDB` |
| `domain/`, `documents/` | — | Dexie, tout `offline/`, `indexedDB` |
| `offline/` | Dexie, `indexedDB` | — |

## États d'un enregistrement du poste

`offline/projections/syncStates.ts`, aligné sur le contrat `contracts/sync/ack.json` :

| État | Sens | Libellé |
| --- | --- | --- |
| `local` | Commit local durable, pas encore envoyé | Enregistré sur ce poste |
| `pending` | Envoyé, sans ACK final | En attente de synchronisation |
| `waiting_dependency` | ACK `WAITING_DEPENDENCY` : pièce liée attendue, reprise dans l'ordre | En attente d'une pièce liée |
| `conflict` | ACK `CONFLICT_REQUIRES_REVIEW` : originaux conservés, décision attendue | Conflit à revoir |
| `acknowledged` | ACK `ACCEPTED` ou `ALREADY_ACCEPTED` enregistré localement | Consolidé sur le serveur |
| `rejected` | ACK `REJECTED` après revue : jamais un fait accepté | Rejeté après revue |

Seuls `acknowledged` et `rejected` sont finaux : une commande ne quitte la file active qu'après eux.
Sont « non confirmés » exactement `local`, `pending` et `waiting_dependency`. L'état du PDF local
est distinct : `pending` (« PDF en préparation »), `ready` (« PDF prêt »), `failed` (« PDF à
régénérer »). Il ne se confond pas avec le statut central du document.

## Fusion par identifiant : deux règles selon la nature de la donnée

Les entités du poste et du serveur portent les mêmes identifiants (ULID connus avant tout commit
local). La fusion se fait par identifiant, jamais en additionnant un total du serveur et un total
local. Un identifiant en double, d'un côté ou de l'autre, est refusé plutôt que compté deux fois.

**Faits en ajout seul** (`mergeFacts` : paiements, contre-écritures, avoirs, remboursements,
mouvements de stock). Un fait ne change jamais : présent dans l'instantané du serveur, il est
consolidé et la copie locale n'est plus comptée (son état local reste lisible).

**Entités modifiables** (`mergeVersioned` : identité patient, consultation, rendez-vous). La version
du serveur ne masque pas une proposition du poste (AT42 : aucun écrasement) :

| Proposition du poste | Valeur affichée | État affiché |
| --- | --- | --- |
| `local`, `pending`, `waiting_dependency`, `conflict` | La proposition du poste ; la version du serveur reste disponible (`serverValue`) pour comparaison | L'état local, jamais « consolidé » |
| `acknowledged`, version supérieure à celle de l'instantané | La proposition (acceptée après l'instantané) | `acknowledged` |
| `acknowledged`, version comprise dans l'instantané | La version du serveur | Consolidé |
| `rejected` | La version du serveur ; la proposition reste lisible (`localValue`) | Consolidé |
| Absente de l'instantané (fiche créée sur le poste) | La valeur du poste | L'état local |

La fraîcheur dit d'où viennent les données : `serverAsOf` est l'instant de l'instantané, ou null
pour des données de ce poste seulement ; `complete` est faux pour une liste distante tronquée,
partielle ou absente.

## Solde d'une facture vu par le poste

`projectLedger` (`ledger.ts`) fusionne les faits financiers par `mergeFacts`, puis les calcule par
la règle F08 `LedgerBalance`, jumelle du serveur et de `vw_invoice_balances`.

| Fait | Compté dans le solde | Affiché |
| --- | --- | --- |
| Consolidé par le serveur, ou accepté après l'instantané | Oui | Consolidé |
| Enregistré sur ce poste, envoyé, ou en attente d'une pièce liée | Oui : encaissement réel, numéro définitif | Montant « non confirmé » par type |
| En conflit | Non | « En revue » |
| Dépendant d'un fait en revue, rejeté ou inconnu (`parentId` : contre-écriture d'un paiement, remboursement d'un avoir), en chaîne | Non : compté seul, il fausserait le reste dû | « En revue », avec la raison (`parent-in-review`, `parent-rejected`, `parent-unknown`) |
| Rejeté après revue | Non | À part, pour la trace |

- `collectionBlocked` est vrai tant qu'un fait est en revue : le montant contesté a peut-être déjà
  été encaissé. `collectable` vaut alors 0 et `assertCollectable` refuse tout encaissement
  (`payment.review_pending`) jusqu'à la décision de la gérante. Sans fait en revue,
  `assertCollectable` applique le plafond du reste dû (règle F08).
- `settled` (soldée) n'est vrai que si rien n'est dû, **tout est confirmé par le serveur**, aucun
  fait n'est en revue et les données distantes sont complètes. Un encaissement seulement du poste
  n'est jamais dit « soldé ».
- `confirmedLedgerVersion` vaut 1 plus le nombre de faits confirmés : c'est la version que le
  serveur connaît. `balance.ledgerVersion` compte aussi les faits du poste.

## Écritures : répartiteur de mutations

`app/services/mutations.ts` reçoit une commande du contrat F06 (nom, paramètres de route, charge
avec les identifiants de ses effets, version attendue, clé d'idempotence).

- **Poste préparé** : si `LocalCommandEngine.handles(requête)` accepte la commande, elle est
  enregistrée par un commit local atomique, même en ligne (le poste est l'autorité temporaire de ses
  écritures financières ; la synchronisation suit). Le poste décide d'après la requête (propriété de
  la pièce, droits préparés), pas d'après le nom de la commande : une facture centrale que le poste
  ne possède pas part par la route directe. Un échec du journal est l'issue `local-failed` (rien
  d'écrit, rien envoyé au serveur).
- **Route directe**, lue dans `contracts/commands/index.json` (même source que le serveur). Le corps
  est conforme au `DirectRequest` de la commande : la charge, `expected_version` toujours présent
  (nombre pour une commande versionnée, `null` pour une commande sans version ou de nature « état »),
  et le champ de numéro à `null` (le serveur numérote : `record_number`, `number`,
  `receipt_number`). Le journal du poste reçoit au contraire la charge avec son numéro. Tous les
  corps sont validés contre leur `DirectRequest` par `direct-body-contract.test.ts`. En-tête
  `Idempotency-Key`. Une requête sans réponse au-delà de 30 s est abandonnée ; une réponse coupée en
  cours de route compte comme une absence de réponse.

| Réponse | Issue | Effet pour l'écran |
| --- | --- | --- |
| 2xx | `synced` | « Synchronisé avec le serveur » |
| Commit local | `saved-local` | « Enregistré sur ce poste à 10h42 » |
| Échec du journal local | `local-failed` | « Échec de l'enregistrement sur ce poste » ; saisie conservée |
| 409 `VERSION_CONFLICT`, `IDEMPOTENCY_PAYLOAD_MISMATCH` | `conflict` | Saisie conservée, version actuelle à vérifier ; aucune nouvelle tentative automatique |
| 419 (jeton CSRF) | Renouvelé une fois, même clé ; si ça persiste : `session-expired` | — |
| 401 | `session-expired` | Saisie conservée ; `ReauthDialog`, puis reprise avec la même clé |
| 423 `REAUTHENTICATION_REQUIRED` | `reauthentication-required` | Confirmer le mot de passe, puis reprise |
| 429 `RATE_LIMITED`, `SERVER_BUSY` (de l'application) | `retryable` avec `Retry-After` | « Rien n'a été appliqué », délai annoncé |
| 5xx, page HTML d'un hébergeur, d'un pare-feu ou d'un portail | `uncertain` | « La commande a pu être appliquée » : reprise avec la même clé |
| 409 métier, 422, 403, 404… | `refused` | Message et valeurs actualisées (`error.details`, par exemple le reste dû) |
| Aucune réponse complète, sans journal local | `unavailable` | « L'enregistrement n'est pas confirmé » |
| Compte différent de celui qui a commencé la saisie | `account-changed` | Rien n'est envoyé |

### `useMutation` : une instance par formulaire

`useMutation` ne modifie jamais le formulaire de l'écran. La clé d'idempotence et les identifiants
d'effets (`effectId('payment_id')`) sont liés au formulaire :

- une reprise garde la même clé, **y compris après un succès** : renvoyer la même saisie est un
  rejeu, qui rend le résultat déjà enregistré, jamais une seconde commande ;
- une charge ou une version attendue différente est une nouvelle intention, avec une nouvelle clé
  (sinon `IDEMPOTENCY_PAYLOAD_MISMATCH` à chaque reprise) ;
- `reset()` (nouveau formulaire) renouvelle la clé et les identifiants d'effets ;
- le même envoi pendant qu'un envoi est en cours rend la même promesse (double clic) ; une autre
  saisie est refusée par `MutationBusyError` ;
- après `session-expired` ou `reauthentication-required`, la session est relue avant tout renvoi :
  toujours déconnecté → rien n'est envoyé ; serveur muet → `unavailable` ; **compte différent de celui
  qui a commencé la saisie** (autre onglet) → `account-changed`, rien n'est envoyé au nom d'un autre
  profil ;
- la clé vit en mémoire : un rechargement de la page la perd (les identifiants d'effets de la charge
  empêchent alors le doublon côté serveur ; le journal du poste, O04, conserve la commande).

`saveState` alimente `SaveStatus` (F09) et ne dit « enregistré » qu'après un commit réussi.

### Reconnexion sur place

La session garde le dernier compte connu (`session.lastAccount`) quand elle expire.
`ReauthDialog` ne reconnecte que ce compte, compare l'identifiant obtenu à celui de la saisie et
ferme la session obtenue si elle diffère. La coquille relit la session au retour sur l'onglet, sans
navigation (une saisie en cours reste à l'écran) et affiche « Session expirée : reconnexion à
l'enregistrement ».

## Lectures : interface commune

`useRead(chargeur)` (`app/services/reads.ts`) traduit l'issue d'une lecture en états d'écran du
fichier 04 :

- `ready`, `empty` ;
- `offline` : serveur injoignable, en erreur (5xx) ou page d'hébergeur, **ou résultat vide sans
  données complètes du serveur** — jamais « aucun dossier » (fichier 04) ;
- `session-expired`, `forbidden`, `error` ;
- un défaut inattendu du chargeur donne `error` et `failure` (jamais un écran bloqué en chargement
  ni une promesse rejetée).

La fraîcheur accompagne la donnée. Une relecture qui échoue garde la donnée déjà affichée, et la
réponse ou l'échec d'une lecture plus ancienne qu'une relecture récente est ignoré.

## Affichage

- **`SyncStatus`** (en-tête, à côté de l'état réseau, dont il est distinct ; visible aussi session
  expirée, car les pièces du poste attendent toujours) affiche les pièces en attente, celles
  d'autres profils (envoyées à l'ouverture de leur profil), les conflits à revoir, les PDF à
  régénérer ou à déposer, le dernier échange réussi et l'échec du dernier essai. « Synchronisé » n'est
  dit que si rien de tout cela n'est en attente et qu'un échange a réussi. Sans poste préparé, il
  n'affiche rien. Le bouton « Synchroniser » a un état en cours et annonce l'échec.
- **`SyncBadge`** montre l'état d'une pièce et de son PDF : « Enregistré sur ce poste », «
  Consolidé sur le serveur », « Conflit à revoir », etc.
- **`FreshnessNote`** donne l'heure du serveur à l'heure du cabinet (« Données du serveur au
  15/10/2026 à 10h42. »), signale des totaux distants incomplets (AT45) ou des données de ce poste
  seulement.

## Hors de F10

- Coffre chiffré et compartiments (O01), enrôlement et grants (O02), plages et quotas (O03).
- Journal du poste et `LocalCommandEngine` réel (O04), envoi, pull et ACK (O05), PDF (D01).
- Écrans qui emploieront ces services : P01–P03, B02–B08, C01–C04.
- AT42 (propositions conservées sur deux postes) et AT45 (mise à jour avec pièces pendantes) se
  recettent quand le journal et la synchronisation existent. F10 en fournit les états, la fusion et
  l'affichage.

## Revue contradictoire (2026-10-15) et traitement

Revue indépendante et adverse du commit `bf4e165`, en lecture seule, avec sondes et mutations sur
une copie : **pass=false**, 6 majeurs, de nombreux mineurs et 6 mutations sur 6 qui survivaient aux
tests. Elle a aussi relevé ce qui tient : ULID conforme (aucun biais de modulo, monotone, débordement
refusé), correspondance ACK → état, aucun double comptage d'un même identifiant, chemins `/api/v1`
et encodage corrects, journal local prioritaire même en ligne, même clé conservée après 401 ou
erreur 5xx. Tous les constats sont traités ci-dessous, chacun avec son test.

| Constat | Gravité | Traitement | Test |
| --- | --- | --- | --- |
| La fusion affichait « Consolidé » et la version du serveur masquait une modification du poste en attente ou en conflit (AT42) | Majeur | `mergeFacts` (faits en ajout seul) et `mergeVersioned` (entités modifiables) : une proposition non finalisée reste affichée avec son état, la version du serveur reste disponible | `projections.test.ts` (entités modifiables, 12 cas) |
| Corps de la route directe non conforme à `DirectRequest` pour 16 commandes sur 26 | Majeur | `directBody` : `expected_version` toujours présent (`null` pour « aucune » et « état »), champ de numéro à `null` ; `expectedVersion` accepte `null` ; l'index des commandes expose le champ de numéro | `direct-body-contract.test.ts` : les 26 corps validés contre leur `DirectRequest` |
| Contre-écriture ou remboursement dépendant d'un fait en litige compté seul : reste dû faux | Majeur | `LedgerFact.parentId` ; un fait dont l'origine est en revue, rejetée ou inconnue passe lui-même en revue, en chaîne | `projections.test.ts` (3 scénarios) |
| Un paiement en litige laissait encaisser de nouveau | Majeur | `collectionBlocked`, `collectable` et `assertCollectable` (`payment.review_pending`) | `projections.test.ts` |
| `useRead` annonçait « aucun résultat » sans avoir consulté le serveur | Majeur | Vide « certain » seulement avec `serverAsOf` et données complètes, sinon `offline` | `reads.test.ts` |
| `useMutation` renouvelait la clé après un succès (second clic = seconde commande) | Majeur | Clé et identifiants d'effets liés au formulaire (`effectId`), renouvelés par `reset()` ; une charge ou une version différente est une nouvelle intention | `mutations.test.ts` (6 cas) |
| 423, 429 (`Retry-After`), 504 et pages HTML classés en « refusé » ou « non traité » | Mineur | Issues `reauthentication-required`, `retryable` (délai lu), `uncertain` ; `ApiError.fromApplication` et `retryAfterSeconds` | `mutations.test.ts` (15 réponses) |
| Requête sans délai (écran bloqué en « enregistrement »), corps coupé, erreurs hors `ApiError` | Mineur | Délai de 30 s ; une réponse coupée est une absence de réponse ; erreur du journal local = `local-failed` | `mutations.test.ts` |
| Jeton CSRF périmé (419) pris pour une session expirée | Mineur | Renouvelé une fois avec la même clé | `mutations.test.ts` |
| Message « renvoyées sans doublon » trop affirmatif | Mineur | Dit que la commande a pu être appliquée et que la reprise garde la clé tant que l'écran reste ouvert | `mutations.test.ts` |
| Autre saisie envoyée pendant un envoi : ignorée sans le dire ; `reset()` pendant l'envoi | Mineur | `MutationBusyError` | `mutations.test.ts` |
| `useRead` bloqué en chargement sur une erreur de programmation, 5xx classé « erreur » | Mineur | État `error` et `failure` ; 5xx et pages d'hébergeur → `offline` | `reads.test.ts` |
| `settled` vrai sur la foi du poste seul | Mineur | Exige tous les faits confirmés par le serveur | `projections.test.ts` |
| `SyncStatus` : « Synchronisé » malgré un échec, pièces d'autres profils et PDF à déposer absents, bouton sans état | Mineur | Champs `otherProfiles` et `documentsToUpload`, état en cours et erreur du bouton ; visible aussi session expirée | `sync.test.ts` |
| Règle ESLint contournable (import dynamique, sous-chemin de Dexie, `@/offline`, `indexedDB`, `offline/db*` seulement, `domain/` et `documents/` libres) | Mineur | Quatre blocs (écrans, services, règles et modèles, `offline/`) ; test d'architecture sur 40 cas puis sur le code réel | `architecture.test.ts` |
| `handles(nom de commande)` : aucun repli pour une facture centrale | Mineur | `handles(requête)` : le poste décide d'après la propriété de la pièce | `mutations.test.ts` |
| Reconnexion sans comparaison de compte ; commande renvoyée au nom d'un autre onglet | Mineur | `session.lastAccount`, comparaison de l'identifiant, garde de compte dans `useMutation`, session relue avant tout renvoi et au retour sur l'onglet | `sync.test.ts`, `mutations.test.ts` |
| Affirmations inexactes de ce document | Mineur | Corrigées | — |

Les 6 mutations qui survivaient (« soldé » sans condition de revue, état « pièce liée attendue »
retiré, version hors de l'empreinte, « Synchronisé » affiché malgré ce qui attend, échec d'une
lecture obsolète non ignoré, motif ESLint relatif retiré) et 7 autres (version du serveur qui masque
le poste, litige sans blocage d'encaissement, numéro non remis à null, garde de compte retirée, 429
classé en refus, vide certain sans données complètes, identifiant non comparé à la reconnexion)
font maintenant échouer au moins un test.
