// Serveur simulé et données FICTIVES des écrans de vente, de caisse et de stock (B01 à B05, I00).
// `fetch` est remplacé : le vrai client HTTP, le vrai répartiteur de mutations et le vrai routeur
// travaillent contre des réponses au format des contrats (`contracts/schemas/resources/billing.json`).
import { flushPromises } from '@vue/test-utils'
import { vi, type Mock } from 'vitest'

import type {
  CashRegister,
  CashSession,
  CatalogItem,
  Invoice,
  MeResponse,
  Patient,
  PaymentResult,
  Receipt,
} from '@/domain/contracts/generated'
import { FORBIDDEN_SETTLEMENT_WORDS } from '@/domain/billing/payment'
import { takeSalesFlash } from '@/pages/sales/flash'

import { patient as patientRecord, resetEverything as resetPatients } from '../patients/support'
import { ALL_TEST_MODULES, meResponse } from '../shell/server'

export { apiError, buttonByName, json, openApp, textOf } from '../patients/support'

/**
 * Plusieurs tours de la file des promesses : un envoi enchaîne lecture de la réponse, issue du
 * répartiteur, relecture et navigation.
 */
export async function settle(rounds = 6): Promise<void> {
  for (let round = 0; round < rounds; round += 1) await flushPromises()
}

/**
 * Clic comme l'utilisateur. Un bouton d'envoi soumet son formulaire (événement `submit` annulable, comme
 * l'envoi implicite du navigateur) : jsdom n'est pas garanti sur l'activation des boutons d'envoi.
 */
export async function click(element: Element | null): Promise<void> {
  if (element === null) throw new Error('Élément à cliquer introuvable.')
  const form = element instanceof HTMLButtonElement && element.type === 'submit' ? element.form : null
  if (form !== null) {
    if (!element.hasAttribute('disabled')) {
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    }
  } else {
    ;(element as HTMLElement).click()
  }
  await settle()
}

export const PATIENT_ID = '01J9ZXK4M2Q8R7S6T5V4W30001'
export const VISIT_ID = '01J9ZXK4M2Q8R7S6T5V4W3000A'
export const INVOICE_ID = '01J9ZXK4M2Q8R7S6T5V4W30026'
export const REGISTER_ID = '01J9ZXK4M2Q8R7S6T5V4W3003R'
export const SESSION_ID = '01J9ZXK4M2Q8R7S6T5V4W3002Y'
export const ITEM_ID = '01J9ZXK4M2Q8R7S6T5V4W30C01'
export const RECEIPT_ID = '01J9ZXK4M2Q8R7S6T5V4W3002V'
export const PAYMENT_ID = '01J9ZXK4M2Q8R7S6T5V4W3002T'

/** Caissière du référentiel : lecture patient, catalogue, factures, encaissement, sa caisse. */
export const CASHIER = [
  'patient.read',
  'agenda.read',
  'catalog.read',
  'invoice.read',
  'invoice.create',
  'invoice.issue',
  'payment.collect',
  'stock.read',
  'cash.own_session',
]

/** Gérante : autorité de prix, catalogue, règles fiscales, stock initial, clôture d'une autre caisse. */
export const MANAGER = [
  ...CASHIER,
  'patient.create',
  'catalog.manage',
  'catalog.purchase_cost.read',
  'cash.manage',
  'stock.adjust',
  'approval.decide',
]

export function salesPatient(overrides: Partial<Patient> = {}): Patient {
  return patientRecord({
    id: PATIENT_ID,
    record_number: 'TEST-P-000001',
    last_name: 'PATIENT FICTIF',
    first_names: 'Élodie Marie',
    ...overrides,
  })
}

export function catalogItem(overrides: Partial<CatalogItem> = {}): CatalogItem {
  return {
    id: ITEM_ID,
    reference: 'DEMO-MONTURE',
    category_code: 'FRAME',
    name: 'Monture fictive (démonstration)',
    brand: 'MARQUE FICTIVE',
    model: null,
    unit_code: 'UNIT',
    quantity_integer_only: true,
    sale_price: 40000,
    tax_rule_id: null,
    stock_mode: 'NONE',
    requires_expiry: false,
    low_stock_threshold: null,
    expiry_alert_days: null,
    active: true,
    public_visibility: false,
    version: 1,
    ...overrides,
  }
}

