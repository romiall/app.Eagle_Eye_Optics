# Chemin central de commandes (F07)

F07 construit le chemin que **toute mutation critique** emprunte, quelle que soit son origine : route directe d'un compte connecté, commande rejouée depuis un poste (synchronisation) ou tâche planifiée. Une seule transaction MySQL porte, dans cet ordre : les droits, les verrous, l'idempotence, les faits de l'action, l'audit, l'outbox et le journal des changements. Contrat : fichier 23, « Chemin transactionnel central » ; fiche 24, F07 ; scénarios AT11, AT12, AT13, AT19, AT38 ; exigences EE017, EE024, EE045.

Ce document décrit ce qui est réellement implémenté et prouvé. Les actions métier (paiement, facture, consultation…) arrivent avec leurs tâches (P01, B02–B08, C01–C04…) et se branchent sur ce chemin sans le modifier.

## Flux d'une commande

```text
Controller / sync / job
  └─ CommandBus::dispatch(Command, CommandContext, clé d'idempotence)
       1. commande connue du registre ; clé d'idempotence valide (obligatoire si la commande l'exige)
       2. charge validée contre le schéma de contracts/ (Opis, JSON Schema 2020-12)      → 422 VALIDATION_FAILED
       3. pré-contrôle des droits, sans verrou (refus rapide, source admise)              → 403 ACTION_FORBIDDEN
       ── TransactionRunner : UNE transaction, rejouée en entier sur interblocage ──
       4. verrou exclusif du cabinet                                         (rang 10)
       5. appareil, si commande de poste                                     (rang 20)
       6. ligne d'idempotence                                                (rang 30)
            • déjà COMMITTED, même empreinte  → résultat durable rendu, AUCUN effet rejoué
            • déjà COMMITTED, autre empreinte → 409 IDEMPOTENCY_PAYLOAD_MISMATCH
       7. droits RELUS en base sous le verrou (compte actif, permission effective) ou contexte historique du grant
       8. réservation de la clé (statut RECEIVED, dans la même transaction)
       9. action : autres verrous dans l'ordre du contrat 23, recalcul, écriture des faits
      10. audit → outbox → journal des changements → résultat idempotent COMMITTED
       ── COMMIT ──
      11. réponse (jamais avant le commit)
```

Un refus métier (`BusinessError`) ou toute exception **annule tout**, y compris la réservation de la clé : il n'est jamais COMMITTED, et la même intention peut être renvoyée quand l'état a changé. Une intention modifiée prend une nouvelle clé ; les tentatives de transport gardent l'ancienne.

## Ordre unique des verrous (L0)

`LockRank` fixe l'ordre, `TransactionLocks` le fait respecter : demander un verrou de rang inférieur à un verrou déjà tenu, ou des verrous d'un même rang par clés décroissantes, lève `LockOrderViolation` **avant toute requête**. Redemander un verrou déjà tenu est permis. Les identifiants d'un même rang sont triés par la classe, pas par l'appelant.

| Rang | Verrou | Table et clé |
| --- | --- | --- |
| 10 | cabinet | `clinics.id` (exclusif ; partagé pour les lecteurs du pull) |
| 20 | appareil | `enrolled_devices` |
| 30 | idempotence | `idempotency_requests` (cabinet, espace de noms, commande, clé) |
| 40 | séquences de numéros | `document_sequences` par (type, année) croissants |
| 50 | propriété de facture | `invoice_write_owners` par facture |
| 60 | facture | `invoices` |
| 70 | session de caisse | `cash_sessions` |
| 80 / 81 / 82 | positions, lots, quotas de stock | `stock_positions`, `stock_lots`, `device_stock_allocations`, identifiants triés |
| 90 | approbation | `approvals` |

Deux transactions qui respectent cet ordre ne peuvent pas s'interbloquer. Toutes les lectures sont filtrées par `clinic_id`.

## Idempotence

