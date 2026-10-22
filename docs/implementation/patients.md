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
