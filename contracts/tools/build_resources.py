"""Ressources de réponse, requêtes HTTP sans commande hors-ligne et snapshots de pièces (F06).

Source unique des DTO de l'API (fichiers 08, 19, 23) et des contenus figés écrits dans les colonnes JSON
`snapshot` du catalogue (fichier 11 : un document est généré à partir de son snapshot, jamais recalculé
sur l'identité ou les prix récents). Produit :
  - contracts/schemas/resources/<groupe>.json : objets renvoyés par l'API et leurs enveloppes
    {data: …} / {data: […], meta: Pagination} ;
  - contracts/schemas/requests/<groupe>.json : corps des routes en ligne qui ne sont pas des commandes du
    journal local (session, comptes, préparation du poste, exports…) ; les routes qui appellent une
    commande utilisent contracts/commands/<Commande>.v1.json#/$defs/DirectRequest ;
  - contracts/snapshots/<pièce>.json : snapshots de facture, reçu, avoir, remboursement, remise et
    révision clinique.

Conventions : montants en entiers XAF, quantités et mesures en chaînes exactes, instants UTC à la
milliseconde, dates civiles AAAA-MM-JJ ; une valeur calculée par le serveur (solde, disponible,
ledger_version) est une projection, jamais une colonne inventée ; une rubrique non autorisée pour le
profil est absente de la réponse (annotation x-read-scope dans l'OpenAPI).

  python3 contracts/tools/build_resources.py          # écrit les fichiers
  python3 contracts/tools/build_resources.py --check  # échoue si un fichier est absent ou périmé (CI)
"""
from pathlib import Path
import json
import sys

ROOT = Path(__file__).resolve().parents[2]
CONTRACTS = ROOT / 'contracts'
BASE = 'https://eeo.invalid/contracts/'
GENERATED = ' Généré par contracts/tools/build_resources.py : ne pas modifier à la main.'


class Refs:
    """Préfixes de $ref relatifs au fichier produit."""

    def __init__(self, depth):
        up = '../' * depth
        self.common = up + 'schemas/common.json#/$defs/' if depth else 'common.json#/$defs/'
        self.enums = up + 'schemas/enums.json#/$defs/' if depth else 'enums.json#/$defs/'
        self.steps = up + 'schemas/clinical-steps.json#/$defs/' if depth else 'clinical-steps.json#/$defs/'


R = Refs(2)        # contracts/schemas/resources/*.json et contracts/schemas/requests/*.json
S = Refs(1)        # contracts/snapshots/*.json
CURRENT = R


def ref(name):
    return {'$ref': CURRENT.common + name}


def enum(column):
    return {'$ref': CURRENT.enums + column}


def step(name):
    return {'$ref': CURRENT.steps + name}


def local(name):
    return {'$ref': '#/$defs/' + name}


def nullable(schema):
    return {'anyOf': [schema, {'type': 'null'}]}


def text(max_length=None, min_length=1):
    schema = {'type': 'string', 'minLength': min_length}
    if max_length:
        schema['maxLength'] = max_length
    return schema


def n_text(max_length=None):
    return nullable(text(max_length, 0))


def boolean():
    return {'type': 'boolean'}


def integer(minimum=None, maximum=None):
    schema = {'type': 'integer'}
    if minimum is not None:
        schema['minimum'] = minimum
    if maximum is not None:
        schema['maximum'] = maximum
    return schema


def array(items, min_items=0, max_items=None):
    schema = {'type': 'array', 'minItems': min_items, 'items': items}
    if max_items is not None:
        schema['maxItems'] = max_items
    return schema


def obj(properties, optional=(), description=None):
    """Objet strict : propriétés requises sauf celles de `optional` (rubriques soumises aux droits, champs
    facultatifs d'une requête)."""
    schema = {'type': 'object', 'additionalProperties': False,
              'required': [name for name in properties if name not in optional], 'properties': properties}
    if description:
        schema = {'description': description, **schema}
    return schema


def data(name):
    return obj({'data': local(name)})


def page(name):
    return obj({'data': array(local(name)), 'meta': ref('Pagination')})


def role():
    """Profils seedés par la baseline (table roles) : référentiel canonique, pas une énumération inventée."""
    return {'type': 'string', 'enum': ['CASHIER', 'CLINICIAN', 'MANAGER', 'RECEPTION']}


def ulid():
    return ref('Ulid')


def n_ulid():
    return ref('NullableUlid')


def instant():
    return ref('Instant')


def n_instant():
    return ref('NullableInstant')


def money():
    return ref('MoneyXaf')


def signed_money():
    return ref('SignedMoneyXaf')


def quantity():
    return ref('Quantity')


def decimal():
    return {'type': 'string', 'pattern': '^-?(0|[1-9][0-9]{0,8})(\\.[0-9]{1,3})?$', 'description': 'DECIMAL(12,3) en chaîne exacte, zéro admis.'}


def schema_file(path, title, description, defs):
    return path, {
        '$schema': 'https://json-schema.org/draft/2020-12/schema',
        '$id': BASE + path,
        'title': title,
        'description': description + GENERATED,
        '$defs': defs,
    }


# --------------------------------------------------------------------------------------------------
# Ressources de réponse
# --------------------------------------------------------------------------------------------------

