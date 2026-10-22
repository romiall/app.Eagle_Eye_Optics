# Consultation clinique (C01) — serveur

Première tranche des fonctions cliniques, **en ligne**, **sans contrôle du paiement de l'examen** (B04 et B06
n'existent pas) : démarrer l'examen d'une visite, lire une consultation, enregistrer les rubriques
**narratives** par étape (motifs et lunettes, antécédents, conclusion et orientation). Les rubriques mesurées
(acuités, réfraction, centrage), la finalisation et la correction ne sont pas dans cette tranche (C02, C03).
Rien ici n'est utilisable sans Internet : les commandes d'un poste hors-ligne sont refusées explicitement
(`403 ACTION_FORBIDDEN`) tant que la synchronisation clinique (coffre, compartiments, O01 à O05) n'existe pas.

## Routes (module `consultations`, ouvert par l'exploitant)

| Méthode et route | Permissions | Réponse |
| --- | --- | --- |
| `POST /api/v1/visits/{id}/consultations` | `clinical.write` et `clinical.read` | 201 `ConsultationResponse` (commande `StartConsultation`, `Idempotency-Key` obligatoire) |
| `GET /api/v1/consultations/{id}` | `clinical.read` | 200 `ConsultationResponse` (relue dans les tables vivantes) |
| `PATCH /api/v1/consultations/{id}/steps/{step}` | `clinical.write` et `clinical.read` | 200 `ConsultationResponse` (commande `SaveClinicalStep`, `Idempotency-Key` obligatoire, version attendue) |

`CompleteConsultation`, `AmendConsultation` et `GetPatientHistory` restent `PLANNED` au contrat (C03, P02).

- **Module fermé** : 404 `RESOURCE_NOT_FOUND`, avant toute vérification de droit (même pour la gérante).
- **Droits** : gérante (`MANAGER`) et professionnel (`CLINICIAN`) seulement. Accueil et caisse n'ont aucune
  permission `clinical.*` : 403 `ACTION_FORBIDDEN` sur ces routes, **y compris pour une consultation existante**
  (le refus ne dit pas si elle existe : AT02). Un identifiant d'un autre cabinet répond 404.