export function draftInvoice(overrides: Partial<Invoice> = {}): Invoice {
  return {
    id: INVOICE_ID,
    patient_id: PATIENT_ID,
    visit_id: null,
    number: null,
    status: 'DRAFT',
    issued_at: null,
    issued_by: null,
    issued_by_name: null,
    due_date: null,
    currency: 'XAF',
    total_gross: 80000,
    origin: 'NATIVE',
    legacy_reference: null,
    draft_version: 1,
    ledger_version: null,
    balance: {
      total_gross: 80000,
      paid_net: 0,
      credited: 0,
      refunded: 0,
      balance_due: 0,
      credit_available: 0,
      settlement: 'NOT_ISSUED',
    },
    lines: [
      {
        id: '01J9ZXK4M2Q8R7S6T5V4W3001W',
        position: 1,
        catalog_item_id: ITEM_ID,
        reference_snapshot: 'DEMO-MONTURE',
        description_snapshot: 'Monture fictive (démonstration)',
        category_snapshot: 'FRAME',
        unit_snapshot: 'UNIT',
        quantity: '2.000',
        unit_price_gross: 40000,
        discount_gross: 0,
        line_total_gross: 80000,
        tax_code_snapshot: 'NO_TAX_CONFIGURED',
        tax_rate_snapshot: null,
        tax_amount: null,
        prescription_id: null,
      },
    ],
    payments: [],
    credit_notes: [],
    refunds: [],
    write_owner: null,
    document: null,
    patient_snapshot: null,
    clinic_snapshot: null,
    tax_snapshot: null,
    ...overrides,
  }
}

const CLINIC_SNAPSHOT = {
  clinic_id: '01J9ZXK4M2Q8R7S6T5V4W30069',
  name: 'CABINET DE DÉMONSTRATION — DONNÉES FICTIVES',
  address: null,
  phone: null,
  email: null,
  tax_identifier: null,
  professional_mentions: null,
  document_settings: null,
  currency: 'XAF' as const,
  timezone: 'Africa/Douala',
  test_document: true,
}

/** Facture émise de 80 000, avec `paid` déjà encaissé (projection du serveur). */
export function issuedInvoice(paid = 0, overrides: Partial<Invoice> = {}): Invoice {
  const draft = draftInvoice()
  return {
    ...draft,
    number: 'TEST-FAC-2026-000001',
    status: 'ISSUED',
    issued_at: '2026-10-15T09:00:00.000Z',
    issued_by: '01J9ZXK4M2Q8R7S6T5V4W30002',
    issued_by_name: 'Caisse (démonstration)',
    draft_version: 2,
    ledger_version: 1,
    balance: {
      total_gross: 80000,
      paid_net: paid,
      credited: 0,
      refunded: 0,
      balance_due: 80000 - paid,
      credit_available: 0,
      settlement: paid === 0 ? 'UNPAID' : paid === 80000 ? 'SETTLED' : 'PARTIALLY_PAID',
    },
    document: { document_id: '01J9ZXK4M2Q8R7S6T5V4W30029', document_status: 'PENDING' },
    patient_snapshot: {
      patient_id: PATIENT_ID,
      record_number: 'TEST-P-000001',
      last_name: 'PATIENT FICTIF',
      first_names: 'Élodie Marie',
    },
    clinic_snapshot: CLINIC_SNAPSHOT,
    tax_snapshot: { regime: 'NO_TAX_CONFIGURED', rules: [] },
    ...overrides,
  }
}

export function paymentResult(overrides: Partial<PaymentResult> = {}): PaymentResult {
  return {
    payment_id: PAYMENT_ID,
    receipt_id: RECEIPT_ID,
    receipt_number: 'TEST-REC-2026-000001',
    invoice_id: INVOICE_ID,
    amount: 30000,
    currency: 'XAF',
    method: 'CASH',
    effective_at: '2026-10-15T09:00:00.000Z',
    recorded_at: '2026-10-15T09:00:00.000Z',
    ledger_version: 2,
    balance: {
      total_gross: 80000,
      paid_net: 30000,
      credited: 0,
      refunded: 0,
      balance_due: 50000,
      credit_available: 0,
      settlement: 'PARTIALLY_PAID',
    },
    receipt_document: { document_id: '01J9ZXK4M2Q8R7S6T5V4W3002X', document_status: 'PENDING' },
    ...overrides,
  }
}