def access_resources():
    staff = {'id': ulid(), 'login': text(64), 'display_name': text(180), 'email': nullable({'type': 'string', 'maxLength': 254}),
             'active': boolean(), 'version': ref('Version'), 'roles': array(role(), 0, 4)}
    defs = {
        'StaffMemberSummary': obj(dict(staff), description='Compte dans une liste : sans les permissions effectives.'),
        'StaffMember': obj({**staff, 'permissions': array(ref('PermissionCode'))},
                           description='Compte du personnel : profils actifs et permissions effectives (jamais de hash ni de secret MFA).'),
        'ClinicSummary': obj({'id': ulid(), 'name': text(180), 'timezone': text(64), 'currency': ref('Currency'),
                              'production_documents_enabled': boolean(),
                              'enabled_modules': array(text(40))},
                             description='enabled_modules : modules ouverts par l\'exploitant (EEO_ENABLED_MODULES), codes de la navigation ; vide = aucun module métier exposé.'),
        'Me': obj({**staff, 'permissions': array(ref('PermissionCode')), 'clinic': local('ClinicSummary')},
                  description='Compte connecté : profils, permissions effectives, cabinet (nom, fuseau, monnaie).'),
        'ClinicSettings': obj({'id': ulid(), 'name': text(180), 'timezone': text(64), 'currency': ref('Currency'),
                               'address': n_text(2000), 'phone': nullable({'type': 'string', 'maxLength': 32}),
                               'email': nullable({'type': 'string', 'maxLength': 254}), 'tax_identifier': n_text(100),
                               'professional_mentions': n_text(4000), 'document_settings': nullable({'type': 'object'}),
                               'production_documents_enabled': boolean()},
                              description='Paramètres administratifs du cabinet (gérante).'),
        'Reauthentication': obj({'reauthenticated_until': instant()}),
        'StaffMemberResponse': data('StaffMember'),
        'StaffMemberPage': page('StaffMemberSummary'),
        'MeResponse': data('Me'),
        'ClinicSettingsResponse': data('ClinicSettings'),
        'ReauthenticationResponse': data('Reauthentication'),
    }
    return schema_file('schemas/resources/access.json', 'Ressources : session, comptes et cabinet', 'Réponses des routes F05 (implémentées).', defs)


def patient_resources():
    identity = {
        'id': ulid(), 'record_number': text(64), 'last_name': text(120), 'first_names': n_text(180),
        'sex': enum('patient_records.sex'), 'birth_date': ref('NullableCivilDate'),
        'phone_primary': nullable(ref('Phone')), 'archived': boolean(),
        'merged_into_patient_id': n_ulid(), 'version': ref('Version'),
    }
    defs = {
        'PatientSummary': obj(dict(identity), description='Résultat de recherche : identité administrative minimale (tous profils autorisés).'),
        'Patient': obj({**identity,
                        'birth_date_missing_reason': n_text(255), 'phone_secondary': nullable(ref('Phone')),
                        'phone_missing_reason': n_text(255), 'address': n_text(), 'profession': n_text(180),
                        'guardian_contact': nullable({'type': 'object'}), 'legacy_record': boolean(),
                        'first_known_visit_on': ref('NullableCivilDate'), 'first_visit_certified': boolean(),
                        'legacy_record_number': n_text(64), 'created_at': instant(), 'updated_at': instant()},
                       description='Dossier administratif (identité durable) ; jamais de donnée clinique.'),
        'PatientHistory': obj({
            'patient_id': ulid(),
            'visits': array(obj({'id': ulid(), 'reason': enum('visits.reason'), 'occurred_at': instant(), 'status': enum('visits.status')})),
            'consultations': array(obj({'id': ulid(), 'visit_id': ulid(), 'effective_at': instant(), 'status': enum('consultations.status'),
                                        'current_revision': integer(0), 'clinician_id': ulid()})),
            'invoices': array(obj({'id': ulid(), 'number': n_text(64), 'status': enum('invoices.status'), 'issued_at': n_instant(),
                                   'total_gross': money(), 'balance_due': signed_money()})),
        }, optional=('consultations', 'invoices'),
            description='Historique : visites pour tout profil autorisé ; consultations seulement avec clinical.read ; pièces seulement avec invoice.read.'),
        'PatientResponse': data('Patient'),
        'PatientPage': page('PatientSummary'),
        'PatientHistoryResponse': data('PatientHistory'),
        'Visit': obj({'id': ulid(), 'patient_id': ulid(), 'reason': enum('visits.reason'), 'occurred_at': instant(),
                      'status': enum('visits.status'), 'appointment_id': n_ulid(), 'exam_invoice_id': n_ulid(),
                      'exam_waiver_approval_id': n_ulid(), 'arrival_at': n_instant(), 'closed_at': n_instant(),
                      'administrative_note': n_text(), 'version': ref('Version')}),
        'VisitResponse': data('Visit'),
        'VisitPage': page('Visit'),
    }
    return schema_file('schemas/resources/patients.json', 'Ressources : patients et visites', 'Identité administrative, historique filtré par profil, visites.', defs)


def clinical_resources():
    revision = obj({'id': ulid(), 'revision_no': integer(1), 'recorded_at': instant(), 'author_id': ulid(), 'reason': text(),
                    'previous_revision_id': n_ulid(), 'sha256': ref('Sha256Hex')})
    eye = obj({'id': ulid(), 'eye': enum('optical_prescription_eyes.eye'), 'no_correction': boolean(),
               'sphere': ref('NullableDiopter'), 'cylinder': ref('NullableDiopter'), 'axis': nullable(integer(0, 180)),
               'addition': ref('NullableDiopter'), 'fitting_height': ref('NullableDiopter')})
    term = obj({'id': ulid(), 'term_code': ref('Code'), 'other_text': n_text()})
    defs = {
        'ClinicalRevision': revision,
        'Consultation': obj({'id': ulid(), 'visit_id': ulid(), 'patient_id': ulid(), 'clinician_id': ulid(), 'effective_at': instant(),
                             'date_change_reason': n_text(), 'status': enum('consultations.status'), 'current_revision': integer(0),
                             'completed_at': n_instant(), 'finalization_missing_data_reason': n_text(), 'version': ref('Version'),
                             'steps': step('AllSteps'), 'revisions': array(revision)},
                            description='Consultation complète : professionnel et gérante seulement (clinical.read).'),
        'Prescription': obj({'id': ulid(), 'series_id': ulid(), 'version_no': integer(1), 'supersedes_id': n_ulid(), 'patient_id': ulid(),
                             'origin': enum('optical_prescriptions.origin'), 'consultation_id': n_ulid(),
                             'external_prescriber': n_text(180), 'external_prescribed_on': ref('NullableCivilDate'), 'external_file_id': n_ulid(),
                             'status': enum('optical_prescriptions.status'), 'usage_code': nullable(ref('Code')), 'usage_other': n_text(),
                             'lens_type_code': nullable(ref('Code')), 'lens_type_other': n_text(),
                             'lens_index': nullable({'type': 'string', 'pattern': '^[0-9]\\.[0-9]{1,2}$'}), 'lens_index_other': n_text(),
                             'pd_total': ref('NullableDiopter'), 'pd_od': ref('NullableDiopter'), 'pd_og': ref('NullableDiopter'),
                             'pd_inconsistency_reason': n_text(), 'notes': n_text(), 'eyes': array(eye, 2, 2), 'treatments': array(term),
                             'validated_at': n_instant(), 'validated_by': n_ulid(), 'document_id': n_ulid(), 'updated_at': instant()},
                            description='Prescription versionnée ; updated_at sert de jeton de concurrence pour PATCH d\'un brouillon (optical_prescriptions n\'a pas de colonne version).'),
        'ConsultationResponse': data('Consultation'),
        'PrescriptionResponse': data('Prescription'),
    }
    return schema_file('schemas/resources/clinical.json', 'Ressources : consultations et prescriptions', 'Données cliniques réservées aux profils autorisés (fichier 09).', defs)


