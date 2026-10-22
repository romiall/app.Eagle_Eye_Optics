"""Contrat OpenAPI 3.1 de l'API privée et des routes publiques (F06).

Source unique de contracts/openapi.yaml : routes du fichier 08, compléments du contrat 23, protocole du
fichier 19, routes F05 implémentées et routes nécessaires aux tâches du backlog (catalogue, imports,
statistiques, audit). Chaque opération porte :
  - x-permission : code(s) de permission seedé(s) exigé(s) (l'un suffit), vérifiés côté serveur ;
  - x-reauthentication : réauthentification récente exigée ;
  - x-idempotency : required (en-tête Idempotency-Key) ou none ;
  - x-offline-command : commande du journal local équivalente (contracts/commands/index.json) ;
  - x-read-scope : rubriques renvoyées selon le profil ;
  - x-implementation-status : IMPLEMENTED (code et tests présents) ou PLANNED ;
  - x-backlog-task : tâche du fichier 24 qui implémente l'opération.
Les corps de requête et de réponse sont des $ref vers les JSON Schema de contracts/ (une seule définition,
validée en PHP et en TypeScript).

Le YAML est écrit par un émetteur minimal et déterministe (chaînes entre guillemets JSON, valides en
YAML 1.2), sans dépendance : la CI vérifie le fichier par --check.

  python3 contracts/tools/build_openapi.py          # écrit contracts/openapi.yaml
  python3 contracts/tools/build_openapi.py --check  # échoue si le fichier est absent ou périmé (CI)
"""
from pathlib import Path
import json
import re
import sys

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'contracts' / 'openapi.yaml'
COMMANDS = json.loads((ROOT / 'contracts' / 'commands' / 'index.json').read_text(encoding='utf-8'))['const']

ACCESS = './schemas/resources/access.json#/$defs/'
PATIENTS = './schemas/resources/patients.json#/$defs/'
CLINICAL = './schemas/resources/clinical.json#/$defs/'
BILLING = './schemas/resources/billing.json#/$defs/'
STOCK = './schemas/resources/stock.json#/$defs/'
SCHEDULING = './schemas/resources/scheduling.json#/$defs/'
OPERATIONS = './schemas/resources/operations.json#/$defs/'
PUBLIC = './schemas/resources/public.json#/$defs/'
REQUESTS = './schemas/requests/http.json#/$defs/'


def command_body(name):
    return './commands/' + name + '.v1.json#/$defs/DirectRequest'


def op(method, path, operation_id, summary, tag, *, permission=None, reauth=False, idempotent=None, command=None,
       request=None, request_type='application/json', response=None, status=200, read_scope=None, implemented=False,
       task=None, params=(), errors=(), security='session', description=None):
    return {
        'method': method, 'path': path, 'operation_id': operation_id, 'summary': summary, 'tag': tag,
        'permission': permission, 'reauth': reauth,
        'idempotent': idempotent if idempotent is not None else (method in ('post', 'patch') and security == 'session'),
        'command': command, 'request': request if request is not None else (command_body(command) if command else None),
        'request_type': request_type, 'response': response, 'status': status, 'read_scope': read_scope,
        'implemented': implemented, 'task': task, 'params': params, 'errors': errors, 'security': security,
        'description': description,
    }


ID = ('path', 'id', 'Identifiant ULID de la ressource.')
PAGE = ('query', 'page', 'Page (pagination, 1 par défaut).')