export function receipt(
  overrides: { id?: string; number?: string; amount?: number; before?: number } = {},
): Receipt {
  const amount = overrides.amount ?? 30000
  const before = overrides.before ?? 0
  const id = overrides.id ?? RECEIPT_ID
  const number = overrides.number ?? 'TEST-REC-2026-000001'
  return {
    id,
    payment_id: PAYMENT_ID,
    invoice_id: INVOICE_ID,
    number,
    issued_at: '2026-10-15T09:00:00.000Z',
    issued_by: '01J9ZXK4M2Q8R7S6T5V4W30002',
    snapshot: {
      receipt_id: id,
      receipt_number: number,
      issued_at: '2026-10-15T09:00:00.000Z',
      effective_at: '2026-10-15T09:00:00.000Z',
      invoice: {
        invoice_id: INVOICE_ID,
        number: 'TEST-FAC-2026-000001',
        issued_at: '2026-10-15T09:00:00.000Z',
        total_gross: 80000,
      },
      patient: {
        patient_id: PATIENT_ID,
        record_number: 'TEST-P-000001',
        last_name: 'PATIENT FICTIF',
        first_names: 'Élodie Marie',
      },
      payment: { payment_id: PAYMENT_ID, amount, currency: 'XAF', method: 'CASH', reference: null },
      paid_net_before: before,
      paid_net_after: before + amount,
      balance_after: 80000 - before - amount,
      kind: 80000 - before - amount === 0 ? 'BALANCE_SETTLEMENT' : 'DEPOSIT',
      cashier: { user_id: '01J9ZXK4M2Q8R7S6T5V4W30002', display_name: 'Caisse (démonstration)' },
      clinic: CLINIC_SNAPSHOT,
      template_version: 'receipt.v1',
    },
    document: { document_id: '01J9ZXK4M2Q8R7S6T5V4W3002X', document_status: 'PENDING' },
  }
}

export const REGISTERS: CashRegister[] = [
  { id: REGISTER_ID, code: 'CAISSE-PRINCIPALE', name: 'Caisse principale', active: true },
]

export function openSession(overrides: Partial<CashSession> = {}): CashSession {
  return {
    id: SESSION_ID,
    cash_register_id: REGISTER_ID,
    cashier_id: '01J9ZXK4M2Q8R7S6T5V4W30002',
    opened_at: '2026-10-15T07:00:00.000Z',
    opening_amount: 25000,
    closed_at: null,
    counted_amount: null,
    expected_amount: null,
    variance: null,
    variance_reason: null,
    closed_by: null,
    collected_cash: 80500,
    refunded_cash: 0,
    theoretical_cash: 105500,
    ...overrides,
  }
}

// --- Serveur simulé ---------------------------------------------------------------------------------

export interface Recorded {
  method: string
  path: string
  query: URLSearchParams
  body: Record<string, unknown> | null
  headers: Record<string, string>
}

type Reply = Response | 'network'
export type RouteHandler = (request: Recorded, match: RegExpExecArray) => Reply | Promise<Reply>

export interface SalesApi {
  me: MeResponse | 'anonymous'
  requests: Recorded[]
  fetchMock: Mock
  /** Ajoute (ou remplace : la dernière déclarée l'emporte) une route simulée. */
  on: (method: string, path: RegExp, handler: RouteHandler) => void
}