def billing_resources():
    line = obj({'id': ulid(), 'position': integer(1), 'catalog_item_id': n_ulid(), 'reference_snapshot': n_text(64),
                'description_snapshot': text(500), 'category_snapshot': ref('Code'), 'unit_snapshot': ref('Code'),
                'quantity': quantity(), 'unit_price_gross': money(), 'discount_gross': money(), 'line_total_gross': money(),
                'tax_code_snapshot': text(40), 'tax_rate_snapshot': nullable(ref('Rate')), 'tax_amount': nullable(money()),
                'prescription_id': n_ulid()})
    balance = obj({'total_gross': money(), 'paid_net': money(), 'credited': money(), 'refunded': money(),
                   'balance_due': signed_money(), 'credit_available': money(),
                   'settlement': {'type': 'string', 'enum': ['NOT_ISSUED', 'UNPAID', 'PARTIALLY_PAID', 'SETTLED', 'IN_CREDIT']}},
                  description='Projection calculée sous le verrou de la facture (contrat 23) : aucun statut PAID stocké.')
    payment = obj({'id': ulid(), 'amount': ref('PositiveMoneyXaf'), 'method': enum('payments.method'), 'reference': n_text(180),
                   'effective_at': instant(), 'confirmed_at': n_instant(), 'status': enum('payments.status'),
                   'receipt_id': n_ulid(), 'receipt_number': n_text(64), 'reversed_amount': money()})
    document_state = obj({'document_id': ulid(), 'document_status': enum('generated_documents.status')},
                         description='État central du PDF : PENDING n\'autorise jamais à répéter l\'opération (fichier 08).')
    defs = {
        'CatalogItem': obj({'id': ulid(), 'reference': text(64), 'category_code': ref('Code'), 'name': text(180), 'brand': n_text(120),
                            'model': n_text(120), 'unit_code': ref('Code'), 'quantity_integer_only': boolean(), 'sale_price': money(),
                            'purchase_cost': nullable(money()), 'tax_rule_id': n_ulid(), 'stock_mode': enum('catalog_items.stock_mode'),
                            'requires_expiry': boolean(), 'active': boolean(), 'version': ref('Version')},
                           optional=('purchase_cost',),
                           description='Article : prix de vente pour les profils autorisés ; purchase_cost présent seulement pour la gérante (fichier 08).'),
        'CatalogPage': page('CatalogItem'),
        'CatalogItemResponse': data('CatalogItem'),
        'TaxRule': obj({'id': ulid(), 'code': text(40), 'label': text(180), 'rate_percent': nullable(ref('Rate')), 'active': boolean(),
                        'validated_by': n_ulid(), 'validated_at': n_instant(), 'legal_note': n_text(), 'version': ref('Version')},
                       description='Règle fiscale saisie par la gérante ; jamais inventée : sans validation (validated_at), elle n\'est pas appliquée aux documents de production.'),
        'TaxRuleResponse': data('TaxRule'),
        'TaxRuleList': obj({'data': array(local('TaxRule'))}),
        'ImportReport': obj({'dry_run': boolean(), 'rows': integer(0), 'accepted': integer(0),
                             'rejected': array(obj({'line': integer(1), 'field': n_text(64), 'message': text()})),
                             'created_ids': array(ulid())},
                            description='Rapport d\'import contrôlé (X01) : aperçu sans écriture (dry_run) puis import ; une ligne refusée n\'est jamais importée partiellement.'),
        'ImportReportResponse': data('ImportReport'),
        'InvoiceLine': line,
        'InvoiceBalance': balance,
        'InvoicePayment': payment,
        'DocumentState': document_state,
        'Invoice': obj({'id': ulid(), 'patient_id': ulid(), 'visit_id': n_ulid(), 'number': n_text(64), 'status': enum('invoices.status'),
                        'issued_at': n_instant(), 'issued_by': n_ulid(), 'due_date': ref('NullableCivilDate'), 'currency': ref('Currency'),
                        'total_gross': money(), 'origin': enum('invoices.origin'), 'legacy_reference': n_text(100),
                        'draft_version': ref('Version'), 'ledger_version': nullable(ref('Version')), 'balance': balance,
                        'lines': array(line), 'payments': array(payment),
                        'credit_notes': array(obj({'id': ulid(), 'number': n_text(64), 'status': enum('credit_notes.status'),
                                                   'amount_gross': money(), 'issued_at': n_instant()})),
                        'refunds': array(obj({'id': ulid(), 'number': text(64), 'amount': ref('PositiveMoneyXaf'),
                                              'method': enum('refunds.method'), 'effective_at': instant()})),
                        'write_owner': nullable(obj({'device_id': ulid(), 'epoch': integer(1), 'status': enum('invoice_write_owners.status')})),
                        'document': nullable(document_state)},
                       description='Facture : draft_version (version du brouillon, figée à l\'émission) et ledger_version (projection après émission, null pour un brouillon) nommées distinctement (fichier 08).')
        | {'allOf': [
            {'if': {'properties': {'status': {'const': 'DRAFT'}}, 'required': ['status']},
             'then': {'properties': {'number': {'type': 'null'}, 'issued_at': {'type': 'null'}, 'ledger_version': {'type': 'null'}}}},
            {'if': {'properties': {'status': {'const': 'ISSUED'}}, 'required': ['status']},
             'then': {'properties': {'number': text(64), 'issued_at': instant(), 'issued_by': ulid(), 'ledger_version': ref('Version')}}},
        ]},
        'InvoiceSummary': obj({'id': ulid(), 'patient_id': ulid(), 'number': n_text(64), 'status': enum('invoices.status'), 'issued_at': n_instant(),
                               'total_gross': money(), 'balance_due': signed_money(), 'draft_version': ref('Version'),
                               'ledger_version': nullable(ref('Version'))}),
        'InvoiceResponse': data('Invoice'),
        'InvoicePage': page('InvoiceSummary'),
        'PaymentResult': obj({'payment_id': ulid(), 'receipt_id': ulid(), 'receipt_number': text(64), 'invoice_id': ulid(),
                              'amount': ref('PositiveMoneyXaf'), 'currency': ref('Currency'), 'method': enum('payments.method'),
                              'effective_at': instant(), 'recorded_at': instant(), 'ledger_version': ref('Version'),
                              'balance': balance, 'receipt_document': document_state},
                             description='Réponse confirmée d\'encaissement (fichier 08) : références paiement/reçu, montant, solde recalculé, état du PDF.'),
        'ReversalResult': obj({'reversal_id': ulid(), 'payment_id': ulid(), 'invoice_id': ulid(), 'amount': ref('PositiveMoneyXaf'),
                               'recorded_at': instant(), 'ledger_version': ref('Version'), 'balance': balance}),
        'CreditNoteResult': obj({'credit_note_id': ulid(), 'number': text(64), 'invoice_id': ulid(), 'amount_gross': ref('PositiveMoneyXaf'),
                                 'issued_at': instant(), 'ledger_version': ref('Version'), 'balance': balance, 'document': document_state}),
        'RefundResult': obj({'refund_id': ulid(), 'number': text(64), 'invoice_id': ulid(), 'credit_note_id': ulid(),
                             'amount': ref('PositiveMoneyXaf'), 'method': enum('refunds.method'), 'effective_at': instant(),
                             'ledger_version': ref('Version'), 'balance': balance, 'document': document_state}),
        'PaymentResultResponse': data('PaymentResult'),
        'ReversalResultResponse': data('ReversalResult'),
        'CreditNoteResultResponse': data('CreditNoteResult'),
        'RefundResultResponse': data('RefundResult'),
        'Approval': obj({'id': ulid(), 'action_code': ref('PermissionCode'), 'target_type': text(64), 'target_id': ulid(),
                         'payload_hash': ref('Sha256Hex'), 'requested_by': ulid(), 'reason': text(), 'status': enum('approvals.status'),
                         'decided_by': n_ulid(), 'decided_at': n_instant(), 'expires_at': n_instant(), 'consumed_at': n_instant()}),
        'ApprovalResponse': data('Approval'),
        'CashSession': obj({'id': ulid(), 'cash_register_id': ulid(), 'cashier_id': ulid(), 'opened_at': instant(), 'opening_amount': money(),
                            'closed_at': n_instant(), 'counted_amount': nullable(money()), 'expected_amount': nullable(money()),
                            'variance': nullable(signed_money()), 'variance_reason': n_text(), 'closed_by': n_ulid(),
                            'collected_cash': money(), 'refunded_cash': money()},
                           description='Session de caisse ; collected_cash/refunded_cash sont des projections de la session.'),
        'CashSessionResponse': data('CashSession'),
        'CurrentCashSessionResponse': obj({'data': nullable(local('CashSession'))}),
        'Delivery': obj({'id': ulid(), 'invoice_id': ulid(), 'status': enum('deliveries.status'), 'delivered_at': n_instant(),
                         'delivered_by': n_ulid(), 'credit_delivery_approval_id': n_ulid(),
                         'lines': array(obj({'id': ulid(), 'invoice_line_id': ulid(), 'item_id': ulid(), 'quantity': quantity(),
                                             'stock_position_id': n_ulid(), 'stock_lot_id': n_ulid(), 'reservation_id': n_ulid()}), 1)}),
        'DeliveryResponse': data('Delivery'),
    }
    return schema_file('schemas/resources/billing.json', 'Ressources : catalogue, ventes, approbations, caisse et remises',
                       'Pièces financières immuables, projections de solde, réponses des opérations financières.', defs)