| Élément | Règle |
| --- | --- |
| Clé | En-tête `Idempotency-Key` (8 à 128 caractères parmi lettres, chiffres, `.`, `_`, `:`, `-`) ; pour un poste, l'identifiant de la commande |
| Espace de noms | `user:<compte>` pour la route directe, `device:<poste>` pour la synchronisation, `job:<tâche>` ; une clé n'est jamais partagée entre comptes |
| Empreinte | SHA-256 du JSON canonique de (nom, agrégat, version attendue, charge) : indépendante de l'ordre des clés, sensible à toute différence de valeur, `null` différent de `0` |
| Rejeu (réponse perdue) | Même clé, même empreinte : le résultat stocké est rendu, l'action n'est pas rejouée (AT11, AT38). MySQL normalise l'ordre des clés du JSON stocké : même contenu, ordre éventuellement différent |
| Conflit | Même clé, autre empreinte : `409 IDEMPOTENCY_PAYLOAD_MISMATCH`, effet d'origine intact (AT11) |
| Droits au rejeu | Route directe : droits actuels relus (un compte désactivé depuis ne relit rien). Poste : pas de réévaluation, son autorisation historique a été jugée au commit ; une révocation ultérieure ne fait pas perdre son ACK |
| Sans clé | Admis pour les opérations qui ne l'exigent pas (comptes, cabinet) : aucune ligne d'idempotence |

## Autorisation

| Source | Moment | Règle |
| --- | --- | --- |
| DIRECT | avant tout verrou | permission effective du compte (refus rapide) |
| DIRECT | sous le verrou du cabinet | compte relu en base : actif, permission effective. Une désactivation survenue pendant l'attente du verrou est prise en compte |
| SYNC | sous le verrou | **contexte historique** du grant (`offline_grants`) évalué à l'instant déclaré par le poste, voir ci-dessous |
| JOB | — | identité de service ; la commande doit admettre la source JOB. L'audit enregistre « Système (<tâche>) » |

Contexte historique d'une commande de poste (`HistoricalAuthorization`, fonction pure, tolérance `eeo.offline.max_clock_skew_seconds` = 300 s) :