OPERATIONS_TABLE = [
    # --- Session (F05, implémenté) -------------------------------------------------------------------
    op('get', '/sanctum/csrf-cookie', 'GetCsrfCookie', 'Initialiser la session et le jeton CSRF (cookie XSRF-TOKEN)', 'Session',
       status=204, implemented=True, task='F05', security='none', idempotent=False,
       description='Route de Sanctum : pose le cookie de session du sous-domaine et XSRF-TOKEN, renvoyé ensuite dans X-XSRF-TOKEN.'),
    op('post', '/v1/auth/login', 'Login', 'Se connecter dans le cabinet configuré', 'Session',
       request=REQUESTS + 'LoginRequest', response=ACCESS + 'MeResponse', implemented=True, task='F05', security='csrf',
       idempotent=False, errors=(400, 422, 429, 503),
       description='Réponse identique pour tout échec (422) ; limites par adresse et par identifiant (429) ; session régénérée.'),
    op('post', '/v1/auth/logout', 'Logout', 'Se déconnecter (session détruite, jeton CSRF renouvelé)', 'Session',
       status=204, implemented=True, task='F05', idempotent=False),
    op('get', '/v1/auth/me', 'GetMe', 'Compte connecté, profils, permissions effectives et cabinet', 'Session',
       response=ACCESS + 'MeResponse', implemented=True, task='F05'),
    op('post', '/v1/auth/reauthenticate', 'Reauthenticate', 'Confirmer son mot de passe pour une opération sensible', 'Session',
       request=REQUESTS + 'ReauthenticateRequest', response=ACCESS + 'ReauthenticationResponse', implemented=True, task='F05',
       idempotent=False, errors=(422, 429)),
    op('post', '/v1/auth/password', 'ChangeOwnPassword', 'Changer son mot de passe (session courante conservée)', 'Session',
       request=REQUESTS + 'ChangePasswordRequest', status=204, implemented=True, task='F05', idempotent=False, errors=(409, 422, 429)),

    # --- Cabinet et comptes (F05, implémenté) --------------------------------------------------------
    op('get', '/v1/clinic/settings', 'GetClinicSettings', 'Paramètres administratifs du cabinet', 'Cabinet et comptes',
       permission=['users.manage'], response=ACCESS + 'ClinicSettingsResponse', implemented=True, task='F05'),
    op('patch', '/v1/clinic/settings', 'UpdateClinicSettings', 'Modifier les paramètres administratifs du cabinet', 'Cabinet et comptes',
       permission=['users.manage'], reauth=True, idempotent=False, request=REQUESTS + 'UpdateClinicSettingsRequest',
       response=ACCESS + 'ClinicSettingsResponse', implemented=True, task='F05', errors=(422,),
       description='Idempotence par clé reportée au chemin central F07 ; fuseau, monnaie et production_documents_enabled refusés.'),
    op('get', '/v1/clinic/document-identity', 'GetClinicDocumentIdentity', 'Identité du cabinet imprimée sur les documents', 'Cabinet et comptes',
       permission=['invoice.read', 'invoice.create', 'invoice.issue', 'payment.collect', 'cash.own_session', 'cash.manage',
                   'credit_note.issue', 'refund.execute', 'finance.global.read', 'clinical.read', 'clinical.export'],
       response=ACCESS + 'ClinicDocumentIdentityResponse', implemented=True, task='D02',
       description='En-tête et pied des documents (nom, coordonnées, identifiant fiscal, mentions professionnelles, réglages des documents, documents de test ou non) : lisible par tout profil qui produit un document (l\'un des droits suffit), pas seulement par la gérante. Ne dépend d\'aucun module. Les paramètres administratifs complets restent sur /clinic/settings (gérante).'),
    op('get', '/v1/staff', 'ListStaff', 'Comptes du personnel du cabinet', 'Cabinet et comptes',
       permission=['users.manage'], response=ACCESS + 'StaffMemberPage', implemented=True, task='F05', params=(PAGE,)),
    op('post', '/v1/staff', 'CreateStaffMember', 'Créer un compte nominatif', 'Cabinet et comptes',
       permission=['users.manage'], reauth=True, idempotent=False, request=REQUESTS + 'CreateStaffRequest',
       response=ACCESS + 'StaffMemberResponse', status=201, implemented=True, task='F05', errors=(422,)),
    op('get', '/v1/staff/{id}', 'GetStaffMember', 'Compte du personnel', 'Cabinet et comptes',
       permission=['users.manage'], response=ACCESS + 'StaffMemberResponse', implemented=True, task='F05', params=(ID,)),
    op('patch', '/v1/staff/{id}', 'UpdateStaffMember', 'Modifier nom, courriel, profils ou activation', 'Cabinet et comptes',
       permission=['users.manage'], reauth=True, idempotent=False, request=REQUESTS + 'UpdateStaffRequest',
       response=ACCESS + 'StaffMemberResponse', implemented=True, task='F05', params=(ID,), errors=(409, 422)),
    op('post', '/v1/staff/{id}/permissions', 'SetStaffPermissionOverride', 'Dérogation nominative datée et motivée', 'Cabinet et comptes',
       permission=['users.manage'], reauth=True, idempotent=False, request=REQUESTS + 'PermissionOverrideRequest',
       response=ACCESS + 'StaffMemberResponse', implemented=True, task='F05', params=(ID,), errors=(409, 422)),
    op('post', '/v1/staff/{id}/password', 'ResetStaffPassword', 'Réinitialiser le mot de passe d\'un compte (hors gérante)', 'Cabinet et comptes',
       permission=['users.manage'], reauth=True, idempotent=False, request=REQUESTS + 'ResetPasswordRequest', status=204,
       implemented=True, task='F05', params=(ID,), errors=(409, 422)),

    # --- Patients et visites -------------------------------------------------------------------------
    op('get', '/v1/patients', 'SearchPatients', 'Rechercher des patients (identité administrative)', 'Patients',
       permission=['patient.read'], response=PATIENTS + 'PatientPage', implemented=True, task='P02', errors=(404, 422, 429),
       params=(('query', 'query', 'Nom, numéro de dossier ou téléphone (recherche normalisée, 2 caractères au moins).'), PAGE)),
    op('post', '/v1/patients', 'CreatePatient', 'Créer un patient (numéro central)', 'Patients',
       permission=['patient.create', 'patient.read'], command='CreatePatient', response=PATIENTS + 'PatientResponse', status=201,
       implemented=True, task='P01', errors=(404, 409, 422),
       description='Rend la fiche créée : exige aussi patient.read. Module patients fermé : 404.'),
    op('get', '/v1/patients/{id}', 'GetPatient', 'Dossier administratif', 'Patients',
       permission=['patient.read'], response=PATIENTS + 'PatientResponse', implemented=True, task='P01', params=(ID,), errors=(404,)),
    op('patch', '/v1/patients/{id}', 'UpdatePatient', 'Modifier l\'identité (version attendue)', 'Patients',
       permission=['patient.update', 'patient.read'], command='UpdatePatient', response=PATIENTS + 'PatientResponse', implemented=True,
       task='P01', params=(ID,), errors=(404, 409, 422),
       description='Rend la fiche modifiée : exige aussi patient.read.'),
    op('get', '/v1/patients/{id}/history', 'GetPatientHistory', 'Historique du patient filtré par profil', 'Patients',
       permission=['patient.read'], response=PATIENTS + 'PatientHistoryResponse', task='P02', params=(ID,),
       read_scope={'ADMINISTRATIVE': 'visits', 'CLINICAL': 'consultations (clinical.read)', 'FINANCIAL': 'invoices (invoice.read)'}),
    op('get', '/v1/patients/{id}/activity', 'GetPatientActivity', 'Activité du patient (visites, examens, ordonnances, pièces)', 'Patients',
       permission=['patient.read'], response=PATIENTS + 'PatientActivity', implemented=True, task='R02', errors=(404, 422),
       params=(ID, ('query', 'section', 'Filtre facultatif : VISITS, EXAMS, PRESCRIPTIONS ou FINANCIAL (section visible du compte, 403 sinon).'), PAGE),
       read_scope={'ADMINISTRATIVE': 'VISITS (patient.read)', 'CLINICAL': 'EXAMS, PRESCRIPTIONS (clinical.read, module consultations)',
                   'FINANCIAL': 'FINANCIAL (invoice.read, module sales)'},
       description='Module patients. Lecture seule, du plus récent au plus ancien, 25 éléments par page. Une section non autorisée est absente : ni ses éléments, ni son compte (meta.sections), ni sa mention. Aucune valeur clinique : identifiants, dates, états, numéros. Pièces : factures émises (solde projeté, jamais « payé » stocké), reçus, avoirs émis, remboursements ; brouillons et pièces abandonnées exclus. screen_path : adresse de l\'écran qui ouvre l\'élément, seulement si le compte peut l\'ouvrir.'),
    op('post', '/v1/visits', 'RegisterVisit', 'Enregistrer une arrivée', 'Patients',
       permission=['visit.register'], command='RegisterVisit', response=PATIENTS + 'VisitResponse', status=201, implemented=True,
       task='P03', errors=(409, 422)),
    op('get', '/v1/visits', 'ListVisits', 'Visites d\'une journée', 'Patients',
       permission=['visit.register', 'agenda.read'], response=PATIENTS + 'VisitPage', implemented=True, task='P03', errors=(422,),
       description="Lecture ouverte à qui détient l’une OU l’autre des permissions listées (middleware any-permission).",
       params=(('query', 'date', 'Date civile AAAA-MM-JJ (fuseau du cabinet).'), PAGE)),

    # --- Clinique ------------------------------------------------------------------------------------
    op('post', '/v1/visits/{id}/consultations', 'StartConsultation', 'Démarrer l\'examen', 'Clinique',
       permission=['clinical.write', 'clinical.read'], command='StartConsultation', response=CLINICAL + 'ConsultationResponse', status=201,
       implemented=True, task='P03', params=(ID,), errors=(404, 409, 422),
       description='Rend la consultation (relue, même au rejeu de la clé), donc exige aussi clinical.read. Module consultations. Paiement de l\'examen : contrôle inactif par défaut (EEO_EXAM_FEE_CONTROL=off : NOT_CONTROLLED dans l\'audit, exam_invoice_id et exam_waiver_approval_id refusés en 422 s\'ils sont fournis) ; contrôle actif (billed) : la facture de frais de CETTE visite réglée, ou la dispense exacte de la gérante (désignée, ou retrouvée par son contenu exact quand exam_waiver_approval_id est null) consommée dans la transaction, sinon 422 EXAM_PAYMENT_REQUIRED ; dispense désignée invalide, échue ou déjà utilisée : 422 APPROVAL_REQUIRED_OR_EXPIRED. Visite EXAM ou FOLLOW_UP au statut ARRIVED ou READY_FOR_EXAM (contrôle actif : aussi AWAITING_EXAM_FEE, qui reçoit alors 422 EXAM_PAYMENT_REQUIRED tant que rien ne prouve le règlement), une seule consultation par visite (409 STATE_TRANSITION_REFUSED sinon). L\'identifiant de la visite du chemin doit être celui du corps (422).'),
    op('get', '/v1/consultations', 'ListConsultations', 'Examens du cabinet (liste sans valeur clinique)', 'Clinique',
       permission=['clinical.read'], response=CLINICAL + 'ConsultationSummaryPage', implemented=True, task='C01', errors=(404, 422, 429),
       params=(('query', 'date', 'Jour civil AAAA-MM-JJ (fuseau du cabinet) de la date effective ; absent : tous les jours.'),
               ('query', 'status', 'DRAFT, IN_PROGRESS ou COMPLETED.'),
               ('query', 'mine', 'true : seulement les examens du professionnel connecté.'), PAGE),
       description='Module consultations. 50 examens par page, du plus récent au plus ancien (date effective). Patient (identité administrative de base), état, dates, révision et professionnel : aucune valeur clinique, aucune lecture tracée (le dossier s\'ouvre par GET /consultations/{id}, tracé). Même limite de débit par compte que les lectures de dossier (429 RATE_LIMITED).'),
    op('get', '/v1/consultations/{id}', 'GetConsultation', 'Consultation complète', 'Clinique',
       permission=['clinical.read'], response=CLINICAL + 'ConsultationResponse', implemented=True, task='C01', params=(ID,), errors=(404, 429),
       description='Module consultations. Relit les tables vivantes au format AllSteps (sept rubriques) dans un instantané cohérent ; une rubrique sans saisie est rendue vide. patient : identité administrative de base. La lecture est tracée dans l\'audit (consultation.viewed), sans contenu, au plus une fois par compte et par consultation toutes les 10 minutes ; il en va de même des réponses des écritures (elles rendent le dossier). Débit limité par compte (429 RATE_LIMITED).'),
    op('patch', '/v1/consultations/{id}/steps/{step}', 'SaveClinicalStep', 'Saisir une rubrique du dossier', 'Clinique',
       permission=['clinical.write', 'clinical.read'], command='SaveClinicalStep', response=CLINICAL + 'ConsultationResponse', implemented=True, task='C01',
       params=(ID, ('path', 'step', 'Rubrique : ANAMNESIS, HISTORIES, VISUAL_ACUITY, REFRACTION, CENTERING, MEDICATIONS, CONCLUSION.')), errors=(404, 409, 422),
       description='Rend la consultation, donc exige aussi clinical.read. Module consultations. Remplacement complet de la rubrique, version attendue (409 VERSION_CONFLICT). Les sept rubriques sont ouvertes (C01, C02, C04). Consultation terminée : 409 STATE_TRANSITION_REFUSED (la correction passe par AmendConsultation). Les identifiants du chemin (consultation, rubrique) doivent être ceux du corps (422).'),
    op('post', '/v1/consultations/{id}/complete', 'CompleteConsultation', 'Finaliser (révision 1 immuable)', 'Clinique',
       permission=['clinical.complete', 'clinical.read'], command='CompleteConsultation', response=CLINICAL + 'ConsultationResponse', implemented=True, task='C03', params=(ID,), errors=(404, 409, 422),
       description='Rend la consultation, donc exige aussi clinical.read. Module consultations. Snapshot complet et révision 1 dans la même transaction ; la visite passe à COMPLETED. snapshot_sha256 : SHA-256 de JCS(steps) tel que relu par le poste, que le serveur recalcule ; l\'empreinte de la révision (clinical_revisions.sha256) est celle de JCS(snapshot), calculée par le serveur. Consultation déjà terminée ou brouillon : 409 STATE_TRANSITION_REFUSED.'),
    op('post', '/v1/consultations/{id}/amendments', 'AmendConsultation', 'Corriger avec motif (nouvelle révision)', 'Clinique',
       permission=['clinical.amend', 'clinical.read'], command='AmendConsultation', response=CLINICAL + 'ConsultationResponse', status=201, implemented=True, task='C03', params=(ID,), errors=(404, 409, 422),
       description='Rend la consultation, donc exige aussi clinical.read. Module consultations. Nouvelle révision immuable (motif, auteur), révisions antérieures intactes ; chaque rubrique envoyée remplace la rubrique actuelle. snapshot_sha256 : SHA-256 de JCS(steps) de la requête, recalculé par le serveur.'),
    op('get', '/v1/consultations/{id}/revisions/{revision_no}', 'GetClinicalRevision', 'Révision figée d\'une consultation', 'Clinique',
       permission=['clinical.read'], response=CLINICAL + 'ClinicalRevisionResponse', implemented=True, task='C03',
       params=(ID, ('path', 'revision_no', 'Numéro de la révision (entier ≥ 1).')), errors=(404, 429),
       description='Snapshot complet immuable tel qu\'enregistré. Module consultations. Lecture tracée (consultation.revision_viewed), sans contenu, au plus une fois par compte et par révision toutes les 10 minutes.'),
    op('get', '/v1/consultations/{id}/prescriptions', 'ListConsultationPrescriptions', 'Prescriptions optiques de la consultation (toutes versions)', 'Clinique',
       permission=['clinical.read'], response=CLINICAL + 'PrescriptionList', implemented=True, task='C04', params=(ID,), errors=(404,),
       description='Module consultations. Toutes les versions, de la plus récente à la plus ancienne.'),
    op('post', '/v1/consultations/{id}/prescriptions', 'CreatePrescription', 'Prescription interne de la consultation', 'Clinique',
       permission=['clinical.write', 'clinical.read'], command='CreatePrescription', response=CLINICAL + 'PrescriptionResponse', status=201, implemented=True, task='C04', params=(ID,), errors=(404, 409, 422),
       description='Crée une prescription BROUILLON (version 1 d\'une nouvelle série). Module consultations. Rend la prescription : exige aussi clinical.read. Une consultation n\'a qu\'une série de prescriptions : les versions suivantes passent par /prescriptions/{id}/revisions. Rien n\'est recopié depuis la réfraction sans choix explicite du professionnel (l\'écran envoie les valeurs choisies).'),
    op('post', '/v1/prescriptions/external', 'CreateExternalPrescription', 'Transcrire une ordonnance externe identifiée (A13)', 'Clinique',
       permission=['clinical.write', 'clinical.read'], command='CreatePrescription', response=CLINICAL + 'PrescriptionResponse', status=201, implemented=True, task='C04', errors=(404, 409, 422),
       description='Même commande que la prescription interne, origin = EXTERNAL (prescripteur et date d\'origine obligatoires, aucune consultation fictive). Module consultations.'),
    op('get', '/v1/prescriptions/{id}', 'GetPrescription', 'Prescription versionnée', 'Clinique',
       permission=['clinical.read'], response=CLINICAL + 'PrescriptionResponse', implemented=True, task='C04', params=(ID,), errors=(404,),
       description='Module consultations.'),
    op('patch', '/v1/prescriptions/{id}', 'UpdatePrescriptionDraft', 'Modifier une prescription encore brouillon (en ligne)', 'Clinique',
       permission=['clinical.write', 'clinical.read'], request=REQUESTS + 'UpdatePrescriptionDraftRequest', response=CLINICAL + 'PrescriptionResponse',
       implemented=True, task='C04', params=(ID,), errors=(404, 409, 422),
       description='Brouillon seulement (une prescription validée est immuable : 409 STATE_TRANSITION_REFUSED) ; jeton de concurrence expected_updated_at (409 VERSION_CONFLICT). Module consultations.'),
    op('post', '/v1/prescriptions/{id}/revisions', 'RevisePrescription', 'Nouvelle version d\'une prescription (supersedes_id)', 'Clinique',
       permission=['clinical.amend', 'clinical.read'], command='CreatePrescription', response=CLINICAL + 'PrescriptionResponse', status=201, implemented=True, task='C04', params=(ID,), errors=(404, 409, 422),
       description='Nouvelle version BROUILLON de la dernière version validée de la série (supersedes_id = {id}). Module consultations.'),
    op('post', '/v1/prescriptions/{id}/validate', 'ValidatePrescription', 'Valider la prescription (version immuable)', 'Clinique',
       permission=['clinical.complete', 'clinical.read'], command='ValidatePrescription', response=CLINICAL + 'PrescriptionResponse', implemented=True, task='C04', params=(ID,), errors=(404, 409, 422),
       description='Validation explicite : version immuable et document PDF préparé (generated_documents PENDING, génération par le moteur de documents). Aucune vente ni mouvement de stock n\'est créé. Module consultations.'),
    op('get', '/v1/visual-acuity-scales', 'ListVisualAcuityScales', 'Échelles d\'acuité du cabinet', 'Clinique',
       permission=['clinical.read'], response=CLINICAL + 'VisualAcuityScaleList', implemented=True, task='C02',
       description='Échelles configurées par le cabinet (module consultations). Aucune conversion entre échelles n\'est fournie ni présumée. Tant que la cliente n\'a pas validé ses échelles (G11, Q06), seules des échelles de DÉMONSTRATION fictives existent.'),

    # --- Catalogue -----------------------------------------------------------------------------------
    op('get', '/v1/catalog', 'SearchCatalog', 'Rechercher le catalogue (prix de vente)', 'Catalogue',
       permission=['catalog.read'], response=BILLING + 'CatalogPage', implemented=True, task='B01', errors=(404, 422),
       params=(('query', 'query', 'Référence, nom, marque ou modèle (2 caractères au moins). Sans texte : les articles actifs.'),
               ('query', 'include_inactive', 'true pour inclure les articles désactivés (réservé à qui détient catalog.manage).'), PAGE),
       read_scope={'FINANCIAL': 'sale_price', 'MANAGER': 'purchase_cost (catalog.purchase_cost.read)'},
       description='Module sales. 25 articles par page, ordre du nom puis de la référence. purchase_cost est ABSENT de la réponse (jamais null) sans catalog.purchase_cost.read. Articles stockés, services et sur mesure sont distincts (stock_mode) ; une unité « paire » n\'est jamais convertie en deux unités.'),
    op('post', '/v1/catalog/items', 'CreateCatalogItem', 'Créer un article', 'Catalogue',
       permission=['catalog.manage'], request=REQUESTS + 'CatalogItemRequest', response=BILLING + 'CatalogItemResponse', status=201, implemented=True, task='B01', errors=(404, 409, 422),
       description='Module sales, gérante. Référence unique par cabinet. Aucun prix réel n\'est fourni par l\'application : seules des données fictives existent en développement. L\'audit garde le prix de vente, jamais le coût d\'achat.'),
    op('patch', '/v1/catalog/items/{id}', 'UpdateCatalogItem', 'Modifier un article (version attendue)', 'Catalogue',
       permission=['catalog.manage'], request=REQUESTS + 'CatalogItemRequest', response=BILLING + 'CatalogItemResponse', implemented=True, task='B01', params=(ID,), errors=(404, 409, 422),
       description='Les champs de la requête remplacent ceux de l\'article ; version attendue = catalog_items.version (409 VERSION_CONFLICT avec current_version). Un changement de prix ne touche jamais une facture émise. Une demande sans effet ne change pas la version.'),
    op('get', '/v1/tax-rules', 'ListTaxRules', 'Règles fiscales saisies par la gérante', 'Catalogue',
       permission=['catalog.manage'], response=BILLING + 'TaxRuleList', implemented=True, task='B01', errors=(404,),
       description='Module sales. Aucune règle n\'est préconfigurée ; une règle n\'est appliquée aux documents que si elle est active et validée.'),
    op('post', '/v1/tax-rules', 'CreateTaxRule', 'Saisir une règle fiscale (aucune taxe préconfigurée)', 'Catalogue',
       permission=['catalog.manage'], reauth=True, request=REQUESTS + 'TaxRuleRequest', response=BILLING + 'TaxRuleResponse', status=201, implemented=True, task='B01', errors=(404, 409, 422),
       description='Module sales, gérante réauthentifiée. Une règle active exige un taux et sa validation (validate = true).'),
    op('patch', '/v1/tax-rules/{id}', 'UpdateTaxRule', 'Modifier ou valider une règle fiscale', 'Catalogue',
       permission=['catalog.manage'], reauth=True, request=REQUESTS + 'TaxRuleRequest', response=BILLING + 'TaxRuleResponse', implemented=True, task='B01', params=(ID,), errors=(404, 409, 422),
       description='Version attendue = tax_rules.version. Changer le taux retire la validation : une règle active doit être revalidée dans la même demande.'),

    # --- Ventes --------------------------------------------------------------------------------------
    op('post', '/v1/invoices', 'CreateInvoiceDraft', 'Créer un brouillon de facture', 'Ventes',
       permission=['invoice.create', 'exam_fee.collect'], command='CreateInvoiceDraft', response=BILLING + 'InvoiceResponse', status=201, implemented=True, task='B02', errors=(404, 409, 422),
       description='Module sales. exam_fee.collect limite le brouillon à la prestation d\'examen fixe (fichier 09). Prix = prix du catalogue, remise plafonnée par le droit de l\'auteur : ce qui le dépasse est refusé en 422 APPROVAL_REQUIRED_OR_EXPIRED champ par champ (lines.N.unit_price_gross, lines.N.discount_gross, lines.N.catalog_item_id). Le brouillon n\'a aucun effet sur le stock. Corps direct : expected_version null et présent.'),
    op('get', '/v1/invoices', 'ListInvoices', 'Factures par statut, patient ou jour d\'émission', 'Ventes',
       permission=['invoice.read'], response=BILLING + 'InvoicePage', implemented=True, task='B02', errors=(404, 422),
       params=(('query', 'status', 'DRAFT, ISSUED ou ABANDONED.'), ('query', 'patient_id', 'Filtre patient.'),
               ('query', 'issued_on', 'Jour civil d\'émission AAAA-MM-JJ au fuseau du cabinet (seules les factures émises ce jour-là).'), PAGE),
       description='Module sales. 25 factures par page, les plus récentes d\'abord, avec leur reste dû et leur ledger_version projetés, et l\'identité de base du patient (numéro de dossier, nom, prénoms). issued_on illisible : 422.'),
    op('get', '/v1/invoices/{id}', 'GetInvoice', 'Facture, lignes, encaissements et solde projeté', 'Ventes',
       permission=['invoice.read'], response=BILLING + 'InvoiceResponse', implemented=True, task='B02', params=(ID,), errors=(404,),
       description='Module sales. Les snapshots (patient, cabinet, taxe) d\'une facture émise sont ceux figés à l\'émission : c\'est d\'eux, jamais de l\'identité ni des prix actuels, que le document est produit.'),
    op('patch', '/v1/invoices/{id}', 'UpdateDraft', 'Modifier le brouillon (version brouillon)', 'Ventes',
       permission=['invoice.create', 'exam_fee.collect'], command='UpdateDraft', response=BILLING + 'InvoiceResponse', implemented=True, task='B02', params=(ID,), errors=(404, 409, 422),
       description='Module sales. Version attendue = draft_version (409 VERSION_CONFLICT avec current_version : l\'écran conserve la saisie). La liste de lignes remplace la précédente. Créateur ou gérante seulement. Facture émise ou abandonnée : 409 STATE_TRANSITION_REFUSED. L\'identifiant du corps doit être celui de l\'adresse.'),
    op('post', '/v1/invoices/{id}/issue', 'IssueInvoice', 'Émettre (numéro, snapshots, réserves, en transaction)', 'Ventes',
       permission=['invoice.issue', 'exam_fee.collect'], command='IssueInvoice', response=BILLING + 'InvoiceResponse', implemented=True, task='B03', params=(ID,), errors=(404, 409, 422),
       description='Module sales. Émission immuable : numéro central, snapshots, réserve du stock des articles stockés et document PENDING dans une transaction ; toute erreur annule tout, numéro compris. Par la route directe : write_owner_id null, reservations vide (le serveur choisit positions et lots), total_gross recalculé et vérifié (422 sinon), issued_at plausible (la date de la pièce est celle du serveur). Stock insuffisant : 409 STOCK_UNAVAILABLE (details.available) ; lot expiré : 422 LOT_EXPIRED. Facture détenue par un poste : 409 WRITE_OWNER_REQUIRED.'),
    op('post', '/v1/invoices/{id}/payments', 'RegisterPayment', 'Encaisser (reçu numéroté au commit)', 'Ventes',
       permission=['payment.collect', 'exam_fee.collect'], command='RegisterPayment', response=BILLING + 'PaymentResultResponse', status=201,
       implemented=True, task='B04', params=(ID,), errors=(404, 409, 422),
       description='Module sales. Montant ≤ solde verrouillé (409 PAYMENT_EXCEEDS_BALANCE avec details.amount_due et current_version) ; version attendue = ledger_version (409 VERSION_CONFLICT avec current_version et amount_due). Un reçu numéroté unique par encaissement, créé après le paiement confirmé dans la même transaction ; le PDF suit (document_status PENDING n\'autorise jamais à répéter l\'encaissement). Espèces : session de caisse ouverte de l\'auteur ; autres modes : aucune session ; référence obligatoire pour mobile money et virement. Un jour civil antérieur exige retroactive_reason. Un rejeu de la même clé rend la réponse d\'origine.'),
    op('post', '/v1/invoices/{id}/abandon', 'AbandonInvoiceDraft', 'Abandonner un brouillon de facture', 'Ventes',
       permission=['invoice.create', 'exam_fee.collect'], request=REQUESTS + 'AbandonInvoiceDraftRequest', response=BILLING + 'InvoiceResponse',
       implemented=True, task='B02', params=(ID,), errors=(403, 404, 409, 422),
       description='Module sales, en ligne seulement. Version attendue = draft_version (409 VERSION_CONFLICT). Créateur du brouillon ou gérante (403 sinon). Facture émise ou déjà abandonnée : 409 STATE_TRANSITION_REFUSED (une facture émise se corrige par avoir). Aucun numéro, aucune réserve de stock, aucun document à annuler ; les lignes restent en base. Motif facultatif, conservé dans l\'audit. L\'identifiant du corps doit être celui de l\'adresse.'),
    op('get', '/v1/sales/settings', 'GetSalesSettings', 'Règles de vente du compte connecté (affichage)', 'Ventes',
       permission=['invoice.read', 'invoice.create', 'payment.collect', 'exam_fee.collect'], response=BILLING + 'SalesSettingsResponse',
       implemented=True, task='B04', errors=(404,),
       description='Module sales. Autorité de prix, plafond de remise effectif de CE compte, modes de règlement et modes à référence obligatoire, référence de l\'article des frais d\'examen. Le serveur reste seul juge à chaque commande.'),
    op('get', '/v1/receipts/{id}', 'GetReceipt', 'Reçu d\'un encaissement (pièce immuable, snapshot figé)', 'Ventes',
       permission=['invoice.read'], response=BILLING + 'ReceiptResponse', implemented=True, task='B04', params=(ID,), errors=(404,),
       description='Module sales. Le snapshot du reçu (receipt.json) est la source de son document ; la nature (DEPOSIT ou BALANCE_SETTLEMENT) est calculée, jamais saisie.'),
    op('post', '/v1/payments/{id}/reversals', 'ReversePayment', 'Contre-écriture d\'une erreur comptable', 'Ventes',
       permission=['payment.reverse'], reauth=True, command='ReversePayment', response=BILLING + 'ReversalResultResponse', status=201,
       implemented=True, task='B07', params=(ID,), errors=(404, 409, 422),
       description='Module sales, réauthentification récente. L\'identifiant de l\'adresse est le paiement (payment_id du corps), l\'agrégat sa facture (invoice_id) ; version attendue = ledger_version (409 VERSION_CONFLICT). Le paiement, son reçu et son numéro restent tels qu\'émis : la contre-écriture est une pièce liée, motivée (erreur comptable), cumul ≤ paiement (422, details.reversible), jamais une sortie d\'argent ; refusée si l\'encaissé net deviendrait inférieur aux remboursements versés. Approbation exacte (facture, paiement, montant) consommée une fois, ou exécution directe de la gérante (approval_id inconnu, autorisation écrite consommée sous cet identifiant) ; sinon 422 APPROVAL_REQUIRED_OR_EXPIRED. Paiement en espèces : la session est verrouillée, une session close n\'est jamais réécrite. Facture détenue par un poste : 409 WRITE_OWNER_REQUIRED.'),
    op('post', '/v1/invoices/{id}/credit-notes', 'IssueCreditNote', 'Émettre un avoir lié aux lignes d\'origine', 'Ventes',
       permission=['credit_note.issue'], reauth=True, command='IssueCreditNote', response=BILLING + 'CreditNoteResultResponse', status=201,
       implemented=True, task='B07', params=(ID,), errors=(404, 409, 422),
       description='Module sales, réauthentification récente. Facture émise ; version attendue = ledger_version (409 VERSION_CONFLICT). Lignes d\'origine de CETTE facture, une fois chacune ; cumul des avoirs émis ≤ ligne (montant et quantité indiquée, 422 details.creditable) ; somme des lignes = amount_gross ≤ part non créditée de la facture. Numéro central TEST-AV-AAAA-NNNNNN (number null dans la requête), snapshot figé (régime fiscal de la facture, aucune taxe inventée), document PENDING (credit_note.v1). Ni la facture, ni ses reçus, ni le stock ne changent ; un avoir au-delà du reste dû fait apparaître un crédit à rembourser (IN_CREDIT). Approbation exacte (facture, montant, lignes) ou exécution directe de la gérante ; sinon 422 APPROVAL_REQUIRED_OR_EXPIRED.'),
    op('post', '/v1/invoices/{id}/refunds', 'RefundCredit', 'Rembourser un crédit disponible', 'Ventes',
       permission=['refund.execute'], reauth=True, command='RefundCredit', response=BILLING + 'RefundResultResponse', status=201,
       implemented=True, task='B08', params=(ID,), errors=(404, 409, 422),
       description='Module sales, réauthentification récente. Sortie d\'argent liée à un avoir émis de la facture : montant ≤ crédit à rembourser de la facture (422 details.refundable_credit) et ≤ part de l\'avoir non encore remboursée ; version attendue = ledger_version (409 VERSION_CONFLICT avec refundable_credit). Espèces : session de caisse ouverte de l\'auteur (clôturée : 409) ; autres modes : aucune session, référence obligatoire pour mobile money et virement. Date effective du jour (ni future, ni d\'un jour antérieur). Numéro central TEST-RMB-AAAA-NNNNNN, snapshot figé (bénéficiaire = identité figée de la facture), document PENDING (refund.v1). Approbation exacte (facture, avoir, montant, mode) ou exécution directe de la gérante ; sinon 422 APPROVAL_REQUIRED_OR_EXPIRED. Un rejeu de la même clé rend la réponse d\'origine, jamais un second remboursement.'),
    op('get', '/v1/approvals', 'ListApprovals', 'Demandes d\'approbation (gérante : toutes ; autre compte : les siennes)', 'Approbations',
       permission=['approval.request', 'approval.decide'], response=BILLING + 'ApprovalPage', implemented=True, task='B06', errors=(404, 422),
       params=(('query', 'status', 'Statut effectif : REQUESTED, APPROVED, REFUSED, EXPIRED ou CONSUMED (toutes sans filtre).'), PAGE),
       description='Module settings. 25 par page, les plus récentes d\'abord. Un compte sans approval.decide ne voit que ses propres demandes. subject : contenu exact revérifié contre payload_hash.'),
    op('post', '/v1/approvals', 'RequestApproval', 'Demander une autorisation liée au contenu exact', 'Approbations',
       permission=['approval.request'], command='RequestApproval', response=BILLING + 'ApprovalResponse', status=201, implemented=True,
       task='B06', errors=(404, 409, 422),
       description='Module settings. Le corps porte le contenu exact (subject) ; le serveur en dérive action_code, target_type, target_id et payload_hash. Cible vérifiée dans le cabinet et opération encore possible (422 sur subject.*, 409 STATE_TRANSITION_REFUSED sinon : visite déjà démarrée, frais déjà encaissés, facture non émise…) ; une demande identique en attente ou accordée : 409. Motif obligatoire ; échéance proposée facultative.'),
    op('get', '/v1/approvals/{id}', 'GetApproval', 'Demande d\'approbation', 'Approbations',
       permission=['approval.request', 'approval.decide'], response=BILLING + 'ApprovalResponse', implemented=True, task='B06', params=(ID,), errors=(404,),
       description='Module settings. Une demande d\'un autre compte répond 404 à qui ne détient pas approval.decide.'),
    op('post', '/v1/approvals/{id}/decision', 'DecideApproval', 'Décider une autorisation (gérante réauthentifiée)', 'Approbations',
       permission=['approval.decide'], reauth=True, command='DecideApproval', response=BILLING + 'ApprovalResponse', implemented=True,
       task='B06', params=(ID,), errors=(403, 404, 409, 422),
       description='Module settings, réauthentification récente (423 sinon). Seulement une demande en attente et non échue (409 STATE_TRANSITION_REFUSED, details.current_status) ; jamais par la personne qui l\'a demandée (403) ; la gérante doit détenir aussi la permission que l\'approbation remplace (403). Accord : échéance = la plus proche de celle proposée et de la décision + EEO_APPROVAL_TTL_MINUTES ; refus : motif obligatoire (422). Une dispense d\'examen accordée passe la visite READY_FOR_EXAM.'),

    # --- Caisse --------------------------------------------------------------------------------------
    op('get', '/v1/cash-registers', 'ListCashRegisters', 'Caisses actives du cabinet (caisse principale)', 'Caisse',
       permission=['cash.own_session'], response=BILLING + 'CashRegisterList', implemented=True, task='B05', errors=(404,),
       description='Module sales. Une seule caisse principale au premier jalon (créée par eeo:sales:bootstrap) : aucune route n\'en crée une autre.'),
    op('get', '/v1/cash-sessions/current', 'GetCurrentCashSession', 'Session de caisse ouverte du compte', 'Caisse',
       permission=['cash.own_session'], response=BILLING + 'CurrentCashSessionResponse', implemented=True, task='B05', errors=(404,),
       description='Module sales. data vaut null quand le compte n\'a aucune session ouverte. collected_cash, refunded_cash et theoretical_cash sont des projections recalculées à chaque lecture.'),
    op('post', '/v1/cash-sessions', 'OpenCashSession', 'Ouvrir une session de caisse', 'Caisse',
       permission=['cash.own_session'], command='OpenCashSession', response=BILLING + 'CashSessionResponse', status=201, implemented=True, task='B05', errors=(404, 409, 422),
       description='Module sales. Une session ouverte par caisse et par caissière (409 STATE_TRANSITION_REFUSED, details.current_status OPEN). La caissière est le compte connecté.'),
    op('post', '/v1/cash-sessions/{id}/close', 'CloseCashSession', 'Clôturer (attendu recalculé, écart motivé)', 'Caisse',
       permission=['cash.own_session'], command='CloseCashSession', response=BILLING + 'CashSessionResponse', implemented=True, task='B05', params=(ID,), errors=(404, 409, 422),
       description='Module sales. L\'attendu est recalculé par le serveur : si expected_amount a changé depuis l\'affichage, 409 VERSION_CONFLICT avec details.expected_amount. Un écart non nul exige variance_reason (422). Session close immuable. Une caissière clôture sa session ; la gérante (cash.manage) clôture celle d\'une autre.'),
    op('get', '/v1/cash-sessions', 'ListCashSessions', 'Sessions de caisse du cabinet (gérante)', 'Caisse',
       permission=['cash.manage'], response=BILLING + 'CashSessionPage', implemented=True, task='B05', errors=(404, 422),
       params=(('query', 'state', 'open (ouvertes) ou closed (clôturées) ; sans filtre : ouvertes d\'abord.'), PAGE),
       description='Module sales. Toutes les caisses et caissières, 25 par page, avec les noms ACTUELS de la caissière et de qui a clôturé ; totaux projetés.'),
    op('get', '/v1/cash-sessions/{id}/report', 'GetCashSessionReport', 'Relevé d\'une session de caisse', 'Caisse',
       permission=['cash.own_session'], response=BILLING + 'CashSessionReportResponse', implemented=True, task='B05', params=(ID,), errors=(403, 404),
       description='Module sales. Sa propre session ; la session d\'une autre caissière seulement avec cash.manage (403 sinon). Tiroir espèces, mouvements, encaissements de la session et autres modes de la même caissière pendant la session ; relevé PROVISOIRE tant que la session est ouverte. Source du PDF du relevé, produit sur le poste.'),
    op('get', '/v1/reports/cash-day', 'GetCashDayReport', 'Caisse du jour', 'Caisse',
       permission=['finance.global.read', 'cash.own_session'], response=BILLING + 'CashDayReportResponse', implemented=True, task='R01', errors=(404, 422),
       params=(('query', 'date', 'Jour civil AAAA-MM-JJ au fuseau du cabinet (aujourd\'hui par défaut).'),),
       read_scope={'MANAGER': 'CLINIC (finance.global.read) : tout le cabinet, factures et avoirs émis, reste à encaisser',
                   'CASHIER': 'OWN (cash.own_session) : ses encaissements et ses sessions seulement'},
       description='Module sales. Encaissements nets par mode (vw_collection_events) du jour civil du cabinet et sessions du jour. Lecture EN LIGNE, complète à son instant (generated_at) ; jamais un statut « payé ».'),

    # --- Stock ---------------------------------------------------------------------------------------
    op('get', '/v1/stock/availability', 'GetStockAvailability', 'Disponibilités (physique, réservé, quotas locaux)', 'Stock',
       permission=['stock.read'], response=STOCK + 'StockAvailabilityPage', implemented=True, task='I00', errors=(404, 422),
       params=(('query', 'item_id', 'Filtre article.'), ('query', 'location_id', 'Filtre emplacement.'), PAGE),
       description='Module stock. Disponible central = physique − réservations centrales − quotas locaux non consommés ; aucun coût ni valeur de stock.'),
    op('get', '/v1/stock/locations', 'ListStockLocations', 'Emplacements de stock actifs', 'Stock',
       permission=['stock.read'], response=STOCK + 'StockLocationList', implemented=True, task='I00', errors=(404,),
       description='Module stock. Emplacements actifs du cabinet (PRINCIPAL créé par eeo:sales:bootstrap) ; aucune route n\'en crée au premier jalon.'),
    op('post', '/v1/stock/opening', 'RegisterOpeningStock', 'Stock initial validé', 'Stock',
       permission=['stock.adjust'], reauth=True, request=REQUESTS + 'OpeningStockRequest', response=STOCK + 'OpeningStockResponse', status=201, implemented=True, task='I00', errors=(404, 409, 422),
       description='Module stock, gérante réauthentifiée. Un mouvement OPENING par ligne, positions et lots créés au besoin ; le stock initial d\'un article dans un emplacement ne s\'enregistre qu\'une fois. approval_id doit être null (la gérante accorde l\'autorisation en exécutant elle-même ; les approbations à distance attendent B06). Corps sans expected_version.'),
    op('post', '/v1/stock/movements', 'RegisterStockMovement', 'Mouvement hors vente motivé et approuvé', 'Stock',
       permission=['stock.adjust'], command='RegisterStockMovement', response=STOCK + 'StockMovementResponse', status=201, task='I02', errors=(409, 422)),
    op('post', '/v1/invoices/{id}/deliveries', 'ConfirmDelivery', 'Confirmer une remise liée à la vente', 'Stock',
       permission=['delivery.confirm'], command='ConfirmDelivery', response=BILLING + 'DeliveryResponse', status=201, task='I01', params=(ID,), errors=(409, 422)),
    op('post', '/v1/deliveries/{id}/physical-returns', 'RegisterPhysicalReturn', 'Enregistrer un retour physique inspecté', 'Stock',
       permission=['stock.adjust'], command='RegisterPhysicalReturn', response=STOCK + 'PhysicalReturnResponse', status=201, task='I02', params=(ID,), errors=(409, 422)),

    # --- Agenda et suivi -----------------------------------------------------------------------------
    op('post', '/v1/follow-ups', 'CreateFollowUp', 'Programmer un contrôle recommandé', 'Agenda et suivi',
       permission=['follow_up.write'], command='CreateFollowUp', response=SCHEDULING + 'FollowUpPlanResponse', status=201, task='S02', errors=(409, 422)),
    op('post', '/v1/appointments', 'CreateAppointment', 'Créer un rendez-vous interne', 'Agenda et suivi',
       permission=['agenda.write'], command='CreateAppointment', response=SCHEDULING + 'AppointmentResponse', status=201, task='S01', errors=(409, 422)),
    op('get', '/v1/appointments', 'ListAppointments', 'Rendez-vous d\'une période', 'Agenda et suivi',
       permission=['agenda.read'], response=SCHEDULING + 'AppointmentPage', task='S01',
       params=(('query', 'from', 'Instant UTC de début.'), ('query', 'to', 'Instant UTC de fin.'), PAGE)),
    op('post', '/v1/appointments/{id}/reschedule', 'RescheduleAppointment', 'Déplacer un rendez-vous (réservation concurrente contrôlée)', 'Agenda et suivi',
       permission=['agenda.write'], request=REQUESTS + 'RescheduleAppointmentRequest', response=SCHEDULING + 'AppointmentResponse',
       task='S01', params=(ID,), errors=(409, 422), description='En ligne seulement : les autres calendriers sont inconnus hors-ligne (fichier 19).'),
    op('post', '/v1/appointments/{id}/cancel', 'CancelAppointment', 'Annuler un rendez-vous', 'Agenda et suivi',
       permission=['agenda.write'], request=REQUESTS + 'CancelAppointmentRequest', response=SCHEDULING + 'AppointmentResponse', task='S01', params=(ID,), errors=(409, 422)),
    op('get', '/v1/contact-tasks', 'ListContactTasks', 'Tâches de contact du jour', 'Agenda et suivi',
       permission=['agenda.read'], response=SCHEDULING + 'ContactTaskPage', task='S02',
       params=(('query', 'due_date', 'Date civile AAAA-MM-JJ.'), PAGE)),
    op('post', '/v1/contact-tasks/{id}/attempts', 'RecordContactAttempt', 'Historiser une tentative de contact', 'Agenda et suivi',
       permission=['agenda.write'], command='RecordContactAttempt', response=SCHEDULING + 'ContactAttemptResultResponse', status=201, task='S02', params=(ID,), errors=(409, 422)),

    # --- Rapports ------------------------------------------------------------------------------------
    op('get', '/v1/reports/activity', 'GetActivityReport', 'Activité administrative', 'Rapports',
       permission=['statistics.activity'], response=SCHEDULING + 'ActivityReportResponse', task='R02',
       params=(('query', 'from', 'Date civile de début.'), ('query', 'to', 'Date civile de fin.'))),
    op('get', '/v1/reports/clinical', 'GetClinicalReport', 'Statistiques cliniques agrégées', 'Rapports',
       permission=['statistics.clinical'], response=SCHEDULING + 'ClinicalReportResponse', task='R02',
       params=(('query', 'from', 'Date civile de début.'), ('query', 'to', 'Date civile de fin.'))),
    op('get', '/v1/reports/financial', 'FinancialDashboard', 'Chiffre d\'affaires et encaissements (gérante)', 'Rapports',
       permission=['finance.global.read'], response=OPERATIONS + 'FinancialReportResponse', task='R01',
       params=(('query', 'from', 'Date civile de début.'), ('query', 'to', 'Date civile de fin.'))),
    op('get', '/v1/audit-events', 'ListAuditEvents', 'Consulter le journal d\'audit', 'Rapports',
       permission=['audit.read'], response=OPERATIONS + 'AuditEventPage', implemented=True, task='F07', errors=(422,),
       params=(('query', 'target_type', 'Type de cible.'), ('query', 'target_id', 'Identifiant de la cible.'), ('query', 'from', 'Instant UTC de début.'),
               ('query', 'to', 'Instant UTC de fin.'), PAGE)),

    # --- Documents -----------------------------------------------------------------------------------
    op('get', '/v1/documents/{id}/download', 'GetDocument', 'Télécharger un document privé', 'Documents',
       permission=['invoice.read', 'clinical.export'], response=None, task='D01', params=(ID,), errors=(404,),
       description='Policy du document : pièce financière avec invoice.read, document clinique avec clinical.export ; stockage privé, jamais d\'URL publique. Réponse application/pdf.'),
    op('post', '/v1/documents/{id}/content', 'StoreDocumentContent', 'Déposer le PDF correspondant au snapshot', 'Documents',
       permission=['invoice.issue', 'payment.collect', 'clinical.complete'], request='binary-pdf', request_type='application/pdf',
       response=OPERATIONS + 'GeneratedDocumentResponse', task='D01', params=(ID,), errors=(409, 413, 422),
       description='Le serveur vérifie Policy, source, version de template, type et SHA-256 des octets, calcule le chemin privé ; un READY n\'est plus modifié, un dépôt identique retrouve le fichier.'),

    # --- Postes et synchronisation --------------------------------------------------------------------
    op('post', '/v1/devices/enroll', 'EnrollDevice', 'Enrôler un poste (clé publique ES256)', 'Postes et synchronisation',
       permission=['offline.enroll'], reauth=True, request=REQUESTS + 'EnrollDeviceRequest', response=OPERATIONS + 'EnrolledDeviceResponse', status=201, task='O02', errors=(409, 422)),
    op('post', '/v1/devices/{id}/prepare', 'PrepareDevice', 'Préparer le poste : grants, plages, quotas, catalogue', 'Postes et synchronisation',
       permission=['offline.enroll'], reauth=True, request=REQUESTS + 'PrepareDeviceRequest', response=OPERATIONS + 'PreparationResponse', task='O03', params=(ID,), errors=(409, 422)),
    op('post', '/v1/devices/{id}/release', 'ReleaseDevice', 'Clôturer les droits du poste après vérification', 'Postes et synchronisation',
       permission=['offline.enroll'], reauth=True, request=REQUESTS + 'ReleaseDeviceRequest', response=OPERATIONS + 'ReleaseResponse', task='O03', params=(ID,), errors=(409, 422)),
    op('get', '/v1/devices/{id}/health', 'GetDeviceHealth', 'Santé du poste (dernière synchronisation, conflits, plages)', 'Postes et synchronisation',
       permission=['offline.enroll'], response=OPERATIONS + 'DeviceHealthResponse', task='O05', params=(ID,)),
    op('post', '/v1/sync/push', 'PushCommands', 'Transmettre un lot ordonné de commandes signées', 'Postes et synchronisation',
       request='./sync/push-request.json', response='./sync/push-response.json', task='O05', idempotent=False, errors=(409, 413, 422),
       description='Session du profil ouvert ; chaque commande est autorisée par son grant et sa permission ; ACK par commande, timeout = rejouer les mêmes identifiants. Idempotence portée par command_id et event_hash.'),
    op('get', '/v1/sync/pull', 'PullChanges', 'Changements autorisés depuis un curseur opaque signé', 'Postes et synchronisation',
       response='./sync/pull-page.json', task='O05', params=(('query', 'cursor', 'Curseur opaque signé de la page précédente (absent au premier appel).'),),
       description='Pages ordonnées par publication sous verrou du cabinet ; le client n\'avance le curseur qu\'après commit local de toute la page.'),
    op('get', '/v1/sync/conflicts', 'ListSyncConflicts', 'Conflits de synchronisation à revoir', 'Postes et synchronisation',
       permission=['approval.decide'], response=OPERATIONS + 'ConflictPage', task='O05', params=(PAGE,)),
    op('post', '/v1/sync/conflicts/{id}/resolution', 'ResolveSyncConflict', 'Résoudre un conflit (décision motivée, jamais anonyme)', 'Postes et synchronisation',
       permission=['approval.decide'], reauth=True, request=REQUESTS + 'ConflictResolutionRequest', response=OPERATIONS + 'ConflictResponse', task='O05', params=(ID,), errors=(409, 422)),

    # --- Restitution et imports -----------------------------------------------------------------------
    op('post', '/v1/exports', 'RequestExport', 'Demander un export privé', 'Restitution',
       permission=['export.global', 'clinical.export'], reauth=True, request=REQUESTS + 'CreateExportRequest',
       response=OPERATIONS + 'ExportRequestResponse', status=201, task='X02', errors=(422,),
       description='clinical.export suffit pour l\'export du dossier d\'un patient (PATIENT_RECORD) ; les autres types exigent export.global.'),
    op('get', '/v1/exports/{id}', 'GetExport', 'État d\'un export', 'Restitution',
       permission=['export.global', 'clinical.export'], response=OPERATIONS + 'ExportRequestResponse', task='X02', params=(ID,)),
    op('get', '/v1/exports/{id}/download', 'DownloadExport', 'Télécharger un export prêt (privé, expirant)', 'Restitution',
       permission=['export.global', 'clinical.export'], response=None, task='X02', params=(ID,), errors=(404, 410)),
    op('post', '/v1/imports/catalog', 'ImportCatalog', 'Importer le catalogue (aperçu puis import contrôlé)', 'Restitution',
       permission=['catalog.manage'], reauth=True, request='multipart-csv', request_type='multipart/form-data',
       response=BILLING + 'ImportReportResponse', task='X01', errors=(422,)),
    op('post', '/v1/imports/legacy-balances', 'ImportLegacyBalances', 'Importer les créances papier (factures LEGACY_BALANCE)', 'Restitution',
       permission=['finance.global.read'], reauth=True, request='multipart-csv', request_type='multipart/form-data',
       response=BILLING + 'ImportReportResponse', task='X01', errors=(422,),
       description='Chaque créance devient une pièce d\'origine LEGACY_BALANCE (référence papier, solde d\'ouverture) ; aucun encaissement ancien n\'apparaît comme reçu aujourd\'hui.'),

    # --- Réservation publique (L2) --------------------------------------------------------------------
    op('get', '/public/v1/services', 'ListPublicServices', 'Prestations réservables', 'Réservation publique (L2)',
       response=PUBLIC + 'PublicServiceList', task='L2D02', security='none', idempotent=False),
    op('get', '/public/v1/slots', 'ListPublicSlots', 'Créneaux administratifs disponibles', 'Réservation publique (L2)',
       response=PUBLIC + 'PublicSlotList', task='L2D02', security='none', idempotent=False,
       params=(('query', 'service_id', 'Prestation.'), ('query', 'from', 'Instant UTC de début.'), ('query', 'to', 'Instant UTC de fin.'))),
    op('post', '/public/v1/booking-requests', 'RequestPublicBooking', 'Demander un rendez-vous (consentement, anti-abus)', 'Réservation publique (L2)',
       request=REQUESTS + 'BookingRequest', response=PUBLIC + 'BookingReceiptResponse', status=201, task='L2D02', security='none', idempotent=True,
       errors=(409, 422, 429), description='Aucun jeton de back-office ; référence publique distincte ; pas de déduction indiquant qu\'un téléphone possède un dossier.'),
]