def stock_resources():
    lot = obj({'lot_id': ulid(), 'batch_number': text(80), 'expires_on': ref('NullableCivilDate'), 'status': enum('stock_lots.status'),
               'on_hand': decimal(), 'reserved': decimal(), 'offline_allocated': decimal()})
    defs = {
        'StockAvailability': obj({'item_id': ulid(), 'location_id': ulid(), 'stock_position_id': ulid(), 'on_hand': decimal(),
                                  'reserved': decimal(), 'offline_allocated': decimal(), 'central_available': decimal(),
                                  'lots': array(lot)},
                                 description='Disponible central = physique − réservations centrales − quotas locaux non consommés (fichier 19).'),
        'StockAvailabilityPage': page('StockAvailability'),
        'StockMovement': obj({'id': ulid(), 'item_id': ulid(), 'position_id': ulid(), 'lot_id': n_ulid(), 'kind': enum('stock_movements.kind'),
                              'signed_quantity': decimal(), 'occurred_at': instant(), 'reason': text(), 'approval_id': n_ulid()}),
        'StockMovementResponse': data('StockMovement'),
        'OpeningStockResult': obj({'movements': array(local('StockMovement'), 1)}),
        'OpeningStockResponse': data('OpeningStockResult'),
        'PhysicalReturn': obj({'id': ulid(), 'delivery_line_id': ulid(), 'item_id': ulid(), 'quantity': quantity(),
                               'condition_code': enum('physical_returns.condition_code'), 'accepted_for_restock': boolean(),
                               'occurred_at': instant(), 'stock_movement_id': n_ulid(), 'credit_note_id': n_ulid()}),
        'PhysicalReturnResponse': data('PhysicalReturn'),
    }
    return schema_file('schemas/resources/stock.json', 'Ressources : stock', 'Disponibilités, mouvements, stock initial et retours physiques.', defs)