1. grant d'un autre appareil ou d'un autre auteur, ou permission absente du `permissions_snapshot` → **refus** (`GRANT_EXPIRED_OR_REVOKED`, 403) ;
2. instant du poste antérieur à la dernière heure serveur qu'il connaissait (antidatage), postérieur à l'heure du serveur, ou antérieur à l'émission du grant → **revue** (`AUTHORIZATION_REVIEW_REQUIRED`, 409) ;
3. instant postérieur à la validité du grant → refus ;
4. grant révoqué : poste qui avait resynchronisé après la révocation → refus ; action antérieure à la révocation → **accordée** (une révocation d'aujourd'hui ne détruit pas une pièce d'hier) ; action autour de la révocation sans que le poste ait pu la connaître → revue.

Ces règles d'horloge posent la structure ; **O02** (préparation du poste, « horloge suspecte ») les confirmera.

## Effets écrits au commit

| Effet | Table | Règle |
| --- | --- | --- |
| Audit | `audit_events` (append-only, triggers) | Auteur et corrélation du **contexte**, jamais de la charge. Aucun secret dans le diff. Décision sur la chaîne `previous_hash` : **non tenue** (voir « Décisions ») |
| Événement | `outbox_events` (PENDING) | Seuls les 13 événements du contrat F06 ; charge validée contre `contracts/events/<type>.v<version>.json` (charge minimale, aucune donnée clinique). Un événement hors contrat est un défaut de programmation |
| Changement à diffuser | `sync_change_log` (append-only) | Écrit **uniquement** sous le verrou exclusif du cabinet (`LogicException` sinon) |
| Résultat idempotent | `idempotency_requests` (COMMITTED) | Objet JSON de la réponse |

Aucun effet externe n'a lieu dans la transaction : un test d'architecture interdit à la couche `app/Application` tout appel HTTP, courrier, notification, fichier, file ou événement. Les envois passent par l'outbox, lue par un worker **après** le commit.

## Publication du pull ordonnée sous le verrou du cabinet

`sync_change_log.change_sequence` est un AUTO_INCREMENT attribué à l'**insertion**, non au commit. Toute mutation centrale tient le verrou exclusif de la ligne du cabinet de son premier verrou jusqu'au commit, et n'écrit ses changements que dans cette fenêtre : pour un cabinet, l'ordre des numéros est l'ordre des commits.

`ChangeFeed::page()` lit une page sous le verrou **partagé** de la même ligne : il attend la fin de toute mutation en cours, puis voit tous les changements committés du cabinet. Un numéro manquant ne peut être qu'un trou d'AUTO_INCREMENT (transaction annulée), jamais un changement à venir ; un MAX global observé n'est jamais utilisé. La page porte une **borne supérieure** : le numéro du dernier changement balayé, retenu ou non par le filtre de compartiments, pour que le curseur avance aussi sur des entrées masquées. Le curseur opaque signé et la validation côté client après commit local de la page sont ceux de la tâche **O05**.

## Interblocage et attente de verrou

| Cas | Comportement |
| --- | --- |
| Interblocage (1213) | Transaction **rejouée en entier** (verrous, lectures, écritures), au plus 3 tentatives, attente croissante et décalée de 10 à 40 ms puis 20 à 80 ms |
| Tentatives épuisées | `503 SERVER_BUSY` + `Retry-After: 2`, aucune modification ; le client renvoie la même demande avec la même clé |
| Attente de verrou dépassée (1205) | Pas de relance interne (trois attentes de plusieurs secondes dépasseraient le délai de la requête) : `503 SERVER_BUSY` |
| Délai de verrou | `innodb_lock_wait_timeout` de la session fixé à `eeo.transactions.lock_wait_timeout_seconds` (10 s par défaut, variable `EEO_LOCK_WAIT_TIMEOUT`) |
| Transaction imbriquée | Refusée hors des tests (`LogicException`) : une relance sur interblocage ne rejouerait que le point de sauvegarde |

## Ajouter une commande (liste de contrôle)

1. Le schéma de la charge existe dans `contracts/commands/<Commande>.v1.json` (F06), avec sa permission et sa route directe.
2. Une action `CommandHandler` : `permissions()` (celles du contrat) et `handle()` qui prend ses verrous par `TransactionLocks` **dans l'ordre**, recalcule, écrit ses faits et rend un `CommandOutcome` (réponse, audit, événements, changements). Aucune transaction, aucun appel externe.
3. Un `CommandDefinition` (nom, action, sources admises, schéma, `idempotencyRequired = true`), déclaré par le module dans le registre (voir `AccessCommands`). `CentralPathGuardTest` vérifie qu'il correspond au contrat F06.
4. Le contrôleur n'a que le rôle de la ligne suivante : `$this->dispatchCommand($request, new Command(...))` (trait `DispatchesCommands`).
5. Tests : calcul et refus, idempotence (rejeu, contenu différent), retour arrière à chaque point (`Checkpoint`), concurrence réelle entre deux connexions, fermeture et reprise pour une commande disponible hors-ligne (fiche 23).

## Mutations de comptes et du cabinet (F05) sur le chemin central

`CreateStaffMember`, `UpdateStaffMember`, `SetStaffPermissionOverride`, `ResetStaffPassword` et `UpdateClinicSettings` conservent leur signature (contrôleurs, scénarios de concurrence et tests de F05 inchangés) mais ne sont plus que des **façades** : validation de la saisie et hachage du mot de passe hors transaction, puis `CommandBus::dispatch`. Leurs règles vivent dans `app/Domain/Access/Handlers/` et s'exécutent sous le verrou du cabinet. Ce sont des opérations en ligne seulement : pas de schéma dans `contracts/commands/`, pas de clé d'idempotence imposée (la version attendue et l'unicité de `login` et `email` en tiennent lieu ; le contrat F06 les déclare `x-idempotency: none`).

La connexion, le changement de son propre mot de passe, la mise en service, la procédure de secours et les comptes de démonstration gardent leur logique (verrou partagé du cabinet, audit d'échec sans auteur, saisie console) mais ouvrent leur transaction par `TransactionRunner`, comme tout le reste.

## Consultation de l'audit

`GET /api/v1/audit-events` (permission `audit.read`, gérante seulement) : lecture seule, limitée au cabinet, du plus récent au plus ancien, 50 événements par page, filtres `target_type`, `target_id`, `from`, `to` (instants UTC à la milliseconde), `page`. Réponse conforme à `AuditEventPage` (F06).

## Décisions et limites

| Sujet | Décision |
| --- | --- |
| Chaîne de hash de l'audit | **Non tenue.** Elle exigerait de sérialiser toute écriture d'audit sous un verrou exclusif, or des événements s'écrivent hors du chemin central (échecs de connexion, sous verrou partagé) : une chaîne incomplète donnerait de fausses alertes, et le fichier 09 rappelle qu'elle ne protège pas d'un administrateur qui possède toutes les clés. Protection retenue : `event_hash` par ligne, triggers UPDATE et DELETE, export quotidien hors serveur (X02) |
| Sérialisation | Le verrou du cabinet sérialise **toutes** les mutations centrales d'un cabinet (choix simple du contrat 23). Débit non mesuré à ce stade : à mesurer avec les cibles du fichier 05 avant toute optimisation, sans retirer les invariants |
| Règles d'horloge des postes | Posées ici, à confirmer en O02 |
| Réponse rejouée | Rendue depuis une colonne JSON MySQL : contenu identique, ordre des clés éventuellement normalisé |
| Curseur du pull | Borne supérieure et ordre de publication prouvés ; curseur opaque signé = O05 |
| Environnement | Preuve sur MySQL 8.4.9 ; à rejouer sur la version de l'hébergeur (Q01) |

## Preuves

| Test | Prouve |
| --- | --- |
| `tests/Unit/Application/HistoricalAuthorizationTest.php` | Contexte historique : 13 cas (accordé, refusé, revue, tolérance, révocation postérieure) |
| `tests/Unit/Application/TransactionRunnerTest.php` | 3 tentatives, attentes bornées, `SERVER_BUSY`, aucune relance d'un refus métier |
| `tests/Unit/Application/CommandHashTest.php` | Empreinte stable, sensible aux valeurs, `null` ≠ 0 |
| `tests/Feature/Application/TransactionLocksTest.php` | Ordre des 9 rangs, refus avant toute requête, filtre par cabinet |
| `tests/Feature/Application/CommandBusTest.php` | Effets écrits ensemble, rejeu, conflit, refus sans effet, retour arrière **à chaque point**, droits, sources, poste, version attendue (AT19), audit non modifiable (AT13) |
| `tests/Feature/Application/CommandHttpTest.php` | AT11 et AT38 au niveau de l'API : en-tête, rejeu identique, 409, 422, 401, 403, 503 + `Retry-After` |
| `tests/Feature/Application/ChangeFeedTest.php` | Pages bornées, borne qui avance sur les entrées filtrées, isolation des cabinets |
| `tests/Feature/Application/AuditEventsRouteTest.php` | Route d'audit conforme au contrat, droits, filtres, pagination |
| `tests/Feature/Application/CentralPathGuardTest.php` | Aucune transaction hors de l'exécuteur, aucun effet externe, commandes enregistrées conformes au contrat F06 |
| `tests/Concurrency/CommandBusConcurrencyTest.php` | Entre **vraies connexions MySQL** : AT12 (deux règlements de 40 000 sur 50 000), double clic, conflit de contenu, publication du pull qui attend la mutation en cours, ordre des numéros = ordre des commits, interblocage réel rejoué, refus sans attendre le verrou, droit retiré pendant l'attente, retour arrière réel, processus tué avant le commit |

Lancement : voir [testing.md](testing.md) (poste Windows : moteur MySQL local sans Docker, réutilisation du schéma).