ERRORS = {
    400: ('BadRequest', 'Requête refusée (par exemple SESSION_REQUIRED : origine non reconnue).'),
    401: ('Unauthorized', 'AUTH_REQUIRED : reconnexion, saisies conservées.'),
    403: ('Forbidden', 'ACTION_FORBIDDEN : droit insuffisant, sans divulguer la cible.'),
    404: ('NotFound', 'RESOURCE_NOT_FOUND : inexistante ou inaccessible (autre cabinet).'),
    409: ('Conflict', 'VERSION_CONFLICT, STATE_TRANSITION_REFUSED, PAYMENT_EXCEEDS_BALANCE, STOCK_UNAVAILABLE, IDEMPOTENCY_PAYLOAD_MISMATCH, SLOT_UNAVAILABLE ou garde-fou métier.'),
    410: ('Gone', 'Ressource expirée (export).'),
    413: ('PayloadTooLarge', 'Taille au-delà des limites du contrat 23.'),
    419: ('SessionExpired', 'SESSION_CONTEXT_EXPIRED : renouveler session/CSRF et rejouer la même commande idempotente.'),
    422: ('ValidationFailed', 'VALIDATION_FAILED, EXAM_PAYMENT_REQUIRED, APPROVAL_REQUIRED_OR_EXPIRED, LOT_EXPIRED…'),
    423: ('ReauthenticationRequired', 'REAUTHENTICATION_REQUIRED : confirmer le mot de passe.'),
    429: ('RateLimited', 'RATE_LIMITED : attendre (en-tête Retry-After), sans rejouer un paiement sans clé.'),
    503: ('Unavailable', 'CLINIC_NOT_CONFIGURED ou DEPENDENCY_UNAVAILABLE.'),
}