def scheduling_resources():
    defs = {
        'FollowUpPlan': obj({'id': ulid(), 'consultation_id': ulid(), 'patient_id': ulid(), 'reason': enum('follow_up_plans.reason'),
                             'recommended_date': ref('CivilDate'), 'date_origin': enum('follow_up_plans.date_origin'),
                             'reminder_enabled': boolean(), 'preferred_channel': enum('follow_up_plans.preferred_channel'),
                             'status': enum('follow_up_plans.status'), 'version': ref('Version')}),
        'FollowUpPlanResponse': data('FollowUpPlan'),
        'Appointment': obj({'id': ulid(), 'patient_id': n_ulid(), 'prospect_contact_id': n_ulid(), 'follow_up_id': n_ulid(),
                            'resource_id': ulid(), 'service_id': ulid(), 'starts_at': instant(), 'ends_at': instant(),
                            'status': enum('appointments.status'), 'source': enum('appointments.source'),
                            'administrative_reason': n_text(), 'cancel_reason': n_text(), 'version': ref('Version')},
                           description='Rendez-vous ; un rendez-vous pris hors-ligne reste PENDING_CONFIRMATION jusqu\'à validation serveur.'),
        'AppointmentResponse': data('Appointment'),
        'AppointmentPage': page('Appointment'),
        'ContactTask': obj({'id': ulid(), 'patient_id': n_ulid(), 'prospect_contact_id': n_ulid(), 'follow_up_id': n_ulid(),
                            'appointment_id': n_ulid(), 'invoice_id': n_ulid(), 'due_at': instant(), 'channel': enum('contact_tasks.channel'),
                            'status': enum('contact_tasks.status'), 'next_attempt_at': n_instant(), 'assigned_to': n_ulid(),
                            'administrative_notes': n_text(), 'version': ref('Version')}),
        'ContactTaskPage': page('ContactTask'),
        'ContactAttemptResult': obj({'attempt_id': ulid(), 'task': local('ContactTask')}),
        'ContactAttemptResultResponse': data('ContactAttemptResult'),
        'ActivityReport': obj({'from': ref('CivilDate'), 'to': ref('CivilDate'), 'visits': integer(0), 'new_patients': integer(0),
                               'appointments': integer(0), 'appointments_no_show': integer(0), 'contact_tasks_due': integer(0),
                               'contact_tasks_done': integer(0)},
                              description='Activité administrative (accueil, gérante) : comptages, aucune donnée clinique ni financière.'),
        'ActivityReportResponse': data('ActivityReport'),
        'ClinicalReport': obj({'from': ref('CivilDate'), 'to': ref('CivilDate'), 'consultations_completed': integer(0),
                               'prescriptions_validated': integer(0), 'referrals': integer(0),
                               'ametropias': array(obj({'term_code': ref('Code'), 'count': integer(0)}))},
                              description='Statistiques cliniques agrégées (R02, statistics.clinical) : comptages seulement, aucun patient identifiable.'),
        'ClinicalReportResponse': data('ClinicalReport'),
    }
    return schema_file('schemas/resources/scheduling.json', 'Ressources : suivi, agenda et tâches de contact', 'Agenda interne, contrôles recommandés et relances (L0 sans envoi externe).', defs)


