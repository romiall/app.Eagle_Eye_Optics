# Consultation clinique (C01 à C04)

Fonctions cliniques **en ligne**, **sans contrôle du paiement de l'examen** (B04 et B06 ne sont pas branchés
sur la consultation) : démarrer l'examen d'une visite, enregistrer les **sept rubriques** du dossier par étape
(motif et lunettes, antécédents, acuités, réfraction, centrage, médicaments, conclusion et orientation),
**terminer** l'examen (révision 1 figée), le **corriger** par une révision motivée, relire et comparer les
révisions, rédiger puis **valider** une prescription optique (brouillon, versions, ordonnance externe).

Rien ici n'est utilisable sans Internet : toute commande d'un poste hors-ligne (source `SYNC` ou tâche) est
refusée explicitement (`403 ACTION_FORBIDDEN`) tant que la synchronisation clinique (coffre, compartiments,
O01 à O05) n'existe pas. L'écran ne dit jamais « enregistré sur ce poste » : une étape est dite enregistrée
après la réponse du serveur seulement.

> **État : codé, non exécuté.** Les tests PHPUnit, Vitest et Playwright de C01 à C04 sont écrits mais n'ont pas
> été lancés dans cette passe (vérification transversale à la fin de la phase).

## Routes (module `consultations`, ouvert par l'exploitant)