def build_operation(spec):
    operation = {
        'operationId': spec['operation_id'],
        'summary': spec['summary'],
        'tags': [spec['tag']],
    }
    if spec['description']:
        operation['description'] = spec['description']
    if spec['security'] == 'none':
        operation['security'] = []
    elif spec['security'] == 'csrf':
        operation['security'] = [{'xsrfToken': []}]
    elif spec['method'] == 'get':
        operation['security'] = [{'sessionCookie': []}]
    else:
        operation['security'] = [{'sessionCookie': [], 'xsrfToken': []}]

    parameters = []
    if spec['idempotent']:
        parameters.append({'$ref': '#/components/parameters/IdempotencyKey'})
    for location, name, description in spec['params']:
        parameter = {'name': name, 'in': location, 'required': location == 'path', 'description': description}
        if name == 'id':
            parameter['schema'] = {'$ref': './schemas/common.json#/$defs/Ulid'}
        elif name in ('page',):
            parameter['schema'] = {'type': 'integer', 'minimum': 1}
        else:
            parameter['schema'] = {'type': 'string'}
        parameters.append(parameter)
    if parameters:
        operation['parameters'] = parameters

    if spec['request'] == 'binary-pdf':
        operation['requestBody'] = {'required': True, 'content': {'application/pdf': {'schema': {'type': 'string', 'contentMediaType': 'application/pdf'}}}}
    elif spec['request'] == 'multipart-csv':
        operation['requestBody'] = {'required': True, 'content': {'multipart/form-data': {'schema': {
            'type': 'object', 'required': ['file', 'dry_run'],
            'properties': {'file': {'type': 'string', 'contentMediaType': 'text/csv'}, 'dry_run': {'type': 'boolean'}}}}}}
    elif spec['request']:
        operation['requestBody'] = {'required': True, 'content': {spec['request_type']: {'schema': {'$ref': spec['request']}}}}

    responses = {}
    status = str(spec['status'])
    if spec['response']:
        responses[status] = {'description': 'Succès.', 'content': {'application/json': {'schema': {'$ref': spec['response']}}}}
    elif spec['operation_id'] in ('GetDocument', 'DownloadExport'):
        responses['200'] = {'description': 'Fichier privé.', 'content': {'application/octet-stream': {'schema': {'type': 'string', 'contentMediaType': 'application/octet-stream'}}}}
    else:
        responses[status] = {'description': 'Succès, sans contenu.'}
    codes = set(spec['errors'])
    if spec['security'] != 'none':
        codes |= {401, 403, 419} if spec['operation_id'] != 'Login' else {419}
    if spec['reauth']:
        codes.add(423)
    if spec['security'] == 'none' and spec['operation_id'] == 'GetCsrfCookie':
        codes = set()
    for code in sorted(codes):
        responses[str(code)] = {'$ref': '#/components/responses/' + ERRORS[code][0]}
    operation['responses'] = responses

    if spec['permission']:
        operation['x-permission'] = spec['permission']
    operation['x-reauthentication'] = spec['reauth']
    operation['x-idempotency'] = 'required' if spec['idempotent'] else 'none'
    if spec['command']:
        operation['x-offline-command'] = spec['command']
    if spec['read_scope']:
        operation['x-read-scope'] = spec['read_scope']
    operation['x-implementation-status'] = 'IMPLEMENTED' if spec['implemented'] else 'PLANNED'
    operation['x-backlog-task'] = spec['task']
    return operation