def operations_resources():
    jwk = obj({'kty': {'const': 'EC'}, 'crv': {'const': 'P-256'}, 'x': text(43, 43), 'y': text(43, 43)},
              description='Clé publique ES256 du poste (JWK, jamais de composante privée « d »).')
    grant = obj({'grant_id': ulid(), 'user_id': ulid(), 'permissions_snapshot': array(ref('PermissionCode')),
                 'compartments': array({'type': 'string', 'enum': ['ADMINISTRATIVE', 'CLINICAL', 'FINANCIAL', 'MANAGER']}),
                 'rules_version': text(64), 'catalog_version': text(64), 'issued_at': instant(), 'valid_until': instant(),
                 'signed_payload': ref('CompactJws')})
    allocation = obj({'allocation_id': ulid(), 'document_type': enum('document_number_allocations.document_type'),
                      'document_year': integer(2000, 9999), 'range_start': ref('Counter'), 'range_end': ref('Counter'),
                      'last_consumed_value': nullable(ref('Counter')), 'status': enum('document_number_allocations.status')})
    quota = obj({'allocation_id': ulid(), 'stock_position_id': ulid(), 'stock_lot_id': n_ulid(), 'allocated_quantity': quantity(),
                 'consumed_quantity': decimal(), 'returned_quantity': decimal(), 'status': enum('device_stock_allocations.status')})
    defs = {
        'PublicJwk': jwk,
        'EnrolledDevice': obj({'device_id': ulid(), 'device_code': text(64), 'name': text(180), 'kid': ulid(), 'status': enum('enrolled_devices.status'),
                               'enrolled_at': instant(), 'responsible_user_id': ulid(), 'app_version': text(64)}),
        'EnrolledDeviceResponse': data('EnrolledDevice'),
        'OfflineGrant': grant,
        'NumberAllocation': allocation,
        'StockQuota': quota,
        'Preparation': obj({'device_id': ulid(), 'grants': array(grant, 1), 'number_allocations': array(allocation),
                            'stock_allocations': array(quota), 'write_owners': array(obj({'invoice_id': ulid(), 'epoch': integer(1)})),
                            'catalog': obj({'catalog_version': text(64), 'snapshot_sha256': ref('Sha256Hex'), 'file_id': ulid()}),
                            'rules_version': text(64), 'server_time': instant(), 'last_accepted_sequence': ref('Counter'),
                            'last_accepted_hash': ref('NullableSha256Hex')},
                           description='Préparation du poste (fichier 19) : grants signés par profil, plages de numéros, quotas, factures attribuées, catalogue versionné.'),
        'PreparationResponse': data('Preparation'),
        'Release': obj({'device_id': ulid(), 'closed_number_allocations': array(ulid()), 'returned_stock_allocations': array(ulid()),
                        'closed_write_owners': array(ulid()), 'released_at': instant()}),
        'ReleaseResponse': data('Release'),
        'DeviceHealth': obj({'device_id': ulid(), 'status': enum('enrolled_devices.status'), 'last_sync_at': n_instant(),
                             'last_accepted_sequence': ref('Counter'), 'last_accepted_hash': ref('NullableSha256Hex'),
                             'pending_conflicts': integer(0), 'app_version': text(64), 'grants_valid_until': n_instant(),
                             'number_ranges_low': array(ulid())}),
        'DeviceHealthResponse': data('DeviceHealth'),
        'Conflict': obj({'id': ulid(), 'command_id': ulid(), 'conflict_type': text(100), 'status': enum('sync_conflicts.status'),
                         'device_id': ulid(), 'created_at': instant(), 'resolution': n_text(), 'resolved_by': n_ulid(),
                         'resolved_at': n_instant()},
                        description='Conflit de synchronisation : les instantanés chiffrés restent côté serveur ; seuls les profils autorisés les déchiffrent.'),
        'ConflictPage': page('Conflict'),
        'ConflictResponse': data('Conflict'),
        'GeneratedDocument': obj({'id': ulid(), 'document_type': enum('generated_documents.document_type'), 'source_key': text(100),
                                  'revision_no': integer(1), 'template_version': text(64), 'status': enum('generated_documents.status'),
                                  'sha256': ref('NullableSha256Hex'), 'generated_at': n_instant(), 'failure_code': n_text(100)}),
        'GeneratedDocumentResponse': data('GeneratedDocument'),
        'ExportRequest': obj({'id': ulid(), 'export_type': enum('export_requests.export_type'), 'patient_id': n_ulid(),
                              'filters': nullable({'type': 'object'}), 'status': enum('export_requests.status'), 'requested_by': ulid(),
                              'completed_at': n_instant(), 'expires_at': n_instant(), 'error_code': n_text(100)}),
        'ExportRequestResponse': data('ExportRequest'),
        'FinancialReport': obj({'from': ref('CivilDate'), 'to': ref('CivilDate'), 'issued_total': money(), 'collected_total': money(),
                                'credited_total': money(), 'refunded_total': money(), 'outstanding_total': signed_money(),
                                'collected_by_method': array(obj({'method': enum('payments.method'), 'amount': money()})),
                                'legacy_opening_balances': money(),
                                'incomplete_devices': array(obj({'device_id': ulid(), 'last_sync_at': n_instant()}))},
                               description='Gérante : chiffre d\'affaires émis et encaissé distincts ; créances d\'ouverture papier séparées ; postes non synchronisés signalés (totaux incomplets).'),
        'FinancialReportResponse': data('FinancialReport'),
        'AuditEvent': obj({'id': ulid(), 'actor_id': n_ulid(), 'actor_name_snapshot': text(180), 'action': text(100),
                           'target_type': text(64), 'target_id': n_ulid(), 'recorded_at': instant(), 'correlation_id': text(100),
                           'reason': n_text(), 'sanitized_diff': nullable({'type': 'object'}), 'event_hash': ref('Sha256Hex')},
                          description='Événement du journal append-only (lecture gérante, audit.read) ; aucun secret dans le diff.'),
        'AuditEventPage': page('AuditEvent'),
    }
    return schema_file('schemas/resources/operations.json', 'Ressources : postes, synchronisation, documents, exports et rapports',
                       'Enrôlement et préparation des postes, santé, conflits, documents générés, exports privés, rapport financier.', defs)


def public_resources():
    defs = {
        'PublicService': obj({'id': ulid(), 'name': text(180), 'duration_minutes': integer(1)}),
        'PublicServiceList': obj({'data': array(local('PublicService'))}),
        'PublicSlot': obj({'service_id': ulid(), 'resource_id': ulid(), 'starts_at': instant(), 'ends_at': instant()}),
        'PublicSlotList': obj({'data': array(local('PublicSlot'))}),
        'BookingReceipt': obj({'public_reference': text(32), 'status': {'type': 'string', 'enum': ['RECEIVED', 'PENDING_CONFIRMATION']}},
                              description='Référence publique distincte des identifiants internes ; ne révèle jamais l\'existence d\'un dossier.'),
        'BookingReceiptResponse': data('BookingReceipt'),
    }
    return schema_file('schemas/resources/public.json', 'Ressources : réservation publique (L2)', 'Prestations réservables et créneaux administratifs, sans dossier ni nom de patient (fichier 08).', defs)


# --------------------------------------------------------------------------------------------------
# Requêtes HTTP des routes sans commande hors-ligne
# --------------------------------------------------------------------------------------------------

