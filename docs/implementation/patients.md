# Patients (P01 création et modification, P02 recherche) — serveur

Premier jalon des fonctions de base, **en ligne** : la fiche patient passe par le chemin central de
commandes (F07). Le hors-ligne (O01 à O05) est branché ensuite sur les mêmes services ; rien ici n'est
annoncé comme utilisable sans Internet, et la création depuis un poste hors-ligne est refusée
explicitement tant que les plages de numéros (O03) n'existent pas.

## Routes (module `patients`, ouvert par l'exploitant)

| Méthode et route | Permission | Rôle |
| --- | --- | --- |
| `GET /api/v1/patients?query=&page=` | `patient.read` | Recherche (P02) : `PatientPage`, 25 par page |
| `POST /api/v1/patients` | `patient.create` et `patient.read` | Création (commande `CreatePatient`, `Idempotency-Key` obligatoire) ; la réponse est la fiche, d'où la lecture |
| `GET /api/v1/patients/{id}` | `patient.read` | Fiche complète (`PatientResponse`) |
| `PATCH /api/v1/patients/{id}` | `patient.update` et `patient.read` | Modification (commande `UpdatePatient`, version attendue) |

Module fermé : chaque route répond 404 `RESOURCE_NOT_FOUND`, avant toute vérification de droit
(`docs/implementation/modules.md`). Rôles du référentiel : la gérante et l'accueil créent et modifient ;
le professionnel et la caisse lisent seulement (la « correction proposée » de la matrice du fichier 09
n'a ni table ni commande : écart documenté ci-dessous).

## Création

- Corps de la route directe : la charge de la commande, `expected_version: null` et `record_number: null`
  (le serveur numérote). `CommandDefinition::$directSchema` fait valider ce corps par le bus contre
  `commands/CreatePatient.v1.json#/$defs/DirectRequest` (la charge d'un poste reste validée par `schema`).
- Numéro `EE-AAAA-NNNN` : `DocumentNumbers::allocateCentral` prend le numéro dans `document_sequences`
  sous le verrou exclusif du cabinet puis celui de la séquence, l'inscrit dans `document_number_usages`
  (append-only) et écrit la fiche dans la **même transaction** : un échec à n'importe quel point du chemin
  annule tout, numéro compris (aucun trou). L'année est celle du fuseau du cabinet (le 31 décembre à
  23 h 30 UTC est déjà l'année suivante à Douala). Au-delà de quatre chiffres, aucune troncature
  (`EE-2026-10000`). Les autres séries n'ont pas de format : `render()` refuse ce qui n'est pas validé.
- Identité (`PatientIdentity`) : nom obligatoire ; naissance connue et non future (au jour civil du
  cabinet) ou absence expliquée, jamais de date inventée ; téléphone principal ou absence expliquée ;
  sexe `F`, `M` ou `UNKNOWN`. Une raison d'absence n'est conservée que si la valeur est absente. Les
  colonnes `name_search` et `*_normalized` sont toujours recalculées par le serveur. Toutes les erreurs
  sont rendues ensemble (422, une entrée par champ). L'API n'accepte que `AAAA-MM-JJ` pour les dates
  (la saisie `JJ/MM/AAAA` relève de l'écran, qui la convertit).
- Aucun caractère de contrôle dans les champs d'une ligne (l'adresse admet les retours à la ligne) ; une
  clé de recherche qui dépasse 320 caractères après décomposition (syllabes hangul, par exemple) est un 422,
  jamais une erreur SQL.
- **Contact du responsable** d'un mineur (extension facultative) : un objet `name` (180), `relationship`
  (80), `phone` (32), rien d'autre ; l'audit ne recopie pas son contenu (« modifié » seulement).
- **Champs d'import historique** (création) : première visite connue ni future ni antérieure à la
  naissance, « certifiée » exige une date, un ancien numéro exige `legacy_record`.
- Un homonyme ou un téléphone partagé ne bloque jamais l'enregistrement. Le signalement des
  ressemblances (sans fusion) est fait par l'écran avant l'enregistrement, à partir de la recherche
  (aucune route de « contrôle de doublon » n'existe au contrat).
- Un identifiant de patient ou d'usage de numéro déjà pris (nouvelle clé d'idempotence, même identifiant)
  est refusé en 422, jamais en erreur SQL.
- Effets : audit `patient.created`, événement `PatientCreated`, journal des changements
  (`ADMINISTRATIVE`, `CREATED`), résultat idempotent (références seulement).

## Modification

- Verrou « dossier patient » (rang 45 de `LockRank`, entre les séquences et la propriété de facture),
  version lue sous verrou comparée à `expected_version` : 409 `VERSION_CONFLICT` avec
  `details.current_version`.
- L'état **résultant** (fiche + changements) est revalidé en entier : retirer une date de naissance sans
  expliquer son absence donne un 422 (`changes.birth_date_missing_reason`), jamais une erreur SQL.
- Le numéro de dossier et les champs d'import historique ne se modifient pas. Un changement sans effet
  n'incrémente pas la version. L'audit `patient.updated` garde l'avant et l'après de chaque champ modifié
  (c'est l'historique de l'identité).

## Recherche (P02)

Deux caractères au moins et cent au plus (422 sinon, y compris texte mal encodé ou page hors de 1 à
100 000) ; 120 recherches par minute et par compte (429). Numéro de dossier (`EE-AAAA` ou `EE-AAAA-NNNN`,
préfixe ; `AAAA-NNNN`, fin du numéro) ; chiffres (téléphones normalisés, comparés dans les deux sens sans
déduire d'indicatif : un numéro saisi avec `+237` ou `00237` retrouve un numéro enregistré sans indicatif de
7 chiffres au moins, et inversement ; fin du numéro de dossier) ;
sinon nom et prénoms (accents, casse et ponctuation ignorés, ordre des mots indifférent, tous les mots
doivent se retrouver). Les jokers de `LIKE` sont échappés. Toujours limitée au cabinet ; les fiches
fusionnées ne sont pas proposées ; tri stable (`name_search`, numéro, identifiant). La liste ne porte que
l'identité de base. **Non mesuré** : la recherche par nom est un balayage du cabinet (`LIKE '%mot%'`) ; la
cible du fichier 05 (moins d'une seconde sur 20 000 patients) reste à mesurer sur le serveur de recette.

## Tests

`tests/Feature/Patients/PatientCommandsTest.php` (création, numérotation, année du fuseau, idempotence,
retour arrière à chacun des 7 points du chemin, version, état résultant, droits, module fermé, isolation
des cabinets), `PatientSearchTest.php`, `tests/Unit/Patients/PatientIdentityTest.php` (règles pures et
format du numéro), `tests/Concurrency/CommandBusConcurrencyTest.php` (cinq créations simultanées dans
cinq processus : cinq numéros consécutifs distincts ; double clic : une seule fiche),
`TransactionLocksTest` (rang 45).

## Visites et file du jour (P03, premier jalon : sans contrôle de paiement)

| Méthode et route | Permission | Rôle |
| --- | --- | --- |
| `POST /api/v1/visits` | `visit.register` | Arrivée d'un patient (commande `RegisterVisit`, `Idempotency-Key` obligatoire) |
| `GET /api/v1/visits?date=&page=` | l'une OU l'autre de `visit.register`, `agenda.read` (middleware `any-permission`) | File du jour : 50 par page, arrivée croissante |

Module `today`. Aucune donnée clinique : la visite porte l'identité de base du patient (`patient`) et
le marqueur `exam_fee_control`.

- **Statut décidé par le serveur** : `ARRIVED`. `AWAITING_EXAM_FEE` et `READY_FOR_EXAM` ne sont **jamais
  écrits** tant que le contrôle du paiement de l'examen n'existe pas (B04, B06) : « prêt pour examen »
  dirait que la règle est satisfaite, ce qui serait faux. Un test le verrouille.
- **Marqueur `exam_fee_control`** : `NOT_CONTROLLED` pour un examen ou un suivi, `NOT_APPLICABLE` pour un
  achat ou un retrait. L'écran écrit « Paiement de l'examen non contrôlé », jamais « payé », « soldé » ni
  « gratuit ». À dire à la cliente : l'examen n'est pas encore bloqué faute de règlement.
- **Contrôle actif** (`EEO_EXAM_FEE_CONTROL=billed`, P03 avec B04 et B06, décision de l'exploitant) : les deux
  points ci-dessus valent pour la valeur par défaut `off`. En `billed`, une visite d'examen ou de suivi arrive
  `AWAITING_EXAM_FEE`, passe `READY_FOR_EXAM` à l'encaissement de sa facture de frais ou à la dispense accordée,
  et le marqueur vaut `UNPAID`, `WAIVER_REQUESTED`, `PAID` (avec le numéro du reçu) ou `WAIVED` : voir
  [billing.md](billing.md), « Contrôle du paiement de l'examen dans la file du jour (P03) ».
- **Un achat ou un retrait ne crée jamais de consultation.** Une autre visite du même patient le même
  jour ne bloque pas.
- **Jour civil du cabinet** : `DayWindow` convertit minuit local en UTC (à Douala, le 22 octobre local
  commence le 21 à 23 h UTC), jamais « minuit UTC ». Sans `date`, c'est aujourd'hui au fuseau du cabinet.
- Contrôles : patient du cabinet, non archivé, non fusionné ; identifiant de visite libre (422 sinon) ;
  rendez-vous du cabinet, du même patient, ni annulé ni manqué ; heure d'arrivée pas dans le futur au-delà de
  la tolérance d'horloge (`eeo.offline.max_clock_skew_seconds`). Une arrivée passée est acceptée
  (saisie rétrospective : fenêtre à confirmer).
- Source de poste hors-ligne refusée (403) jusqu'à O03/O05.
- Rangs de verrou 46 « visite » et 47 « consultation » ajoutés d'avance pour C01 (`LockRank`).
- Reste du plan P03 : démarrage de l'examen (`StartConsultation`, avec C01), annulation d'une visite
  (commande absente du contrat), contrôle du paiement (B04, B06).

## Activité du patient (onglet « Activité » de la fiche ; R02, partie « activité patient »)

**Codé, non exécuté** (consigne du demandeur : vérification transversale en fin de phase). Lecture seule,
en ligne : l'historique du dossier, jamais écrasé, du plus récent au plus ancien.

| Méthode et route | Permission | Rôle |
| --- | --- | --- |
| `GET /api/v1/patients/{id}/activity?section=&page=` | `patient.read` (route) ; sections selon les droits ci-dessous | `PatientActivity` (contrat), 25 éléments par page |

Module `patients` (404 fermé). Patient d'un autre cabinet ou inconnu : 404 ; identifiant non ULID : 404.

| Section | Éléments (champs) | Rendue seulement si |
| --- | --- | --- |
| `VISITS` | visites : date, motif, statut administratif | `patient.read` (toujours) |
| `EXAMS` | consultations : date effective, état, nom du professionnel | `clinical.read` et module `consultations` ouvert |
| `PRESCRIPTIONS` | prescriptions optiques (une entrée par version : origine interne/externe, état, n° de version, date d'origine d'une ordonnance externe) ; ordonnance de médicaments d'une consultation (identifiant et état de la consultation) | `clinical.read` et module `consultations` ouvert |
| `FINANCIAL` | factures émises (numéro, montant, solde projeté), reçus (numéro, montant, mode, contre-passé, facture), avoirs émis, remboursements | `invoice.read` et module `sales` ouvert |

- **Droit financier** : `invoice.read` (« Lire facture autorisée », `02_reference_data.sql` : gérante et
  caisse), celui des routes de factures et de reçus. Aucun droit inventé.
- **Aucune trace d'une section non autorisée** : elle n'entre dans aucune requête ; `meta.sections` ne
  liste que les sections visibles avec leur nombre d'éléments ; un filtre `section` non visible répond
  403 sans rien dire du dossier, une valeur inconnue 422. Les droits sont les droits ACTUELS (une
  dérogation DENY retire la section).
- **Aucune valeur clinique** : ni motifs, mesures, conclusions, orientation, médicaments, notes ni
  prescripteur externe ; objets stricts au contrat (une fixture invalide le prouve). Pas d'audit de
  lecture pour cette liste (la consultation ouverte reste tracée par `consultation.viewed`).
- **Pièces** : seules les pièces réelles (facture `ISSUED`, avoir `ISSUED`, reçus, remboursements) ;
  brouillons et pièces abandonnées exclus. L'état de règlement est **dérivé** de la projection
  `vw_invoice_balances` (`UNPAID`, `PARTIALLY_PAID`, `SETTLED` = solde nul, `IN_CREDIT`), mêmes valeurs
  que `InvoiceBalance.settlement` ; aucun « payé » (un solde nul peut venir d'un avoir).
- **Ordre** : instant décroissant (visite : heure ; consultation : date effective ; prescription :
  validation, sinon création ; pièce : émission ou date d'effet), puis type (reçu avant facture,
  prescription avant consultation, consultation avant visite), puis identifiant décroissant.
- **Une ordonnance de médicaments** n'a ni table ni numéro propres : c'est la rubrique « médicaments »
  d'une consultation ; l'entrée porte l'identifiant et la date de la consultation. Une prescription
  optique n'a pas de numéro de pièce : le « numéro » affiché est sa version.
- **`screen_path`** : adresse de l'écran qui ouvre l'élément, donnée seulement si l'écran existe et que le
  compte peut l'ouvrir (`/consultations/{id}`, `/consultations/{id}/prescriptions`) ; null pour une
  visite, une ordonnance externe et les pièces (aucun écran de facture ni de reçu pour l'instant).
- Fichiers : `app/Domain/Patients/{PatientActivity,PatientActivityPage}.php`,
  `app/Http/Controllers/Api/V1/PatientActivityController.php`, `app/Http/Resources/PatientActivityPresenter.php`,
  route `api.patients.activity` ; contrat `patient_activity_resources()` dans `contracts/tools/build_resources.py`
  et `GetPatientActivity` dans `build_openapi.py` (`x-read-scope`) ; fixtures
  `contracts/fixtures/resources/patient-activity.*.json`.

**Écran** : `/patients/:id/activite` (route `patient-activite`, `patient.read`, module `patients`), onglets
« Identité » / « Activité » (`PatientRecordTabs`) ; la fiche d'identité ne lit jamais l'activité
(chargement du seul onglet nécessaire, fichier 04). `PatientActivityList` lit via `useRead` (fraîcheur,
« Activité indisponible » sans réponse, jamais un dossier vide), filtres par section seulement pour les
sections reçues (aucun filtre si une seule section), vides confirmés propres à la section (« Aucune visite
enregistrée »…), cartes empilées (aucun tableau à 360 px), lien seulement si l'adresse reçue correspond à
une route de cette version que le compte peut ouvrir (droit et module), sinon texte simple. Libellés purs :
`resources/frontend/domain/patients/activity.ts` ; service `listPatientActivity` (`app/services/patients.ts`).

**Tests écrits, non exécutés** : `tests/Feature/Patients/PatientActivityTest.php` (matrice des quatre
profils, DENY, modules fermés, cabinets, ordre, pagination, filtre, sentinelles cliniques et de référence de
paiement, pièces exclues et états de règlement, versions de prescription ; horloge figée avant les comptes ;
lignes insérées par `tests/Support/PatientActivityFixtures.php`), `tests/frontend/patients/activity-page.test.ts`
(droits, sections absentes, liens, vides, erreurs, pagination, 360 px), `tests/frontend/bootstrap.test.ts`
(15 routes).

| Point ouvert | État |
| --- | --- |
| Caisse et pièces du patient | `invoice.read` donne à la caisse toutes les pièces du patient (comme `GET /invoices`) ; la matrice 09 limite la caisse à la « facture sélectionnée » pour la finance globale : à confirmer avec le demandeur. |
| Liens vers les pièces | Aucun écran de facture ni de reçu : à ajouter dans le présentateur (`invoice.read`, module `sales`) quand la route d'écran existera. |
| Cohérence comptes / page | Deux requêtes sans transaction (interdite hors `TransactionRunner`) : une écriture concurrente peut décaler un compte d'une unité. |
| Performance | Union de 8 sous-requêtes indexées par cabinet et patient ; `vw_invoice_balances` agrège ses faits à chaque lecture : à mesurer sur le serveur de recette. |
| Limite de débit | Aucune dédiée (fichiers partagés `AppServiceProvider`, `config/eeo.php`) : à décider. |
| Dérivation du règlement | Recopiée de la règle de facturation (même contrat) pour ne pas dépendre de ses classes : à unifier après la phase. |
| Fiche fusionnée | Seules ses propres lignes (la fusion est L1). |
| Filtres conservés pendant la session (fichier 04) | Non : le filtre vit dans l'écran. |
| `GetPatientHistory` (`/patients/{id}/history`, PLANNED, P02) | Laissé tel quel : historique clinique détaillé, distinct de cette liste. |

## Écarts et points ouverts

| Point | Décision / état |
| --- | --- |
| « Correction proposée » du professionnel et de la caisse (matrice 09) | Aucune table ni commande au contrat : ces rôles lisent seulement. À décider avec le demandeur. |
| Détection de doublons côté serveur | Signalement par l'écran (recherche avant création) ; pas de route dédiée au contrat. Règle de ressemblance à confirmer (nom identique et même naissance ou même téléphone). |
| Création depuis un poste hors-ligne | Refusée (403) jusqu'à O03 (plages) et O05 (synchronisation). |
| Alignement des droits de la navigation « Aujourd'hui » avec la route de la file (`visit.register` ou `agenda.read`) | À faire dans l'écran P03. |
| Ligatures (œ, æ, ß) et noms collés (`dupont` / `du pont`) | Non repliés par `searchKey` (vecteurs PHP/TS partagés à modifier ensemble) : à traiter avec la revue d'usage. |
| Téléphone : plafond de 15 chiffres, séparateur `/` | Non traité (`Text::phone`, vecteurs partagés). |
| Horloge du numéro | `context->now` est figé avant l'attente du verrou du cabinet ; l'année d'un numéro créé juste à minuit local peut être celle d'avant. À corriger avant I00. |
| Identifiants fournis par le client | Unicité globale (clé primaire) : un identifiant pris dans un autre cabinet répond comme un identifiant pris ici ; la course entre deux cabinets reste une erreur 500 (improbable : ULID aléatoires). |
| Recherche : fiches archivées | Proposées (indicateur `archived`) ; à confirmer. |
| Structure du contact du responsable (`guardian_contact`) | Objet JSON libre, sans écran dans P01. |
| Performance de la recherche | À mesurer (20 000 patients) ; un index de préfixe ou une recherche plein texte seront envisagés si la cible n'est pas tenue. |