def build():
    paths = {}
    for spec in OPERATIONS_TABLE:
        path_item = paths.setdefault(spec['path'], {})
        if spec['path'].startswith('/public/'):
            path_item.setdefault('servers', [{'url': '/', 'description': 'Routes publiques hors /api (fichier 08, L2).'}])
        if spec['method'] in path_item:
            raise SystemExit('ECHEC : opération en double ' + spec['method'] + ' ' + spec['path'])
        path_item[spec['method']] = build_operation(spec)

    for name, entry in COMMANDS.items():
        method, route = entry['direct_route'].split(' ', 1)
        operation = paths.get('/v1' + route, {}).get(method.lower())
        if operation is None or operation.get('x-offline-command') != name:
            raise SystemExit('ECHEC : la route directe ' + entry['direct_route'] + ' de ' + name + ' manque dans la table OpenAPI')

    components = {
        'securitySchemes': {
            'sessionCookie': {'type': 'apiKey', 'in': 'cookie', 'name': 'eagles-eye-optics-medical-session',
                              'description': 'Session Sanctum du seul sous-domaine applicatif (cookie Secure, HttpOnly, SameSite=Lax). Le nom suit SESSION_COOKIE.'},
            'xsrfToken': {'type': 'apiKey', 'in': 'header', 'name': 'X-XSRF-TOKEN',
                          'description': 'Jeton CSRF lu dans le cookie XSRF-TOKEN ; une écriture marquée Sec-Fetch-Site: same-origin est aussi acceptée (Fetch Metadata).'},
        },
        'parameters': {
            'IdempotencyKey': {'name': 'Idempotency-Key', 'in': 'header', 'required': True,
                               'description': 'ULID de l\'intention : même clé et même contenu = résultat durable existant ; même clé et contenu différent = 409 IDEMPOTENCY_PAYLOAD_MISMATCH ; une nouvelle intention reçoit une nouvelle clé.',
                               'schema': {'$ref': './schemas/common.json#/$defs/Ulid'}},
        },
        'responses': {
            name: {'description': description, 'content': {'application/json': {'schema': {'$ref': './schemas/error.json'}}}}
            for name, description in ERRORS.values()
        },
    }
    tags = []
    for spec in OPERATIONS_TABLE:
        if spec['tag'] not in tags:
            tags.append(spec['tag'])
    return {
        'openapi': '3.1.0',
        'info': {
            'title': 'Eagle\'s Eye Optics Medical — API privée',
            'version': '0.1.0-l0',
            'description': ('Contrat des routes du fichier 08, des compléments du contrat 23 et du protocole du fichier 19. '
                            'Corps et réponses : JSON Schema 2020-12 de contracts/, identiques en PHP et en TypeScript. '
                            'Timestamps UTC à la milliseconde, dates civiles AAAA-MM-JJ, montants entiers XAF, quantités et mesures en chaînes exactes. '
                            'x-implementation-status indique ce qui est réellement implémenté. Généré par contracts/tools/build_openapi.py : ne pas modifier à la main.'),
        },
        'jsonSchemaDialect': 'https://json-schema.org/draft/2020-12/schema',
        'servers': [{'url': '/api', 'description': 'API privée de l\'application, origine unique HTTPS du sous-domaine.'}],
        'tags': [{'name': tag} for tag in tags],
        'paths': paths,
        'components': components,
    }