- **Les deux écritures exigent aussi `clinical.read`** : leur réponse est la consultation courante (un rejeu de
  la même clé la relit, elle peut contenir ce que d'autres ont saisi). Une dérogation qui retire la lecture ne
  se contourne donc pas par l'écriture (même règle que `POST/PATCH /patients` avec `patient.read`). Écart
  assumé avec la liste de décisions du demandeur, qui ne demandait `clinical.read` que pour `PATCH` : sans effet
  pour les profils seedés.
- **Identifiants du chemin et du corps** : `visit_id` (démarrage), `consultation_id` et `step.step`
  (enregistrement) doivent être ceux de l'adresse, sinon 422 (clés `visit_id`, `consultation_id`, `step.step`).
  La rubrique du chemin n'est pas une contrainte de route (un nom inconnu donne un 422 lisible, pas un 404).
- Aucune limite de débit dédiée : les identifiants sont des ULID non devinables, la lecture est tracée mais
  limitée par l'intervalle de 10 minutes (ci-dessous), et les écritures sont sérialisées par le verrou du cabinet.

## Démarrage de l'examen (`StartConsultation`)

Corps de la route directe : `expected_version: null`, `consultation_id` (ULID fourni par l'écran), `visit_id`,
`patient_id`, `effective_at` (instant UTC `…T…:…:….mmmZ`), `date_change_reason` (texte ou null),
`exam_invoice_id` et `exam_waiver_approval_id` (null).

- **Contrat assoupli (G1)** : `exam_invoice_id` et `exam_waiver_approval_id` sont nullables et facultatifs au
  schéma (charge et `DirectRequest`), sans preuve exigée. Schéma de la commande toujours en version `1`
  (assouplissement : un poste qui détient une pièce `v1` reste valide).
- **Contrôle du paiement de l'examen** : interface `ExamFeeControl`, liée à `UncontrolledExamFee`. Elle répond
  toujours `NOT_CONTROLLED` ; **aucun interrupteur de configuration** ne permet de « faire comme si ».
  `consultation.started` porte `exam_fee_control: NOT_CONTROLLED`. `visits.exam_invoice_id` et
  `visits.exam_waiver_approval_id` restent null. Une preuve **fournie** (non null) est refusée en 422
  (`exam_invoice_id` ou `exam_waiver_approval_id`) plutôt qu'ignorée : l'écran laisserait croire qu'elle est
  enregistrée. B04 et B06 remplaceront seulement l'implémentation de l'interface.
- **Visite** : du cabinet (404 sinon), du patient indiqué (422 `patient_id`), de motif `EXAM` ou `FOLLOW_UP`
  (422 `visit_id` pour un achat direct ou un retrait : jamais de consultation fictive), au statut `ARRIVED` ou
  `READY_FOR_EXAM`. Autre statut (`AWAITING_EXAM_FEE`, `IN_CONSULTATION`, `COMPLETED`, `CANCELLED`) ou
  consultation déjà démarrée pour cette visite, **même sous une autre clé** : 409 `STATE_TRANSITION_REFUSED`
  avec `details.current_status` = statut de la visite. L'écran reprend alors la consultation existante
  (`consultation_id` de la visite).
- **Écritures** (une transaction) : consultation `DRAFT`, `current_revision` 0, `version` 1, `clinician_id` = le
  compte connecté (jamais un champ de la charge, même si la gérante démarre) ; visite → `IN_CONSULTATION`,
  `version + 1`.
- `effective_at` : pas dans le futur au-delà de la tolérance d'horloge (`eeo.offline.max_clock_skew_seconds`,
  comme `RegisterVisit`) ; une saisie rétrospective est admise **avec ou sans** motif (`date_change_reason` est
  facultatif ; le seuil de « rétrospectif » n'est pas défini par les sources, il n'est pas inventé). Le motif
  fourni est conservé tel quel (blancs de bord retirés, vide → null).
- **Effets** : audit `consultation.started` ({`visit_id`, `patient_id`, `exam_fee_control`, `effective_at`}, jamais
  le motif de date) ; journal des changements `visits` (`ADMINISTRATIVE`, `UPDATED`, version + 1) et
  `consultations` (`CLINICAL`, `CREATED`, version 1) ; aucun événement d'outbox ; résultat idempotent
  `{consultation_id, visit_id, version, status}`.
- **Verrous** : cabinet → idempotence → visite (rang 46) → consultation (rang 47).

## Enregistrement d'une rubrique (`SaveClinicalStep`)

Corps : `expected_version` (entier ≥ 1, `consultations.version`), `consultation_id`, `step: {step, data}`.

- **Rubriques ouvertes** : `ANAMNESIS`, `HISTORIES`, `CONCLUSION`. `VISUAL_ACUITY`, `REFRACTION` et `CENTERING`
  répondent **422** `step.step` « Cette rubrique n'est pas encore ouverte. » et **n'écrivent rien** (C02).
- **Sémantique : remplacement complet** de la rubrique. Les lignes absentes de la charge sont supprimées (c'est
  ainsi qu'on efface une valeur). Comparaison **par identifiant de ligne** : ligne inchangée non touchée, texte
  modifié → mise à jour, code ou groupe modifié → ligne recréée avec le même identifiant ; **toutes les
  suppressions précèdent les mises à jour puis les insertions** (deux lignes peuvent échanger leurs codes sans
  heurter l'unicité). Un terme peut changer d'élément d'antécédents en gardant son identifiant.
- **Version** : comparée à `consultations.version` lue sous le verrou de la consultation (rang 47) ; écart →
  409 `VERSION_CONFLICT` avec `details.current_version`, rien d'écrit. La version couvre **toute la
  consultation** : enregistrer une rubrique invalide la version tenue par un autre poste, même s'il édite une
  autre rubrique (AT19 : conflit conservé, rien n'est perdu ni mélangé).
- **État** : consultation `COMPLETED` → 409 `STATE_TRANSITION_REFUSED`, `details.current_status` = `COMPLETED`
  (la correction passera par une révision motivée, C03). Première sauvegarde : `DRAFT` → `IN_PROGRESS`.
- **Enregistrement sans effet** (état résultant identique à l'état enregistré, l'ordre des lignes ne comptant
  pas, consultation déjà en cours) : même réponse, **version inchangée**, ni audit ni changement publié.
- **Intégrité, toutes les erreurs rendues ensemble, avant la première écriture** (422 `VALIDATION_FAILED`, clés
  pointées relatives au corps de la requête, comme celles du validateur de contrat) :

| Rubrique | Règles refusées (clé en cas d'échec) |
| --- | --- |
| Toutes | texte ≤ 4000 caractères (blancs de bord retirés, vide → null), aucun caractère de contrôle (retours à la ligne admis sauf dans le texte d'un « Autre ») ; `step.data.<champ>` |
| Listes de termes | code inexistant, inactif ou d'un autre groupe de `clinical_terms` (`….term_code`) ; code en double dans la liste (`….term_code` de la 2e ligne) ; identifiant de ligne invalide ou en double (`….id`) ; `OTHER` sans texte, ou texte sur un autre code (`….other_text`) |
| Identifiants | un identifiant de ligne déjà utilisé **ailleurs** (autre consultation, autre cabinet) → 422 `….id` « déjà utilisé », jamais une erreur SQL ; les lignes de cette consultation sont réutilisables |
| `ANAMNESIS` | `wears_glasses` différent de `true` avec `glasses_duration` ou `previous_correction_available` renseignés → **refus** (`step.data.glasses_duration`, `step.data.previous_correction_available`), jamais d'effacement silencieux ; `false` (non) reste distinct de `null` (non renseigné) |
| `HISTORIES` | un seul élément par groupe (`….history_group` du 2e) ; `known_none = true` avec des termes → `step.data.histories.N.terms` ; `NONE` n'est jamais un terme (`….term_code`) ; absence d'élément pour un groupe = non renseigné (distinct d'« Aucun connu ») ; un élément vide ou avec commentaire seul est admis |
| `CONCLUSION` | `referral = true` sans `referral_urgency` → `step.data.referral_urgency` ; `referral` faux ou null avec niveau ou motifs → **refus** (`step.data.referral_urgency`, `step.data.referral_reasons`) ; aucun motif n'est exigé à l'enregistrement |

- **Audit** `consultation.step_saved` : **uniquement** `{step, version, rows}` (`rows` = nombre de lignes des
  tables enfants de la rubrique : motifs ; éléments + termes ; motifs d'orientation). Jamais une valeur
  clinique, jamais un motif. Journal des changements : `consultations`, `CLINICAL`, `UPDATED`. Résultat
  idempotent : `{consultation_id, version, status, step}` (références seulement). Pas d'outbox.
- **Verrous** : cabinet → idempotence → consultation (rang 47).

## Lecture (`GET /consultations/{id}`)

Relit les tables vivantes au format `AllSteps` (six rubriques, ordre du dossier). Les rubriques pas encore
ouvertes sont rendues **vides** conformes au schéma : `acuities: []`, `measurements: []`,
`refraction_observations: null`, `ametropias: []`, `centering_id: null` et mesures null (`centering_id` est
**nullable** au contrat : une consultation sans centrage est représentable). Si des lignes existent déjà dans
ces tables (insertion hors chemin), elles sont lues telles quelles (mesures en forme canonique `"0.00"`, `"-0.50"`).
Listes dans l'ordre d'affichage du référentiel (`clinical_terms.sort_order`), puis identifiant ; antécédents dans
l'ordre oculaire, médical, familial. `revisions` : métadonnées seulement (aucun snapshot) ; vide avant C03.

**Audit de lecture** : `consultation.viewed` (cible `consultations`, aucun contenu, aucun motif), écrit par le
contrôleur via `AuditRecorder` (hors chemin des commandes) — **au plus un événement par couple compte /
consultation et par intervalle de 10 minutes** (`ClinicalReadAudit::INTERVAL_MINUTES`). Choix : un écran qui
relit la consultation à chaque rubrique enregistrée ne doit pas noyer le journal, et dix minutes répondent à
« qui a ouvert ce dossier et quand » à un quart d'heure près. Les écritures et les refus (403, 404) ne comptent
pas comme lectures. Si l'écriture de l'audit échoue, la lecture échoue : un dossier ne se lit pas sans trace.

## Visite : consultation de la visite

La ressource `Visit` (file du jour, enregistrement d'une arrivée) porte `consultation_id` et
`consultation_status` (`DRAFT`, `IN_PROGRESS`, `COMPLETED`), **rendus seulement avec `clinical.read`**, null
sinon (accueil et caisse ne peuvent pas déduire qu'un examen est en cours), et null quand l'examen n'est pas
démarré. L'écran s'en sert pour « Démarrer » (`consultation_id` null et visite d'examen arrivée) ou « Reprendre ».

## Erreur 409 `STATE_TRANSITION_REFUSED`

Nouveau code du catalogue (`contracts/schemas/error.json`, `BusinessError::stateRefused`) : l'état actuel de la
ressource ne permet pas l'opération. `details.current_status` (valeur simple, jamais clinique) : statut de la
**visite** au démarrage, de la **consultation** à l'enregistrement. Utilisé pour : consultation déjà démarrée,
visite non démarrable, consultation terminée modifiée.

## Décisions, écarts, points ouverts

| Point | Décision / état |
| --- | --- |
| Contrôle du paiement de l'examen | Inactif, dit explicitement (`NOT_CONTROLLED`) ; à lever avec B04 et B06 (nouvelle implémentation de `ExamFeeControl`, refus `EXAM_PAYMENT_REQUIRED` côté serveur). Les tests AT05 et AT06 (examen non payé bloqué, dérogation) ne sont **pas** prouvés. À dire à la cliente. |
| Preuve fournie au démarrage | Refusée (422) tant que le contrôle n'existe pas. Le contrat l'admet (schéma facultatif), le serveur non. |
| `clinical.read` sur `POST` du démarrage | Ajouté (la réponse relit la consultation, y compris au rejeu) ; la liste de décisions ne le demandait que pour `PATCH`. |
| Motif de date rétrospective | Facultatif et conservé ; aucun seuil inventé. À confirmer avec la cliente. |
| Patient archivé ou fusionné après l'arrivée | Non contrôlé au démarrage (aucune commande d'archivage ou de fusion n'existe encore) ; à ajouter avec elles. |
| Annulation / clôture d'une visite | Aucune commande au catalogue (G5) : une visite reste `IN_CONSULTATION` tant que C03 ne la clôt pas. |
| Rubriques mesurées | Fermées (422) : C02. Échelles d'acuité (`visual_acuity_scales`) : aucune ligne, aucun seed (G11). |
| `centering_id` nullable | Fait (G6). Pour C02 : un centrage sans ligne enregistrée a un identifiant null ; l'écran en fournit un à la première saisie. |
| Un compte gérante qui démarre | Devient le professionnel de la consultation (`clinician_id` = compte connecté). |
| Poste hors-ligne | `StartConsultation` et `SaveClinicalStep` refusées (403) pour toute source autre que la route directe, jusqu'au coffre et aux compartiments (O01 à O05, fichier 19). |
| Version de la consultation | Couvre toute la consultation (pas par rubrique) : deux postes sur deux rubriques différentes se heurtent ; l'écran rebase sa saisie sur la version actuelle (`details.current_version`). |
| Valeurs du référentiel | Seuls les codes **actifs** de `clinical_terms` sont admis ; un code désactivé reste lisible dans les consultations déjà saisies. Aucune règle d'exclusivité entre amétropies ou motifs n'est inventée. |
| Dernière lecture avant le commit | Les réponses relisent après le commit : en cas d'écriture concurrente, la version rendue peut être plus récente que celle produite par l'appel (l'écran la reprend comme version attendue). |

## Tests

PHPUnit sur MySQL réel : `tests/Feature/Clinical/` — `StartConsultationTest` (effets, motifs, états, rejeu,
conflit de contenu, droits par rôle, module fermé, isolation des cabinets, retour arrière à chacun des 7 points
du chemin, ordre des verrous, confidentialité des effets de bord), `SaveClinicalStepTest` (version, états,
rubriques non ouvertes, identifiants du chemin et du corps, rejeu, droits, retour arrière, audit), 
`SaveAnamnesisStepTest`, `SaveHistoriesStepTest`, `SaveConclusionStepTest` (une règle par test, remplacement
complet, comparaison par identifiant, collisions d'identifiants), `GetConsultationTest` (contrat, rubriques
vides, lignes existantes, droits, cabinets, audit de lecture et son intervalle) ; règles pures
`tests/Unit/Clinical/NarrativeRulesTest.php` ; concurrence réelle entre connexions MySQL
`tests/Concurrency/CommandBusConcurrencyTest.php` (AT19 : deux enregistrements de la même version → un seul
succès ; double clic ; deux démarrages de la même visite → une consultation). Contrats : fixtures
`start-consultation.no-proof.valid`, `start-consultation.bad-invoice-id.invalid`,
`save-clinical-step.{histories,conclusion,centering-no-id}.valid`. Piège de test : figer l'horloge
(`CarbonImmutable::setTestNow`) **avant** de créer les comptes (voir `tests/Support/ClinicalFixtures.php`).
