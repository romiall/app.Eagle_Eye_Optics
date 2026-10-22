// Réponses FICTIVES de GET /patients/{id}/activity (onglet « Activité »), au format du contrat
// PatientActivity : une variante par type d'élément, sans aucune valeur clinique.
import type {
  PatientActivity,
  PatientActivityConsultation,
  PatientActivityInvoice,
  PatientActivityItem,
  PatientActivityMedicationOrder,
  PatientActivityMeta,
  PatientActivityPrescription,
  PatientActivityReceipt,
  PatientActivitySection,
  PatientActivityVisit,
} from '@/domain/contracts/generated'

export const CONSULTATION_ID = '01J9ZXK4M2Q8R7S6T5V4W30501'
export const INVOICE_ID = '01J9ZXK4M2Q8R7S6T5V4W30601'

/** Droits de la gérante utiles à l'activité (lecture d'identité, clinique et pièces). */
export const MANAGER_READ = ['patient.read', 'patient.update', 'clinical.read', 'invoice.read']
export const CLINICIAN_READ = ['patient.read', 'clinical.read']
export const CASHIER_READ = ['patient.read', 'invoice.read']

export function visitItem(overrides: Partial<PatientActivityVisit> = {}): PatientActivityVisit {
  return {
    type: 'VISIT',
    id: '01J9ZXK4M2Q8R7S6T5V4W30401',
    occurred_at: '2026-10-15T09:00:00.000Z',
    screen_path: null,
    reason: 'PICKUP',
    status: 'ARRIVED',
    ...overrides,
  }
}

export function consultationItem(
  overrides: Partial<PatientActivityConsultation> = {},
): PatientActivityConsultation {
  return {
    type: 'CONSULTATION',
    id: CONSULTATION_ID,
    occurred_at: '2026-10-01T07:10:00.000Z',
    screen_path: `/consultations/${CONSULTATION_ID}`,
    status: 'COMPLETED',
    clinician_name: 'Professionnel Fictif',
    ...overrides,
  }
}

export function prescriptionItem(
  overrides: Partial<PatientActivityPrescription> = {},
): PatientActivityPrescription {
  return {
    type: 'PRESCRIPTION',
    id: '01J9ZXK4M2Q8R7S6T5V4W30701',
    occurred_at: '2026-10-01T07:40:00.000Z',
    screen_path: `/consultations/${CONSULTATION_ID}/prescriptions`,
    origin: 'INTERNAL',
    status: 'VALIDATED',
    version_no: 2,
    consultation_id: CONSULTATION_ID,
    external_prescribed_on: null,
    ...overrides,
  }
}

export function medicationItem(
  overrides: Partial<PatientActivityMedicationOrder> = {},
): PatientActivityMedicationOrder {
  return {
    type: 'MEDICATION_ORDER',
    id: CONSULTATION_ID,
    occurred_at: '2026-10-01T07:10:00.000Z',
    screen_path: `/consultations/${CONSULTATION_ID}`,
    consultation_id: CONSULTATION_ID,
    consultation_status: 'COMPLETED',
    ...overrides,
  }
}

export function invoiceItem(
  overrides: Partial<PatientActivityInvoice> = {},
): PatientActivityInvoice {
  return {
    type: 'INVOICE',
    id: INVOICE_ID,
    occurred_at: '2026-10-01T08:00:00.000Z',
    screen_path: null,
    number: 'TEST-FAC-000001',
    origin: 'NATIVE',
    total_gross: 50000,
    paid_net: 20000,
    balance_due: 30000,
    credit_available: 0,
    settlement: 'PARTIALLY_PAID',
    ...overrides,
  }
}

export function receiptItem(
  overrides: Partial<PatientActivityReceipt> = {},
): PatientActivityReceipt {
  return {
    type: 'RECEIPT',
    id: '01J9ZXK4M2Q8R7S6T5V4W30801',
    occurred_at: '2026-10-01T08:05:00.000Z',
    screen_path: null,
    number: 'TEST-REC-000001',
    amount: 20000,
    method: 'MTN_MOMO',
    reversed_amount: 0,
    invoice_id: INVOICE_ID,
    invoice_number: 'TEST-FAC-000001',
    ...overrides,
  }
}

export function sectionsOf(
  totals: Partial<Record<PatientActivitySection['section'], number>>,
): PatientActivitySection[] {
  return (['VISITS', 'EXAMS', 'PRESCRIPTIONS', 'FINANCIAL'] as const)
    .filter((code) => totals[code] !== undefined)
    .map((code) => ({ section: code, total: totals[code] ?? 0 }))
}

/** Réponse de l'activité : `sections` = sections visibles du compte et leurs comptes. */
export function activityOf(
  items: PatientActivityItem[],
  sections: PatientActivitySection[],
  meta: Partial<PatientActivityMeta> = {},
): PatientActivity {
  return {
    data: items,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 25,
      total: items.length,
      section: null,
      sections,
      ...meta,
    },
  }
}

/** Activité complète de la gérante : 2 visites, 1 examen, 2 ordonnances, 2 pièces. */
export function managerActivity(): PatientActivity {
  return activityOf(
    [
      visitItem(),
      receiptItem(),
      invoiceItem(),
      prescriptionItem(),
      medicationItem(),
      consultationItem(),
      visitItem({
        id: '01J9ZXK4M2Q8R7S6T5V4W30402',
        occurred_at: '2026-10-01T07:00:00.000Z',
        reason: 'EXAM',
        status: 'COMPLETED',
      }),
    ],
    sectionsOf({ VISITS: 2, EXAMS: 1, PRESCRIPTIONS: 2, FINANCIAL: 2 }),
  )
}