# --------------------------------------------------------------------------------------------------
# Émetteur YAML minimal et déterministe
# --------------------------------------------------------------------------------------------------

PLAIN_KEY = re.compile(r'^[A-Za-z_$/][A-Za-z0-9_.$/{}-]*$')
RESERVED = {'true', 'false', 'null', 'yes', 'no', 'on', 'off', 'y', 'n', '~'}


def key(name):
    name = str(name)
    if PLAIN_KEY.match(name) and name.lower() not in RESERVED:
        return name
    return json.dumps(name, ensure_ascii=False)


def scalar(value):
    if value is None:
        return 'null'
    if value is True:
        return 'true'
    if value is False:
        return 'false'
    if isinstance(value, (int, float)):
        return json.dumps(value)
    if isinstance(value, str):
        return json.dumps(value, ensure_ascii=False)
    if value == {}:
        return '{}'
    if value == []:
        return '[]'
    raise TypeError(type(value))


def emit(value, indent=0):
    pad = '  ' * indent
    lines = []
    if isinstance(value, dict):
        for name, item in value.items():
            if isinstance(item, (dict, list)) and item:
                lines.append(pad + key(name) + ':')
                lines.extend(emit(item, indent + 1))
            else:
                lines.append(pad + key(name) + ': ' + scalar(item))
    elif isinstance(value, list):
        for item in value:
            if isinstance(item, (dict, list)) and item:
                inner = emit(item, indent + 1)
                lines.append(pad + '- ' + inner[0].lstrip())
                lines.extend(inner[1:])
            else:
                lines.append(pad + '- ' + scalar(item))
    else:
        lines.append(pad + scalar(value))
    return lines


def main():
    document = build()
    text = '# Généré par contracts/tools/build_openapi.py : ne pas modifier à la main.\n' + '\n'.join(emit(document)) + '\n'
    if '--check' in sys.argv:
        if not OUT.exists() or OUT.read_text(encoding='utf-8') != text:
            print('ECHEC : contracts/openapi.yaml périmé : relancer contracts/tools/build_openapi.py', file=sys.stderr)
            sys.exit(1)
        print('OpenAPI à jour : ' + str(len(OPERATIONS_TABLE)) + ' opérations.')
        return
    OUT.write_text(text, encoding='utf-8')
    print(str(len(OPERATIONS_TABLE)) + ' opérations écrites dans contracts/openapi.yaml')


if __name__ == '__main__':
    main()