| Méthode et route | Permissions (toutes exigées) | Réponse |
| --- | --- | --- |
| `GET /api/v1/visual-acuity-scales` | `clinical.read` | 200 `VisualAcuityScaleList` (échelles du cabinet, actives d'abord) |
| `POST /api/v1/visits/{id}/consultations` | `clinical.write`, `clinical.read` | 201 `ConsultationResponse` (`StartConsultation`) |
| `GET /api/v1/consultations/{id}` | `clinical.read` · débit limité | 200 `ConsultationResponse` |
| `PATCH /api/v1/consultations/{id}/steps/{step}` | `clinical.write`, `clinical.read` | 200 `ConsultationResponse` (`SaveClinicalStep`) |
| `POST /api/v1/consultations/{id}/complete` | `clinical.complete`, `clinical.read` | 200 `ConsultationResponse` (`CompleteConsultation`) |
| `POST /api/v1/consultations/{id}/amendments` | `clinical.amend`, `clinical.read` | 201 `ConsultationResponse` (`AmendConsultation`) |
| `GET /api/v1/consultations/{id}/revisions/{revision_no}` | `clinical.read` · débit limité | 200 `ClinicalRevisionResponse` (snapshot figé) |
| `GET /api/v1/consultations/{id}/prescriptions` | `clinical.read` · débit limité | 200 `PrescriptionList` (plus récente d'abord) |
| `POST /api/v1/consultations/{id}/prescriptions` | `clinical.write`, `clinical.read` | 201 `PrescriptionResponse` (`CreatePrescription`, origine interne) |
| `POST /api/v1/prescriptions/external` | `clinical.write`, `clinical.read` | 201 `PrescriptionResponse` (`CreatePrescription`, ordonnance externe) |
| `GET /api/v1/prescriptions/{id}` | `clinical.read` · débit limité | 200 `PrescriptionResponse` |
| `PATCH /api/v1/prescriptions/{id}` | `clinical.write`, `clinical.read` | 200 `PrescriptionResponse` (`UpdatePrescriptionDraft`, en ligne seulement) |
| `POST /api/v1/prescriptions/{id}/revisions` | `clinical.amend`, `clinical.read` | 201 `PrescriptionResponse` (`CreatePrescription`, nouvelle version) |
| `POST /api/v1/prescriptions/{id}/validate` | `clinical.complete`, `clinical.read` | 200 `PrescriptionResponse` (`ValidatePrescription`) |

Toutes les écritures passent par le chemin central (`Idempotency-Key` obligatoire, idempotence, verrous,
audit, journal des changements) ; route directe, synchronisation future et tâche appellent la même action.

- **Module fermé** : 404 avant toute vérification de droit (même pour la gérante).
- **Droits** : gérante et professionnel seulement. Accueil et caisse n'ont aucune permission `clinical.*` :
  403 sur toutes ces routes, **y compris pour une ressource existante** (le refus ne dit pas si elle existe). Un
  identifiant d'un autre cabinet répond 404.
- **Chaque écriture exige aussi `clinical.read`** : sa réponse rend le dossier (ou la prescription) courant ; une
  dérogation qui retire la lecture ne se contourne pas par l'écriture.
- **Identifiants du chemin et du corps** : ils doivent coïncider (422), jamais l'un pour l'autre :
  `visit_id`, `consultation_id`, `step.step`, `supersedes_id` (nouvelle version), `prescription_id` (validation).
- **Limite de débit des lectures** : limiteur `eeo-consultation-read`, **par compte**, 60 lectures par minute par
  défaut (`EEO_RATE_CONSULTATION_READ`, `config('eeo.rate_limits.consultation_read')`), commun aux lectures d'une
  consultation, d'une révision et des prescriptions ; au-delà : 429 `RATE_LIMITED`.

## Corrections de C01 (revue)

| Constat | Correction |
| --- | --- |
| Une écriture sans effet (ou le rejeu d'une clé) rendait tout le dossier sans trace de lecture | Toute réponse qui rend le dossier est une **lecture tracée** (`ClinicalReadAudit`), démarrage, enregistrement, finalisation et correction compris ; même règle d'intervalle (au plus un événement par compte et par cible toutes les 10 minutes). |
| Lecture en plusieurs requêtes autocommit : état mélangé possible pendant une écriture | `ConsultationReader::view` relit l'ensemble tant que la version relue en fin de lecture diffère de celle du début (3 tentatives), puis 503 `SERVER_BUSY` (à renvoyer à l'identique) plutôt qu'un état qui n'a jamais existé. Aucune transaction hors du chemin central. |
| Aucune limite de débit sur la lecture d'un dossier | Limiteur par compte (ci-dessus). |
| L'audit `consultation.step_saved` portait un nombre de lignes (`rows`) | Il ne porte plus que `{step, version}`. |
| Un code de référentiel désactivé bloquait toute sauvegarde de la rubrique qui le contenait | Une ligne **déjà enregistrée** (même identifiant, même code) reste ré-enregistrable ; une ligne nouvelle ou dont le code change porte un code actif (`TermList`, paramètre `$existing`). |
| « Précisez le texte de « Autre » » s'ajoutait au message d'un texte invalide | Le message de consigne n'est donné que si le texte manque vraiment. |
| Message « Type de valeur invalide » en double d'un message de longueur | Le validateur de contrat ne garde que le message de limite. |
| Un identifiant de consultation déjà pris dans un autre cabinet donnait une erreur SQL | 422 `consultation_id` (contrôle global). |
| L'en-tête patient dépendait du module Patients et de `patient.read` | `Consultation.patient` : identité administrative de base (dossier, nom, prénoms, sexe, naissance) rendue avec `clinical.read`. |
| Textes de la visite (consultation masquée) | Docblock et message corrigés ; le statut administratif `IN_CONSULTATION` reste visible de tous. |

Points relevés **documentés, non corrigés** (décision à prendre, voir « Points ouverts ») : normalisation des
blancs internes d'un texte (le middleware `TrimStrings` ne retire que les bords), élément d'antécédents vide
admis, motif d'une date rétrospective facultatif.

## Rubriques mesurées (C02)

Même mécanique que les rubriques narratives : **remplacement complet**, comparaison **par identifiant de ligne**
(suppressions, puis mises à jour, puis insertions : deux lignes peuvent échanger leur clé d'unicité), toutes les
erreurs rendues ensemble avant la première écriture (422, clés `step.data.…`), enregistrement sans effet =
version inchangée, ni audit ni changement. Un identifiant de ligne déjà pris ailleurs → 422 par champ.

**Mesures** (`Rules/Measures`, jumeau TypeScript `domain/clinical/fields`) : chaînes décimales exactes
(contrat `Diopter`, `DECIMAL(6,2)`, au plus 4 chiffres entiers, 2 décimales) ; une valeur à 3 décimales est
**refusée**, jamais arrondie ; un flottant JSON est refusé ; zéro est une valeur distincte de null (non mesuré) ;
axe = entier 0 à 180 ; le cylindre positif n'est **jamais transposé**. Plages cliniques : aucune n'est inventée
(Q06) ; le pas usuel de 0,25 est seulement **signalé** à l'écran, jamais imposé.

| Rubrique | Règles |
| --- | --- |
| `VISUAL_ACUITY` | Une ligne = une valeur **mesurée** (case vide = pas de ligne). Préliminaire : loin ou près, sans ou avec correction ; après correction : distance `UNSPECIFIED`, `WITH` seulement. Une ligne par (contexte, distance, correction, œil). Valeur lue conservée **telle que saisie** (1 à 100 caractères : « 10/10 », « P2 », « CLD », « 0 »). Échelle **active** du cabinet ; une échelle désactivée depuis reste valable pour la ligne qui l'utilise déjà. `normalized_value` n'est **jamais calculée ni acceptée** (aucune conversion validée, Q06) : une valeur déjà enregistrée hors chemin est conservée telle quelle. |
| `REFRACTION` | Autoréfracteur, objective, subjective **distincts**, une ligne par (stade, œil OD/OG) ; une ligne porte au moins une mesure ; l'addition n'existe que pour la subjective ; observations libres ; amétropies choisies dans le référentiel `AMETROPIA` (jamais déduites des chiffres). Un cylindre non nul sans axe est admis pour une **mesure** (exigé pour valider une prescription). |
| `CENTERING` | Une ligne par consultation. Écarts strictement positifs ; le total et les monoculaires sont saisis séparément, **aucun n'est fabriqué** à partir de l'autre (l'écran propose la somme OD + OG, la personne la reporte si elle veut). Si total, OD et OG sont saisis et que OD + OG ≠ total au-delà de la tolérance : **justification obligatoire**. Tolérance : **hypothèse 0** (`CenteringStep::TOLERANCE_UNITS`, Q06). Une justification sans écart est refusée. Sans valeur : pas de ligne, `centering_id` null (G6) ; dès qu'une valeur est saisie, l'identifiant (ULID) vient du poste et doit être celui de la ligne existante. |

**Échelles d'acuité** (`visual_acuity_scales`, Q06 et décision G11 ouvertes) : aucune n'est validée par la
cliente. Commande d'exploitation `php artisan eeo:clinical:scales` :

- `--demo` : trois échelles de **DÉMONSTRATION** fictives (`DEMO_DIXIEMES`, `DEMO_SNELLEN_6`, `DEMO_PRES`), libellées
  « DÉMONSTRATION », en environnements `local`, `testing`, `e2e`, `demo` seulement (refus en production) ;
- `--code=… --label=… [--description=…]` : l'échelle **réelle** choisie par le cabinet, dans tout environnement ;
- `--clinic=…` si la base a plusieurs cabinets. Idempotente (un code présent n'est ni dupliqué ni modifié), audit
  `clinical.acuity_scale_created`. `tests/e2e/prepare-env.mjs` crée les échelles de démonstration.

## Médicaments (C04, rubrique `MEDICATIONS`)

Septième rubrique ajoutée au contrat (`StepName`, `AllSteps`, `AmendConsultation` jusqu'à 7 rubriques) entre
le centrage et la conclusion. Saisie libre **contrôlée** (aucune base de médicaments fournie ni inventée) :
nom (1 à 180) et forme (collyre, pommade, comprimé, autre + précision) obligatoires ; dosage, posologie,
fréquence, durée en texte, instructions facultatifs et **conservés tels que saisis** (rien n'est déduit ni
complété) ; durée structurée = nombre entier > 0 **et** unité (jour, semaine, mois), les deux ou aucun ; 20 lignes
au plus. L'**ordre compte** (position = rang dans la liste, jamais saisie) : changer l'ordre est un changement réel.
`catalog_item_id` facultatif, doit exister dans le catalogue du cabinet. Prescrire **ne crée ni vente ni
mouvement de stock** (seule `medication_orders` est écrite).

## Finalisation et correction (C03)

### `CompleteConsultation`

- Consultation `IN_PROGRESS` (brouillon sans rubrique ou déjà terminée : 409 `STATE_TRANSITION_REFUSED`) ; version
  attendue = `consultations.version` (409 `VERSION_CONFLICT`) ; visite `IN_CONSULTATION`.
- Fichier 02 : « exiger motif ou plaintes, conclusion ou justification d'absence, résolution des erreurs ; ne pas
  exiger des mesures non réalisées ». Lecture retenue : si le motif/les plaintes **ou** la conclusion manquent,
  `finalization_missing_data_reason` est obligatoire (≤ 4000). Les rubriques mesurées vides ne sont **jamais**
  exigées ni transformées en « aucun ».
- L'état enregistré des sept rubriques est **revalidé** par les règles de l'enregistrement (un état écrit hors
  chemin et devenu invalide bloque : erreurs sous `steps.<RUBRIQUE>.…`).
- `snapshot_sha256` = SHA-256 de JCS(steps) **que le poste a lues** ; le serveur le **recalcule** sur les tables
  vivantes sous verrou et refuse un écart (422) : on ne fige jamais autre chose que ce qui a été relu.
- Une transaction : révision 1 (`clinical_revisions`, snapshot complet, motif « Finalisation de la consultation »,
  auteur, `sha256`), consultation `COMPLETED` (`completed_at`, `current_revision` 1, version + 1), visite
  `COMPLETED` avec `closed_at`. Aucun contrôle de paiement.
- Effets : audit `consultation.completed` `{revision_no, revision_id, sha256}` ; événement `ConsultationCompleted`
  (références seulement) ; journal des changements (visite, consultation, révision). Verrous : cabinet →
  idempotence → visite (46) → consultation (47).

### `AmendConsultation`

- Consultation `COMPLETED` (sinon 409 : une consultation en cours se modifie directement) ; version attendue ;
  `revision_no` = révision courante + 1 et `previous_revision_id` = la révision courante (422 sinon) ;
  identifiant de révision neuf.
- **Motif obligatoire** (3 à 1000 caractères, schéma `Reason`) : conservé avec la révision (lisible avec
  `clinical.read`), **jamais recopié dans l'audit** (il peut contenir une donnée clinique).
- Chaque rubrique envoyée remplace la rubrique actuelle (mêmes règles, erreurs `steps.<n>.data.…`), une fois au
  plus ; une correction qui ne change rien est refusée (422 `steps`).
- `snapshot_sha256` = SHA-256 de JCS(`steps` de la requête), recalculé par le serveur (intégrité de ce qui est reçu).
- Le snapshot de la révision N est construit **après** les écritures, dans la même transaction (état résultant,
  `previous_revision_id`, `amendment_reason`). Les révisions antérieures ne changent jamais (triggers de la base).
- Audit `consultation.amended` `{revision_no, revision_id, previous_revision_id, steps (noms), sha256}`. La visite
  n'est pas touchée. Verrous : cabinet → idempotence → consultation.

### Empreintes (hypothèse « G8 »)

`clinical_revisions.sha256` = SHA-256 hexadécimal minuscule de **JCS(snapshot)**, calculé par le serveur seul
(`RevisionHash`, sous-ensemble de JCS : objets à clés ASCII triées, listes, chaînes, **entiers** seulement, booléens,
null ; un flottant lève une exception). Jumeau TypeScript `domain/clinical/snapshot.ts` (Web Crypto). Vecteurs
communs `contracts/vectors/clinical/snapshot-hash.json` (recoupés par une implémentation Python indépendante),
rejoués par PHPUnit et Vitest. La décision « G8 » des notes de pilotage n'est pas inscrite au registre 15 : à
confirmer (point ouvert).

### Lecture des révisions et comparaison

`GET …/revisions/{n}` rend la révision **telle qu'enregistrée** (snapshot compris), trace `consultation.revision_viewed`
(cible la révision, sans contenu). L'écran « Révisions de la consultation » compare deux révisions fait par fait :
changement dit **en texte et par un symbole** (« ≠ Modifié », « + Ajouté », « − Retiré », « = Identique ») ; une
valeur absente est « Non renseigné » ou « Non mesuré », jamais « aucun » ; une acuité est toujours rendue avec son
échelle et deux échelles différentes sont déclarées **non comparables** (aucune conversion).

## Prescription optique (C04)

| État | Règles |
| --- | --- |
| Création (`CreatePrescription`) | Brouillon `DRAFT`. **Interne** : consultation du cabinet et du patient indiqué ; **une seule série par consultation** (une seconde première version → 409, la suite passe par une nouvelle version). **Externe** (A13) : prescripteur et date d'origine obligatoires (pas dans le futur, jour du cabinet), fichier facultatif qui doit exister, **aucune consultation** (aucun examen fictif). **Nouvelle version** : la précédente est `VALIDATED` et la plus récente de sa série ; même série, patient, origine et consultation ; numéro suivant. Identifiants (prescription, série, yeux, traitements) neufs : sinon 422 par champ. |
| Contenu (`OpticalPrescriptionRules::normalize`) | Deux yeux exactement (OD, OG) ; mesures exactes (comme C02), cylindre positif non transposé, hauteur de montage > 0 ; « aucune correction » contradictoire avec une valeur ; usage, type de verre, traitements dans leurs référentiels (`OPTICAL_USAGE`, `LENS_TYPE`, `LENS_TREATMENT`), « Autre » avec précision ; indice dans la liste du fichier 02 (1.50 à 1.74) ou « autre » avec précision ; DP : mêmes règles que le centrage (justification d'un écart, jamais de DP fabriquée). Un brouillon peut être **incomplet**. Un code désactivé reste valable s'il est déjà enregistré sur ce brouillon. |
| Modification d'un brouillon (`UpdatePrescriptionDraft`) | **En ligne seulement** (hors catalogue F06 : hors-ligne, une correction est une nouvelle version). Jeton `expected_updated_at` (la table n'a pas de colonne `version`, que le contrat 23 interdit d'inventer) : écart → 409 `VERSION_CONFLICT` avec `details.current_updated_at`. `changes` ne contient que des champs de contenu ; yeux et traitements fournis **remplacent** les actuels ; contenu résultant revalidé en entier ; sans effet : rien n'est écrit. Audit `prescription.updated` : noms des champs, jamais leurs valeurs. `expected_version: null` est toléré au corps (ajouté par le répartiteur du poste). |
| Validation (`ValidatePrescription`) | Brouillon seulement (sinon 409) ; pour chaque œil, des valeurs saisies **ou** « aucune correction » ; un cylindre non nul exige un axe. La version devient **immuable** (triggers : ni la ligne ni ses enfants). Dans la même transaction, un document est **préparé** : `generated_documents` `OPTICAL_PRESCRIPTION`, statut `PENDING`, `source_key` `optical_prescription:<id>`, `revision_no` = numéro de version, `template_version` `optical_prescription.v1` (convention `<type>.v<n>` du registre des modèles ; la mention « test » vient du cabinet, jamais du nom). **Aucun fichier n'est écrit ici** : la génération suit le commit et appartient au moteur de documents (D01, D02). Rien n'est dit « imprimé ». Aucune vente, ligne de facture ni mouvement de stock. Audit `prescription.validated`, événement `PrescriptionValidated`. |
| Lecture | Une prescription ; la liste des versions d'une consultation (plus récente d'abord). Traces `prescription.viewed` et `consultation.prescriptions_viewed`, sans contenu, au plus une par intervalle. |

**Écran « Prescription optique »** (`/consultations/{id}/prescriptions`) : brouillon marqué « BROUILLON » ; copie
de la réfraction **explicite** (choisir le stade, confirmer ; l'œil sans mesure n'est pas touché, la prescription
s'édite ensuite sans modifier l'examen) ; report explicite des écarts du centrage ; « Valider » exige un brouillon
enregistré sans modification en attente ; nouvelle version d'une version validée (lignes neuves) ; conflit de
brouillon : saisie conservée et champs modifiés ailleurs montrés. **La transcription d'une ordonnance externe n'a
pas d'écran** (aucune route de liste par patient) : serveur et domaine seulement.

**Impression (branchée sur le moteur de documents, D02)** : l'ancien `domain/clinical/printModels.ts` (doublon
du moteur) est **supprimé** ; une seule source de vérité, les adaptateurs `documents/data/clinicalAdapters.ts`
lisant les ressources générées (`Prescription`, `Consultation`, `ClinicalRevisionDetail`), appelés par le service
`app/services/clinicalDocuments.ts` (détail : `documents.md`, « Documents cliniques branchés sur les écrans »).

- **Examen terminé** (`ConsultationDocuments.vue`, sous « Examen terminé », jamais pendant une correction ni pour un
  examen en cours) : « Aperçu de la fiche de consultation » (`clinical.export`), « Aperçu de l'ordonnance de
  médicaments » (rubrique Médicaments renseignée), « Aperçu de la lettre d'orientation » (orientation « oui ») ;
  chaque document est construit de la **révision figée relue** au moment de la demande (`GET …/revisions/{n}`),
  auteur et numéro de révision imprimés. Aucune lecture avant le clic.
- **Prescription optique** (écran « Prescription optique ») : « Aperçu de la prescription (version N) » pour la
  dernière version **validée** seulement ; un brouillon n'a aucune impression. Nom imprimé : compte connecté s'il a
  validé, sinon nom figé d'une révision enregistrée par le même professionnel, sinon refus (jamais deviné).
- Document de TEST tant que `production_documents_enabled` est faux ; identité légale, signature et cachet : non
  inventés. Conservation : port `DocumentStore` volatil ; la ligne `generated_documents` reste `PENDING` (aucune
  route de dépôt). Ordonnance et lettre : sans `document_type` serveur, générées localement.
- L'écran de lecture d'une prescription (`PrescriptionView.vue`) affiche `prescriptionScreenModel` : mêmes valeurs
  et mêmes mises en forme que le PDF.

## Écran de consultation (frontend)

`/consultations/{id}` : sept étapes (liste numérotée, « Étape N sur 7 »), en-tête patient lu dans la consultation,
« Suivant » enregistre avant d'avancer, conflit de version rebasé valeur par valeur (rubriques mesurées comprises).
Acuités : grille loin/près × sans/avec correction × OD/OG/OU puis après correction, **une échelle par groupe**
choisie explicitement (suggestions de saisie des échelles de démonstration, saisie libre conservée), alerte si
aucune échelle n'est configurée ou lisible. Réfraction : trois tableaux, indications non bloquantes (pas de 0,25,
cylindre sans axe). Centrage : somme OD + OG proposée, justification demandée en cas d'écart. Médicaments : lignes
ajoutées, retirées, déplacées. « Terminer l'examen » (compte `clinical.complete`, examen en cours, rien de non
enregistré) : confirmation avec la liste des rubriques non renseignées et la justification si besoin. Examen
terminé : lecture seule, liens « Révisions et comparaison » et « Prescription optique », « Corriger l'examen »
(`clinical.amend`) : motif obligatoire, les rubriques modifiées partent en une révision. Rien n'est conservé dans
le navigateur.

## Décisions, hypothèses, points ouverts

| Point | Décision / état |
| --- | --- |
| Contrôle du paiement de l'examen | Inchangé pour la consultation : finalisation sans contrôle ; AT05/AT06 non prouvés par C01–C04. |
| Échelles d'acuité (Q06, G11) | Démonstration seulement, libellées comme telles ; échelles réelles par `eeo:clinical:scales --code`. Aucune conversion (`normalized_value` jamais calculée). |
| Tolérance DP total / OD + OG (Q06) | Hypothèse : 0 (toute différence se justifie). Pas de 0,25 : signalé, non imposé. Plages cliniques : non inventées. |
| Empreintes (« G8 ») | JCS + SHA-256 ; `snapshot_sha256` de la finalisation = rubriques lues, de la correction = rubriques envoyées ; `sha256` de la révision = snapshot. À inscrire au registre 15. |
| Règle de finalisation | Justification exigée si motif/plaintes **ou** conclusion manquent ; à confirmer avec la cliente. |
| Motif d'une correction | Dans la révision, jamais dans l'audit. |
| Prescription : une série par consultation | Unicité (consultation, version) du MLD ; les corrections sont des versions de la même série. |
| Brouillon de prescription | Modifiable en ligne seulement, jeton `updated_at` (milliseconde). |
| Document de prescription | Ligne `generated_documents` `PENDING` écrite par la validation, `template_version` `optical_prescription.v1` ; PDF généré sur le poste (D02), aucune route ne la passe à `READY`. |
| Nom du professionnel d'une prescription | `Prescription.validated_by_name` (2026-10-22) : nom d'affichage du compte validateur, lu dans le cabinet à chaque lecture. Ce n'est PAS un nom figé (aucune colonne au schéma) : le PDF original, lui, est figé à sa génération. L'impression n'est refusée que si aucun nom n'est disponible. |
| Ordonnance externe | Serveur et domaine prêts ; pas d'écran (nécessite une liste par patient, P02). |
| Motif d'une date rétrospective, blancs internes, élément d'antécédents vide | Documentés, non tranchés (à confirmer avec la cliente). |
| Poste hors-ligne | Toutes les commandes cliniques refusées (403) hors route directe jusqu'à O01–O05. |

## Tests (écrits, non exécutés dans cette passe)

PHPUnit (MySQL réel) — `tests/Feature/Clinical/` : `StartConsultationTest`, `SaveClinicalStepTest`,
`SaveAnamnesisStepTest`, `SaveHistoriesStepTest`, `SaveConclusionStepTest`, `GetConsultationTest` (traces des
écritures, échec de l'audit = échec de la lecture, débit), `ConsultationReaderSnapshotTest` (relecture,
503), `SaveVisualAcuityStepTest`, `SaveRefractionStepTest`, `SaveCenteringStepTest`, `SaveMedicationsStepTest`,
`VisualAcuityScalesTest` (route et commande), `CompleteConsultationTest`, `AmendConsultationTest`,
`GetRevisionTest`, `CreatePrescriptionTest`, `UpdatePrescriptionDraftTest`, `ValidatePrescriptionTest`,
`GetPrescriptionTest` (droits, cabinets, module, rejeu, retour arrière à chaque point du chemin, ordre des
verrous, sources hors-ligne, confidentialité des effets de bord, triggers d'immuabilité) ; règles pures
`tests/Unit/Clinical/` : `NarrativeRulesTest`, `MeasurementRulesTest` (vecteurs), `RevisionHashTest` (vecteurs),
`PrescriptionRulesTest`. Vitest — `tests/frontend/domain/clinical-{snapshot,measures,medications,compare,prescriptions}.test.ts`,
`tests/frontend/consultations/consultation-{page,steps,errors,finish,measures,history}.test.ts`,
`prescriptions-page.test.ts`, `consultation-documents.test.ts` (impression : état, droits, révision figée, nom de
fichier), `tests/frontend/services/consultations.test.ts`. Playwright —
`tests/e2e/consultation.spec.ts` (sept étapes, rechargement, finalisation, révision, 360 px). Contrats : fixtures
`save-clinical-step.{visual-acuity,medications}.valid` et trois `.invalid`, snapshot de révision avec
`MEDICATIONS`. Piège de test : figer l'horloge **avant** de créer les comptes (`tests/Support/ClinicalFixtures.php`) ;
le jeton d'un brouillon de prescription est à la milliseconde : avancer l'horloge entre deux modifications.

## Liste des examens (phase 1, ajoutée avec l'accueil par profil)

`GET /api/v1/consultations` (`ListConsultations`, module `consultations`, `clinical.read`, même limite de débit par
compte que les lectures de dossier) : 50 examens par page, du plus récent au plus ancien (date effective), filtres
`date` (jour civil du cabinet, jamais minuit UTC), `status` et `mine=true`. Chaque élément porte l'identité
administrative de base du patient, l'état, les dates, la révision et le professionnel (`ConsultationSummary`) :
AUCUNE valeur clinique et aucune trace de lecture (le dossier s'ouvre par `GET /consultations/{id}`, tracé).
Code : `app/Domain/Clinical/ConsultationDirectory.php`, `ConsultationListController`, `ConsultationSummaryPresenter`.
Écran : `pages/consultations/ConsultationListPage.vue` (jour du cabinet par défaut ; préréglages `?jour=tous`,
`?etat=IN_PROGRESS`, `?mes=1` pour les raccourcis de l'accueil). Tests écrits :
`tests/Feature/Clinical/ListConsultationsTest.php`, `tests/frontend/consultations/consultation-list.test.ts`.

Identité du cabinet sur les documents cliniques : `GET /api/v1/clinic/document-identity` (lisible avec
`clinical.read`) et `clinicFromIdentity()` de `documents/data/clinic.ts` remplacent `clinicFromSummary()` (nom seul)
pour une ordonnance ou une fiche de consultation ; le branchement dans `app/services/clinicalDocuments.ts` reste à
faire par le chantier de l'impression clinique.
