# Contrats exécutables et versions (F06)

F06 matérialise les contrats des fichiers 08, 19 et 23 : OpenAPI 3.1, JSON Schema 2020-12, protocole de synchronisation, événements, snapshots des pièces, compatibilité et types TypeScript. Les fixtures valent contrat exécutable : PHP (Opis) et TypeScript (Ajv, mode strict) rendent le même verdict sur les mêmes fichiers.

Ce que F06 ne fait pas :
- F06 n'implémente aucune route métier. `x-implementation-status` indique pour chaque opération si elle est réellement implémentée (`IMPLEMENTED`, aujourd'hui les routes F05) ou seulement fixée (`PLANNED`, avec la tâche du backlog qui l'implémentera).
- Les vecteurs de calcul (F08) et les vecteurs de signature JCS/JWS (O02) ne sont pas fournis : les répertoires `contracts/vectors/` arrivent avec ces tâches.

## Contenu de `contracts/`

| Chemin | Rôle | Source |
| --- | --- | --- |
| `schemas/common.json` | Types exacts : ULID, XAF entiers bornés, quantités et mesures en chaînes, compteurs en chaînes, instants UTC à la milliseconde, dates civiles | Écrit à la main |
| `schemas/enums.json` | 106 énumérations `<table>.<colonne>` reprises du catalogue | `tools/build_enums.py` |
| `schemas/error.json` | Enveloppe d'erreur du fichier 08 et codes stables | Écrit à la main ; test `ErrorCodeCatalogTest` |
| `schemas/clinical-steps.json` | Rubriques du dossier (modèle DP), partagées par les commandes, les réponses et le snapshot clinique | `tools/build_commands.py` |
| `sync/` | Cœur signé JCS/JWS, commande signée, push, ACK, page pull | Écrit à la main |
| `commands/` | 26 charges du journal local, leur route directe (`$defs/DirectRequest`), `index.json` et `dispatch.json` | `tools/build_commands.py` |
| `events/` | Enveloppe des événements internes et 13 charges minimales | `tools/build_events.py` |
| `schemas/resources/`, `schemas/requests/` | Réponses de l'API et corps des routes en ligne sans commande | `tools/build_resources.py` |
| `snapshots/` | Contenus figés des pièces (colonnes JSON `snapshot` du catalogue) | `tools/build_resources.py` |
| `openapi.yaml` | 86 opérations de l'API privée et des routes publiques L2 | `tools/build_openapi.py` |
| `compatibility.json` | Ce que la release sait reprendre | Écrit à la main ; schéma `schemas/compatibility.json` |
| `vocabulary.json` | Annotations `x-*` admises | Écrit à la main |
| `fixtures/` | 150 fichiers acceptés ou refusés | Écrits à la main (données fictives) |

Les générateurs sont déterministes et ont un mode `--check` exécuté en CI. Un fichier généré ne se modifie jamais à la main : on modifie le générateur puis on le relance. Les fichiers générés sont exclus de Prettier, car le contrôle compare les octets.

```bash
python3 contracts/tools/build_enums.py
python3 contracts/tools/build_commands.py
python3 contracts/tools/build_events.py
python3 contracts/tools/build_resources.py
python3 contracts/tools/build_openapi.py
npm run contracts:types          # resources/frontend/domain/contracts/generated.ts
```

## Conventions de valeur (contrat 23)

| Valeur | Contrat |
| --- | --- |
| Identifiant | ULID Crockford majuscule, jamais l'identifiant nul. Les identifiants de **tous les effets** (lignes, reçus, documents, usages de numéro, réserves, révisions, mouvements) sont fournis par le poste avant le commit local et repris tels quels par le serveur. |
| Montant | Entier XAF borné comme le SQL (0 à 10¹²), jamais un nombre décimal |
| Quantité, mesure, taux | Chaîne décimale exacte (point décimal) ; null = non mesuré, jamais zéro |
| Compteur BIGINT | Chaîne de chiffres sans zéro non significatif (`device_sequence`, curseurs, bornes de plage) |
| Instant | `AAAA-MM-JJTHH:MM:SS.mmmZ` (UTC, milliseconde) |
| Date civile | `AAAA-MM-JJ`, fuseau du cabinet, jamais déduite d'un minuit UTC |
| Clés d'une charge synchronisée | Toutes présentes, une valeur absente vaut null : le hash JCS est identique en PHP et dans le navigateur |

## Commandes du journal local

Chaque commande produit `commands/<Commande>.v1.json`, qui contient :
- la charge synchronisée (objet strict) ;
- `$defs/DirectRequest`, qui est la même charge plus `expected_version`, pour la route directe (en-tête `Idempotency-Key` obligatoire).

Par la route directe, le numéro de pièce est attribué par le serveur hors des plages réservées : le numéro rendu vaut null dans la requête, alors que l'identifiant de l'usage reste fourni par le client.

`dispatch.json`, référencé par le cœur signé, impose la cohérence de `command_name` avec la charge, `schema_version`, `entity_type`, la nature de `expected_version` et la plage de numéros (`number_allocation_id` obligatoire si un numéro est consommé, null sinon). L'égalité entre `core.entity_id` et le champ indiqué ci-dessous est contrôlée par le serveur, JSON Schema ne comparant pas deux champs.

| Commande | Entité (champ `entity_id`) | Permission | `expected_version` | Route directe (`/api/v1`) | Numéro |
| --- | --- | --- | --- | --- | --- |
| `CreatePatient` | `patient_records` (`patient_id`) | `patient.create` | création (null) | `POST /patients` | `PATIENT` |
| `UpdatePatient` | `patient_records` (`patient_id`) | `patient.update` | version de l'agrégat | `PATCH /patients/{id}` | — |
| `RegisterVisit` | `visits` (`visit_id`) | `visit.register` | création (null) | `POST /visits` | — |
| `StartConsultation` | `consultations` (`consultation_id`) | `clinical.write` | création (null) | `POST /visits/{id}/consultations` | — |
| `SaveClinicalStep` | `consultations` (`consultation_id`) | `clinical.write` | version de l'agrégat | `PATCH /consultations/{id}/steps/{step}` | — |
| `CompleteConsultation` | `consultations` (`consultation_id`) | `clinical.complete` | version de l'agrégat | `POST /consultations/{id}/complete` | — |
| `AmendConsultation` | `consultations` (`consultation_id`) | `clinical.amend` | version de l'agrégat | `POST /consultations/{id}/amendments` | — |
| `CreatePrescription` | `optical_prescriptions` (`prescription_id`) | `clinical.write` | création (null) | `POST /consultations/{id}/prescriptions` (aussi `/prescriptions/external` et `/prescriptions/{id}/revisions`) | — |
| `ValidatePrescription` | `optical_prescriptions` (`prescription_id`) | `clinical.complete` | transition d'état (null) | `POST /prescriptions/{id}/validate` | — |
| `CreateInvoiceDraft` | `invoices` (`invoice_id`) | `invoice.create` | création (null) | `POST /invoices` | — |
| `UpdateDraft` | `invoices` (`invoice_id`) | `invoice.create` | version brouillon | `PATCH /invoices/{id}` | — |
| `IssueInvoice` | `invoices` (`invoice_id`) | `invoice.issue` | version brouillon | `POST /invoices/{id}/issue` | `INVOICE` |
| `RegisterPayment` | `invoices` (`invoice_id`) | `payment.collect` | ledger_version | `POST /invoices/{id}/payments` | `RECEIPT` |
| `ReversePayment` | `invoices` (`invoice_id`) | `payment.reverse` | ledger_version | `POST /payments/{id}/reversals` | — |
| `IssueCreditNote` | `invoices` (`invoice_id`) | `credit_note.issue` | ledger_version | `POST /invoices/{id}/credit-notes` | `CREDIT_NOTE` |
| `RefundCredit` | `invoices` (`invoice_id`) | `refund.execute` | ledger_version | `POST /invoices/{id}/refunds` | `REFUND` |
| `OpenCashSession` | `cash_sessions` (`cash_session_id`) | `cash.own_session` | création (null) | `POST /cash-sessions` | — |
| `CloseCashSession` | `cash_sessions` (`cash_session_id`) | `cash.own_session` | transition d'état (null) | `POST /cash-sessions/{id}/close` | — |
| `ConfirmDelivery` | `deliveries` (`delivery_id`) | `delivery.confirm` | création (null) | `POST /invoices/{id}/deliveries` | — |
| `RegisterPhysicalReturn` | `physical_returns` (`physical_return_id`) | `stock.adjust` | création (null) | `POST /deliveries/{id}/physical-returns` | — |
| `RegisterStockMovement` | `stock_movements` (`movement_id`) | `stock.adjust` | création (null) | `POST /stock/movements` | — |
| `CreateFollowUp` | `follow_up_plans` (`follow_up_plan_id`) | `follow_up.write` | création (null) | `POST /follow-ups` | — |
| `CreateAppointment` | `appointments` (`appointment_id`) | `agenda.write` | création (null) | `POST /appointments` | — |
| `RecordContactAttempt` | `contact_tasks` (`task_id`) | `agenda.write` | version de l'agrégat | `POST /contact-tasks/{id}/attempts` | — |
| `RequestApproval` | `approvals` (`approval_id`) | `approval.request` | création (null) | `POST /approvals` | — |
| `DecideApproval` | `approvals` (`approval_id`) | `approval.decide` | transition d'état (null) | `POST /approvals/{id}/decision` | — |

« Version brouillon » = `invoices.version`, figée à l'émission. « ledger_version » = projection 1 + paiements confirmés + contre-écritures + avoirs émis + remboursements (contrat 23), jamais une colonne. « Transition d'état » : la table n'a pas de colonne `version` (`optical_prescriptions`, `cash_sessions`, `approvals`), le serveur vérifie sous verrou le statut attendu (brouillon, session ouverte, demande en attente). Le test `CommandCatalogConsistencyTest` interdit une version attendue sans colonne réelle.

## Protocole de synchronisation (`sync/`)

- **Cœur** : `event_hash` = SHA-256 hexadécimal minuscule de JCS(core) (RFC 8785).
  - `schema_version` est une chaîne et `last_server_time` est obligatoire, comme les colonnes de `sync_commands`, pour reconstituer le cœur à l'identique.
  - `previous_event_hash` vaut null pour la première commande.
- **Commande signée** : `core`, `event_hash`, `signature` (JWS compact ES256 ; en-tête protégé `alg=ES256`, `typ=eeo-command+jws`, `kid` de la clé enrôlée ; charge signée `command_id`, `device_id`, `event_hash`).
- **Push** : 1 à 50 commandes ordonnées. **ACK** par commande :
  - `ACCEPTED` ;
  - `ALREADY_ACCEPTED` (transport) ;
  - `WAITING_DEPENDENCY` (dépendances listées) ;
  - `CONFLICT_REQUIRES_REVIEW` (identifiant du conflit) ;
  - `REJECTED` (code et motif).

  Le préfixe finalisé (`finalized_prefix`) et l'heure serveur figurent dans la réponse.
- **Pull** : au plus 100 changements, curseur opaque signé, `has_more`. Le client n'avance le curseur qu'après commit local de toute la page.

## Événements internes (`events/`)

Les 13 événements de la version 1 du fichier 08, dans l'enveloppe `event_id`, `event_type`, `event_version`, `clinic_id`, `occurred_at`, `aggregate_type`, `aggregate_id`, `aggregate_version`, `correlation_id`, `payload`, qui correspond à une ligne `outbox_events`.

La charge est minimale : identifiants et attributs administratifs nécessaires, jamais de donnée clinique ni de coordonnées patient. Des fixtures refusées le prouvent : champ de réfraction ajouté, téléphone ajouté. Un consommateur autorisé relit la source par l'API, avec ses droits.

## Ressources, requêtes et snapshots

**Factures et pièces financières**
- `draft_version` et `ledger_version` sont nommées distinctement (fichier 08). Un brouillon n'a ni numéro ni `ledger_version`, une facture émise les a (règle du CHECK de `invoices`).
- Il n'existe aucun statut `PAID` : `balance.settlement` est dérivé du solde projeté.
- La réponse d'encaissement donne les références du paiement et du reçu, le solde recalculé et l'état du PDF. Un PDF `PENDING` n'autorise jamais à répéter l'encaissement.

**Lectures filtrées par profil**
- L'historique patient ne contient les consultations qu'avec `clinical.read`, et les pièces qu'avec `invoice.read`.
- Le coût d'achat du catalogue n'est présent que pour la gérante (annotation `x-read-scope`).

**Snapshots**
- Les snapshots de facture (patient, cabinet, fiscal), de reçu, d'avoir, de remboursement, de remise et de révision clinique sont décrits selon le fichier 11.
- Le snapshot fiscal vaut `NO_TAX_CONFIGURED` avec une liste vide tant qu'aucune règle validée n'existe : aucune taxe n'est inventée.
- Le snapshot du cabinet porte `test_document`, vrai tant que `production_documents_enabled` est faux.
- La nature d'un reçu (acompte ou règlement du solde) est calculée, jamais saisie.

**Routes en ligne sans commande hors-ligne** (corps dans `schemas/requests/http.json`) :
- session et comptes (F05) ;
- modification d'une prescription brouillon ;
- déplacement et annulation de rendez-vous ;
- catalogue et règles fiscales ;
- stock initial ;
- enrôlement, préparation et libération des postes ;
- résolution de conflit ;
- exports ;
- réservation publique.

## OpenAPI 3.1

86 opérations, regroupées en 14 thèmes. Chaque opération porte les annotations du vocabulaire : `x-permission` (l'un des codes suffit), `x-reauthentication`, `x-idempotency`, `x-offline-command`, `x-read-scope`, `x-implementation-status` et `x-backlog-task`.

Les erreurs renvoient à l'enveloppe du fichier 08 (401, 403, 404, 409, 410, 413, 419, 422, 423, 429, 503). La sécurité est décrite par le cookie de session Sanctum, plus `X-XSRF-TOKEN` (ou Fetch Metadata) pour les écritures.

Routes ajoutées au-delà des tableaux 08, 19 et 23, parce qu'une tâche L0 en a besoin et qu'aucune ne doit rester implicite :

| Route | Tâche | Raison |
| --- | --- | --- |
| `POST /catalog/items`, `PATCH /catalog/items/{id}`, `GET`/`POST /tax-rules`, `PATCH /tax-rules/{id}` | B01 | « Créer catalogue et paramètres prix/taxes » ; règles fiscales saisies et validées par la gérante, aucune taxe préconfigurée |
| `POST /imports/catalog`, `POST /imports/legacy-balances` | X01 | Imports contrôlés (aperçu puis import) du catalogue et des créances papier |
| `GET /reports/clinical` | R02 | Statistiques cliniques agrégées (`statistics.clinical`) |
| `GET /audit-events` | F07 | Consultation de l'audit (`audit.read`, fichier 09) ; rattachée au chemin central qui écrit l'audit |

## Décisions prises en F06

| Décision | Motif |
| --- | --- |
| Pas de commande `ReserveStock` ni de route de réservation | I00 : la réserve est un service transactionnel interne d'`IssueInvoice` et de `ConfirmDelivery` |
| `RegisterStockMovement` limité à `BREAKAGE` et `ADJUSTMENT`, approbation obligatoire, casse négative, quantité non nulle | CHECK de `stock_movements` ; le stock initial passe par `POST /stock/opening` (I00), les sorties de vente par `ConfirmDelivery`, les retours par `RegisterPhysicalReturn` |
| Numéro rendu null dans une requête directe | Émission centrale : numéro serveur hors plages réservées (fichier 08) |
| Nature `state` pour `expected_version` | Tables sans colonne `version` : transition d'état contrôlée sous verrou, aucune colonne inventée (contrat 23) |
| `schema_version` en chaîne, `last_server_time` obligatoire | Colonnes de `sync_commands` ; reconstitution du cœur à l'identique pour recalculer le hash |
| Prescription brouillon : `PATCH` en ligne avec `expected_updated_at` | `optical_prescriptions` n'a pas de colonne version ; hors-ligne, une correction est une nouvelle version (`CreatePrescription` avec `supersedes_id`). À confirmer avec C04. |
| `Invoice.balance.settlement` dérivé | Fichier 08 : solde et ledger retournés, pas de statut payé stocké |
| Codes d'erreur complétés | Codes F05 (`SESSION_REQUIRED`, `REAUTHENTICATION_REQUIRED`, `LAST_MANAGER_REQUIRED`, `MANAGER_RESET_REQUIRES_CONSOLE`, `SELF_*`…) et du protocole (`NUMBER_RANGE_EXHAUSTED`, `GRANT_EXPIRED_OR_REVOKED`, `WRITE_OWNER_REQUIRED`) |

## Compatibilité (`compatibility.json`)

Le fichier déclare :
- la release (`info.version` de l'OpenAPI) ;
- le protocole de synchronisation 1 ;
- le catalogue canonique (empreinte du backlog) ;
- les versions de règles acceptées ;
- la version de schéma de chaque commande et de chaque événement.

Les formats de coffre (O01) et les modèles de documents (D01/D02) sont vides tant que ces tâches ne sont pas livrées, car aucun coffre ni document local n'existe à reprendre. Une version n'est retirée que lorsqu'aucun poste ne détient de pièce pendante dans cette version (O06).

## Types TypeScript

`node contracts/tools/generate-types.mjs` (`npm run contracts:types`) produit `resources/frontend/domain/contracts/generated.ts` avec `json-schema-to-typescript`. Le fichier exporte un type par charge, requête directe, ressource, requête, snapshot, événement et définition commune. Les règles conditionnelles (if/then, not) ne s'expriment pas en TypeScript et restent vérifiées à l'exécution par Ajv. Le test `tests/frontend/contract-types.test.ts` vérifie l'usage des types sur les fixtures, avec TypeScript strict.

## Preuves et tests

| Test | Contrôle |
| --- | --- |
| `tests/Contracts/ContractFixturesTest.php` (Opis) et `tests/frontend/contracts.test.ts` (Ajv strict) | Chaque schéma déclare `$schema` 2020-12 et un `$id` qui reflète son chemin ; 150 fixtures, mêmes verdicts dans les deux runtimes. Une variante refusée est une fixture acceptée plus un patch JSON (RFC 6902), donc un défaut unique par construction. |
| `tests/frontend/openapi.test.ts` | Document valide selon le schéma officiel OAS 3.1, avec contrôles négatifs ; toutes les références résolues ; chaque commande associée à sa route directe ; permissions seedées ; tâches du backlog ; `Idempotency-Key` sur chaque mutation idempotente |
| `tests/Contracts/OpenApiRoutesTest.php` | Routes Laravel `/api` identiques aux opérations `IMPLEMENTED` ; permission et réauthentification identiques au middleware |
| `tests/Feature/Contracts/ImplementedRoutesContractTest.php` | Réponses réelles des routes F05 (session, comptes, cabinet, erreurs 401/403/404/409/422/423) validées contre leurs schémas, sur MySQL |
| `tests/Contracts/CommandCatalogConsistencyTest.php` | Entités, versions attendues et permissions des commandes conformes au catalogue |
| `tests/Contracts/CompatibilityDeclarationTest.php` | Déclaration valide et cohérente avec commandes, événements, OpenAPI et backlog |
| `tests/Contracts/ErrorCodeCatalogTest.php` | Tout code d'erreur émis par l'application est déclaré |
| `tests/frontend/contract-types.test.ts` | Types générés utilisables sur les fixtures (TypeScript strict) |

En CI, le job PHP exécute la suite `Contracts` et les cinq `--check` Python ; le job frontend exécute le `--check` des types et Vitest. `tools/dev/check-all.sh` fait les mêmes contrôles en local.