function jsonResponse(status: number, body: unknown): Response {
  return new Response(body === null ? null : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function notFound(): Response {
  return jsonResponse(404, {
    error: { code: 'RESOURCE_NOT_FOUND', message: 'Ressource introuvable.' },
    correlation_id: 'corr-test-0200',
  })
}

export function stubSalesApi(
  options: { permissions?: string[]; modules?: readonly string[] } = {},
): SalesApi {
  const requests: Recorded[] = []
  const routes: { method: string; path: RegExp; handler: RouteHandler }[] = []
  const api: SalesApi = {
    me: meResponse(options.permissions ?? CASHIER, 'Caisse Fictive', [
      ...(options.modules ?? ALL_TEST_MODULES),
    ]),
    requests,
    fetchMock: vi.fn(),
    on: (method, path, handler) => {
      routes.push({ method, path, handler })
    },
  }

  api.fetchMock = vi.fn(async (input: unknown, init?: RequestInit): Promise<Response> => {
    const url = new URL(String(input), 'https://cabinet.test')
    const method = init?.method ?? 'GET'
    const headers = (init?.headers ?? {}) as Record<string, string>
    const body =
      typeof init?.body === 'string' ? (JSON.parse(init.body) as Record<string, unknown>) : null
    const request: Recorded = { method, path: url.pathname, query: url.searchParams, body, headers }
    requests.push(request)

    if (url.pathname === '/up') return jsonResponse(200, { status: 'ok' })
    if (url.pathname === '/api/sanctum/csrf-cookie') return new Response(null, { status: 204 })
    if (url.pathname === '/api/v1/auth/me') {
      if (api.me === 'anonymous') {
        return jsonResponse(401, {
          error: { code: 'AUTH_REQUIRED', message: 'Connexion requise.' },
          correlation_id: 'corr-test-0201',
        })
      }
      return jsonResponse(200, api.me)
    }
    for (let index = routes.length - 1; index >= 0; index -= 1) {
      const route = routes[index]
      if (route === undefined || route.method !== method) continue
      const match = route.path.exec(url.pathname)
      if (match === null) continue
      const reply = await route.handler(request, match)
      if (reply === 'network') throw new TypeError('Failed to fetch')
      return reply
    }
    return notFound()
  })
  vi.stubGlobal('fetch', api.fetchMock)
  return api
}

/** Appels enregistrés pour une méthode et un chemin. */
export function callsTo(api: SalesApi, method: string, path: RegExp): Recorded[] {
  return api.requests.filter((request) => request.method === method && path.test(request.path))
}

/** Remet à zéro l'état partagé entre deux tests (applications, session, messages). */
export function resetSales(): void {
  resetPatients()
  takeSalesFlash()
}

/** Saisie dans un champ de choix (combobox) : texte, attente du délai de recherche, réponse. */
export async function searchCombobox(
  root: ParentNode,
  controlId: string,
  text: string,
): Promise<HTMLInputElement> {
  const input = root.querySelector<HTMLInputElement>(`[id="${controlId}"]`)
  if (input === null) throw new Error(`Champ de choix ${controlId} introuvable.`)
  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
  vi.advanceTimersByTime(250)
  await flushPromises()
  return input
}

/** Choisit le premier résultat d'un champ de choix au clavier (flèche bas puis Entrée). */
export async function chooseFirst(input: HTMLInputElement): Promise<void> {
  input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }))
  await flushPromises()
  input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
  await flushPromises()
}

/** Saisie dans un champ par son identifiant, puis sortie du champ (lecture des montants, décimaux). */
export async function typeInto(root: ParentNode, controlId: string, value: string): Promise<void> {
  const element = root.querySelector<HTMLInputElement | HTMLTextAreaElement>(`[id="${controlId}"]`)
  if (element === null) throw new Error(`Champ ${controlId} introuvable.`)
  element.value = value
  element.dispatchEvent(new Event('input', { bubbles: true }))
  element.dispatchEvent(new Event('blur'))
  await flushPromises()
}

export async function selectInto(root: ParentNode, controlId: string, value: string): Promise<void> {
  const element = root.querySelector<HTMLSelectElement>(`[id="${controlId}"]`)
  if (element === null) throw new Error(`Liste ${controlId} introuvable.`)
  element.value = value
  element.dispatchEvent(new Event('change', { bubbles: true }))
  await flushPromises()
}

/** Bouton de la boîte de confirmation ouverte (`<dialog>`). */
export function dialogButton(root: ParentNode, label: string): HTMLButtonElement | null {
  const dialog = root.querySelector('dialog[open]') ?? root.querySelector('dialog')
  if (dialog === null) return null
  return (
    ([...dialog.querySelectorAll('button')].find(
      (button) => button.textContent?.replace(/\s+/g, ' ').trim() === label,
    ) as HTMLButtonElement | undefined) ?? null
  )
}

/** Aucun mot de règlement interdit (payé, soldé, acquitté, gratuit) dans le texte rendu. */
export function forbiddenWordsIn(root: ParentNode): string[] {
  const text = (root.textContent ?? '').toLowerCase()
  return FORBIDDEN_SETTLEMENT_WORDS.filter((word) => text.includes(word))
}

export { jsonResponse }
