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
| Composants | `resources/frontend/components/SyncStatus.vue`, `components/feedback/{SyncBadge,FreshnessNote}.vue`, `components/session/ReauthDialog.vue` |
| Identifiants du poste | `resources/frontend/domain/values/ulid.ts` |
| Règle d'architecture | `eslint.config.js` (blocs « écrans et composants » et « services d'application ») |
| Tests | `tests/frontend/offline/projections.test.ts`, `tests/frontend/services/{mutations,reads}.test.ts`, `tests/frontend/components/sync.test.ts`, `tests/frontend/domain/ulid.test.ts`, `tests/frontend/architecture.test.ts` |

## Couches et accès

```text
écran / composant ──► app/services (lecture, mutation, état de synchronisation)
                          │
                          ├──► API du serveur (routes directes du contrat F06)
                          └──► offline/projections (fusion, solde)  ──►  offline/… (coffre, journal : O01, O04)
```

- Un écran ou un composant n'importe ni `dexie` ni rien de `resources/frontend/offline`. Il passe
  par `app/services` (règle ESLint, vérifiée par `tests/frontend/architecture.test.ts` sur du code
  d'exemple et sur le code réel).
- Les services d'application lisent les projections du moteur local, jamais ses tables
  (`offline/db*` et `dexie` interdits dans `app/`).
- Seul `resources/frontend/offline` accède à IndexedDB.

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
L'état du PDF local est distinct : `pending` (« PDF en préparation »), `ready` (« PDF prêt »),
`failed` (« PDF à régénérer »). Il ne se confond pas avec le statut central du document.

## Lecture fusionnée par identifiant

`mergeById(instantané du serveur, enregistrements du poste)` (`merge.ts`) :

- un identifiant présent dans l'instantané du serveur est consolidé : la version du serveur fait foi,
  et la copie locale n'est plus comptée (son état reste lisible pour l'affichage) ;
- un enregistrement du poste absent de l'instantané est repris avec son état local ;
- un identifiant en double, d'un côté ou de l'autre, est refusé plutôt que compté deux fois ;
- la fraîcheur dit d'où viennent les données. `serverAsOf` donne l'instant de l'instantané, ou null
  pour des données de ce poste seulement. `complete` est faux pour une liste distante tronquée ou
  absente.

### Solde d'une facture vu par le poste

`projectLedger` (`ledger.ts`) fusionne les faits financiers (paiements, contre-écritures, avoirs,
remboursements) par identifiant. Elle les calcule ensuite par la règle F08 `LedgerBalance`, jumelle
du serveur et de `vw_invoice_balances`. Ce n'est jamais un total du serveur ajouté à un total
local.

| Fait | Compté dans le solde | Affiché |
| --- | --- | --- |
| Consolidé par le serveur, ou accepté après l'instantané | Oui | Consolidé |
| Enregistré sur ce poste, envoyé, ou en attente d'une pièce liée | Oui : encaissement réel, numéro définitif | Montant « non confirmé » par type |
| En conflit | Non | À part, « en litige » : ne pas réencaisser sans décision de la gérante |
| Rejeté après revue | Non | À part, pour la trace |

`settled` (soldée) n'est vrai que si rien n'est dû, sans fait en litige, et avec des données
distantes complètes. Une facture n'est jamais dite soldée sur la seule foi du poste ou d'un
instantané incomplet.

## Écritures : répartiteur de mutations

`app/services/mutations.ts` reçoit une commande du contrat F06 (nom, paramètres de route, charge
avec les identifiants de ses effets, version attendue, clé d'idempotence) :

- **Poste préparé** dont le journal enregistre la commande (interface `LocalCommandEngine`, O04) :
  commit local atomique, même en ligne. Le poste est l'autorité temporaire de ses écritures
  financières ; la synchronisation suit.
- **Sinon** : route directe lue dans `contracts/commands/index.json`, la même source que le serveur.
  Le corps est la charge plus `expected_version` quand la commande en a une, avec l'en-tête
  `Idempotency-Key`.

| Réponse | Issue | Effet pour l'écran |
| --- | --- | --- |
| 2xx | `synced` | « Synchronisé avec le serveur » |
| Commit local | `saved-local` | « Enregistré sur ce poste à 10h42 » |
| 409 `VERSION_CONFLICT`, `IDEMPOTENCY_PAYLOAD_MISMATCH` | `conflict` | Saisie conservée, version actuelle à vérifier ; aucune nouvelle tentative automatique |
| 401, 419 | `session-expired` | Saisie conservée ; `ReauthDialog` reconnecte le **même** compte, puis reprise avec la même clé |
| 409 métier, 422, 403… | `refused` | Message et valeurs actualisées (`error.details`, par exemple le reste dû) |
| 5xx, `SERVER_BUSY`, page HTML de l'hébergeur | `retryable` | Saisie conservée, reprise avec la même clé |
| Aucune réponse, sans journal local | `unavailable` | « L'enregistrement n'est pas confirmé » : la commande a pu être appliquée, rien n'est conclu |

`useMutation` ne modifie jamais le formulaire de l'écran. Il garde la même clé d'idempotence pour
toutes les reprises d'une même intention (même commande, même charge). Une charge modifiée est une
nouvelle intention ; un enregistrement réussi clôt l'intention. Un double clic n'envoie qu'une fois.
`saveState` alimente `SaveStatus` (F09) et ne dit « enregistré » qu'après un commit réussi.

Les clés et les identifiants d'effets sont des ULID générés sur le poste (`domain/values/ulid.ts`,
motif du contrat `common.json#/$defs/Ulid`), strictement croissants dans une même milliseconde.

## Lectures : interface commune

`useRead(chargeur)` (`app/services/reads.ts`) traduit l'issue d'une lecture en états d'écran du
fichier 04 :

- `ready`, `empty` ;
- `offline` : serveur injoignable, jamais « aucun dossier » ;
- `session-expired`, `forbidden`, `error`.

La fraîcheur accompagne la donnée. Une relecture qui échoue garde la donnée déjà affichée, et une
réponse plus ancienne qu'une relecture récente est ignorée.

## Affichage

- **`SyncStatus`** (en-tête, à côté de l'état réseau, dont il est distinct) affiche les pièces en
  attente, les conflits à revoir, les PDF à régénérer, le dernier échange et l'échec du dernier
  essai. « Synchronisé » n'est dit que si rien n'attend et qu'un échange a réussi. Sans poste
  préparé, il n'affiche rien. Le bouton « Synchroniser » apparaît si le poste le permet.
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