def requests():
    password = text(200)
    defs = {
        'LoginRequest': obj({'login': text(180), 'password': password}),
        'ReauthenticateRequest': obj({'password': password}),
        'ChangePasswordRequest': obj({'current_password': password, 'password': password, 'password_confirmation': password}),
        'CreateStaffRequest': obj({'login': text(64), 'display_name': text(180, 2), 'email': nullable({'type': 'string', 'maxLength': 254}),
                                   'password': password, 'roles': array(role(), 1, 4)}, optional=('email',)),
        'UpdateStaffRequest': obj({'expected_version': ref('Version'), 'display_name': text(180, 2),
                                   'email': nullable({'type': 'string', 'maxLength': 254}), 'active': boolean(),
                                   'roles': array(role(), 1, 4), 'reason': n_text(1000)},
                                  optional=('display_name', 'email', 'active', 'roles', 'reason')),
        'PermissionOverrideRequest': obj({'expected_version': ref('Version'), 'permission_code': ref('PermissionCode'),
                                          'effect': {'type': 'string', 'enum': ['ALLOW', 'DENY', 'CLEAR']}, 'reason': text(1000, 3),
                                          'expires_at': n_instant()}, optional=('expires_at',)),
        'ResetPasswordRequest': obj({'expected_version': ref('Version'), 'password': password, 'password_confirmation': password,
                                     'reason': text(1000, 3)}),
        'UpdateClinicSettingsRequest': obj({'name': text(180, 2), 'address': n_text(2000), 'phone': n_text(32),
                                            'email': nullable({'type': 'string', 'maxLength': 254}), 'tax_identifier': n_text(100),
                                            'professional_mentions': n_text(4000), 'document_settings': nullable({'type': 'object'})},
                                           optional=('name', 'address', 'phone', 'email', 'tax_identifier', 'professional_mentions', 'document_settings'),
                                           description='Fuseau, monnaie et production_documents_enabled sont refusés (422).'),
        'UpdatePrescriptionDraftRequest': obj({'expected_updated_at': instant(), 'changes': {'type': 'object', 'minProperties': 1}},
                                              description='Modification d\'une prescription encore DRAFT, en ligne seulement ; jeton de concurrence updated_at. Hors-ligne, une correction est une nouvelle version (CreatePrescription avec supersedes_id).'),
        'RescheduleAppointmentRequest': obj({'expected_version': ref('Version'), 'starts_at': instant(), 'ends_at': instant(),
                                             'resource_id': ulid()}, optional=('resource_id',)),
        'CancelAppointmentRequest': obj({'expected_version': ref('Version'), 'reason': text(1000, 3)}),
        'CatalogItemRequest': obj({'expected_version': nullable(ref('Version')), 'reference': text(64), 'category_code': ref('Code'),
                                   'name': text(180), 'brand': n_text(120), 'model': n_text(120), 'unit_code': ref('Code'),
                                   'quantity_integer_only': boolean(), 'sale_price': money(), 'purchase_cost': nullable(money()),
                                   'tax_rule_id': n_ulid(), 'stock_mode': enum('catalog_items.stock_mode'), 'requires_expiry': boolean(),
                                   'low_stock_threshold': nullable(quantity()), 'expiry_alert_days': nullable(integer(0, 3650)),
                                   'active': boolean(), 'public_visibility': boolean()},
                                  description='Création (expected_version null) ou modification d\'un article par la gérante (catalog.manage). Les prix réels sont fournis par le cabinet : seules des données fictives sont utilisées en développement.'),
        'TaxRuleRequest': obj({'expected_version': nullable(ref('Version')), 'code': text(40), 'label': text(180),
                               'rate_percent': nullable(ref('Rate')), 'active': boolean(), 'validate': boolean(), 'legal_note': n_text()},
                              description='Règle fiscale saisie par la gérante ; validate = true enregistre la validation (réauthentification). Aucune taxe n\'est préconfigurée.'),
        'OpeningStockRequest': obj({'counted_at': instant(), 'reason': text(1000, 3), 'approval_id': n_ulid(),
                                    'lines': array(obj({'movement_id': ulid(), 'item_id': ulid(), 'location_id': ulid(),
                                                        'lot': nullable(obj({'lot_id': ulid(), 'batch_number': text(80),
                                                                             'expires_on': ref('NullableCivilDate')})),
                                                        'quantity': quantity(), 'unit_cost': nullable(money())}), 1, 500)},
                                   description='Stock initial validé (I00) : un mouvement OPENING par ligne, lots créés au besoin ; gérante.'),
        'EnrollDeviceRequest': obj({'device_code': text(64), 'name': text(180), 'public_key_jwk': {'$ref': '../resources/operations.json#/$defs/PublicJwk'},
                                    'responsible_user_id': ulid(), 'app_version': text(64)}),
        'PrepareDeviceRequest': obj({'profiles': array(ulid(), 1, 10),
                                     'number_ranges': array(obj({'document_type': enum('document_number_allocations.document_type'),
                                                                 'quantity': integer(1, 5000)})),
                                     'stock_quotas': array(obj({'stock_position_id': ulid(), 'stock_lot_id': n_ulid(), 'quantity': quantity()})),
                                     'invoice_ids': array(ulid()), 'known_catalog_version': n_text(64)},
                                    description='Préparation en ligne (gérante) : profils à préparer, plages demandées, quotas et créances à attribuer à ce poste.'),
        'ReleaseDeviceRequest': obj({'last_device_sequence': ref('Counter'), 'last_event_hash': ref('NullableSha256Hex'), 'reason': text(1000, 3)}),
        'ConflictResolutionRequest': obj({'decision': {'type': 'string', 'enum': ['ACCEPT_LOCAL', 'KEEP_CENTRAL', 'REJECT', 'CORRECT']},
                                          'reason': text(1000, 3), 'correction_command_id': n_ulid()},
                                         description='Résolution autorisée et motivée ; ne réécrit jamais core/hash/signature ; une correction a sa propre command_id (contrat 23).'),
        'CreateExportRequest': obj({'export_type': enum('export_requests.export_type'), 'patient_id': n_ulid(), 'filters': nullable({'type': 'object'})},
                                   optional=('patient_id', 'filters')),
        'BookingRequest': obj({'service_id': ulid(), 'starts_at': instant(), 'name': text(180), 'phone': ref('Phone'),
                               'email': nullable({'type': 'string', 'maxLength': 254}), 'consent': {'const': True}},
                              optional=('email',), description='Réservation publique (L2) : consentement explicite exigé, anti-abus et idempotence.'),
    }
    return schema_file('schemas/requests/http.json', 'Requêtes HTTP des routes sans commande hors-ligne',
                       'Corps des routes en ligne ; les routes qui exécutent une commande du journal local utilisent contracts/commands/<Commande>.v1.json#/$defs/DirectRequest.', defs)


# --------------------------------------------------------------------------------------------------
# Snapshots des pièces
# --------------------------------------------------------------------------------------------------

def snapshots():
    global CURRENT
    CURRENT = S
    patient = obj({'patient_id': ulid(), 'record_number': text(64), 'last_name': text(120), 'first_names': n_text(180)})
    clinic = obj({'clinic_id': ulid(), 'name': text(180), 'address': n_text(2000), 'phone': n_text(32), 'email': n_text(254),
                  'tax_identifier': n_text(100), 'professional_mentions': n_text(4000), 'document_settings': nullable({'type': 'object'}),
                  'currency': ref('Currency'), 'timezone': text(64), 'test_document': boolean()},
                 description='En-tête du cabinet au moment de l\'émission ; test_document = true tant que production_documents_enabled est faux (document TEST clairement marqué, fichier 23).')
    tax = obj({'regime': {'type': 'string', 'enum': ['NO_TAX_CONFIGURED', 'VALIDATED_RULES']},
               'rules': array(obj({'tax_rule_id': ulid(), 'code': text(40), 'label': text(180), 'rate_percent': nullable(ref('Rate')),
                                   'validated_at': n_instant(), 'legal_note': n_text()}))},
              description='Régime fiscal figé : aucune taxe inventée ; sans règle validée, NO_TAX_CONFIGURED et liste vide.')
    invoice_ref = obj({'invoice_id': ulid(), 'number': text(64), 'issued_at': instant(), 'total_gross': money()})
    actor = obj({'user_id': ulid(), 'display_name': text(180)})
    files = []

    def snap(name, title, description, schema):
        files.append((name, {
            '$schema': 'https://json-schema.org/draft/2020-12/schema',
            '$id': BASE + 'snapshots/' + name,
            'title': title,
            'description': description + GENERATED,
            **schema,
        }))

    snap('invoice-patient.json', 'Snapshot patient d\'une facture (invoices.patient_snapshot)', 'Identité facturée minimale et référence dossier (fichier 11).', patient)
    snap('invoice-clinic.json', 'Snapshot cabinet d\'une facture (invoices.clinic_snapshot)', 'Mentions du cabinet figées à l\'émission.', clinic)
    snap('invoice-tax.json', 'Snapshot fiscal d\'une facture (invoices.tax_snapshot)', 'Règles fiscales validées appliquées, ou absence de régime configuré.', tax)
    snap('receipt.json', 'Snapshot d\'un reçu (receipts.snapshot)',
         'Contenu du reçu (fichier 11) : numéro, dates effective et d\'émission, facture, patient, versement, cumul encaissé net, reste, utilisateur ; nature calculée, jamais saisie.',
         obj({'receipt_id': ulid(), 'receipt_number': text(64), 'issued_at': instant(), 'effective_at': instant(), 'invoice': invoice_ref,
              'patient': patient, 'payment': obj({'payment_id': ulid(), 'amount': ref('PositiveMoneyXaf'), 'currency': ref('Currency'),
                                                  'method': enum('payments.method'), 'reference': n_text(180)}),
              'paid_net_before': money(), 'paid_net_after': money(), 'balance_after': signed_money(),
              'kind': {'type': 'string', 'enum': ['DEPOSIT', 'BALANCE_SETTLEMENT'],
                       'description': 'Calculée : BALANCE_SETTLEMENT si le reste après ce paiement est nul, sinon DEPOSIT (un encaissement ne dépasse jamais le solde).'},
              'cashier': actor, 'clinic': clinic, 'template_version': text(64)}))
    snap('credit-note.json', 'Snapshot d\'un avoir (credit_notes.snapshot)',
         'Avoir (fichier 11) : facture source, lignes et montants corrigés, motif, taxes selon régime, auteur et autorisation, date.',
         obj({'credit_note_id': ulid(), 'number': text(64), 'issued_at': instant(), 'invoice': invoice_ref, 'patient': patient,
              'lines': array(obj({'invoice_line_id': ulid(), 'description': text(500), 'quantity': nullable(quantity()),
                                  'amount_gross': ref('PositiveMoneyXaf'), 'tax_amount': nullable(money())}), 1),
              'amount_gross': ref('PositiveMoneyXaf'), 'reason': text(), 'issued_by': actor, 'approval_id': ulid(), 'tax': tax,
              'clinic': clinic, 'template_version': text(64)}))
    snap('refund.json', 'Snapshot d\'un remboursement (refunds.snapshot)',
         'Reçu de remboursement (fichier 11) : numéro, avoir et facture, montant, mode, référence, bénéficiaire vérifié.',
         obj({'refund_id': ulid(), 'number': text(64), 'effective_at': instant(), 'credit_note': obj({'credit_note_id': ulid(), 'number': text(64)}),
              'invoice': invoice_ref, 'beneficiary': patient, 'amount': ref('PositiveMoneyXaf'), 'currency': ref('Currency'),
              'method': enum('refunds.method'), 'reference': n_text(180), 'created_by': actor, 'clinic': clinic, 'template_version': text(64)}))
    snap('delivery-patient.json', 'Snapshot patient d\'une remise (deliveries.patient_snapshot)', 'Identité minimale du bénéficiaire de la remise.', patient)
    snap('clinical-revision.json', 'Snapshot d\'une révision clinique (clinical_revisions.snapshot)',
         'Consultation figée à la finalisation ou à la correction : toutes les rubriques, auteur, motif ; clinical_revisions.sha256 = SHA-256 de JCS(snapshot). Jamais dans une outbox de communication.',
         obj({'consultation_id': ulid(), 'revision_no': integer(1), 'recorded_at': instant(), 'author': actor, 'patient': patient,
              'visit_id': ulid(), 'effective_at': instant(), 'steps': step('AllSteps'), 'finalization_missing_data_reason': n_text(),
              'previous_revision_id': n_ulid(), 'amendment_reason': n_text()}))
    CURRENT = R
    return files


def build():
    global CURRENT
    files = {}
    for builder in (access_resources, patient_resources, clinical_resources, billing_resources, stock_resources,
                    scheduling_resources, operations_resources, public_resources, requests):
        CURRENT = R
        path, schema = builder()
        files[path] = schema
    for name, schema in snapshots():
        files['snapshots/' + name] = schema
    return files


def main():
    files = build()
    check = '--check' in sys.argv
    stale = []
    for relative, schema in files.items():
        path = CONTRACTS / relative
        text_value = json.dumps(schema, ensure_ascii=False, indent=2) + '\n'
        if check:
            if not path.exists() or path.read_text(encoding='utf-8') != text_value:
                stale.append(relative)
        else:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text_value, encoding='utf-8')
    if check:
        produced = {CONTRACTS / relative for relative in files}
        extra = sorted(str(p.relative_to(CONTRACTS)) for directory in ('schemas/resources', 'schemas/requests', 'snapshots')
                       for p in (CONTRACTS / directory).glob('*.json') if p not in produced)
        if stale or extra:
            print('ECHEC : ressources périmées ' + str(stale) + ' ou en trop ' + str(extra) + ' : relancer contracts/tools/build_resources.py', file=sys.stderr)
            sys.exit(1)
        print('Ressources, requêtes et snapshots à jour : ' + str(len(files)) + ' fichiers.')
        return
    print(str(len(files)) + ' fichiers de ressources, requêtes et snapshots écrits.')


if __name__ == '__main__':
    main()
