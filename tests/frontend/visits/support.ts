// Serveur simulé et aides de saisie pour les tests de la file du jour et de l'arrivée (P03). Données
// FICTIVES. `fetch` est remplacé : le vrai client HTTP, le vrai répartiteur de mutations et le vrai
// routeur travaillent contre des réponses au format des contrats F06 (Visit, VisitPage, VisitResponse).
import { flushPromises } from '@vue/test-utils'
import { vi, type Mock } from 'vitest'

import type { MeResponse, Visit, VisitPage } from '@/domain/contracts/generated'
import { takeArrivalFlash } from '@/pages/today/flash'

import {
  apiError,
  json,
  resetEverything as resetPatientsEverything,
  type Recorded,
} from '../patients/support'
import { ALL_TEST_MODULES, meResponse } from '../shell/server'

export {
  apiError,
  buttonByName,
  choose,
  click,
  field,
  hasField,
  ID_A,
  ID_B,
  ID_C,
  json,
  linkTo,
  openApp,
  pageOf,
  patient,
  submitForm,
  summaryOf,
  textOf,
  type,
  type Recorded,
} from '../patients/support'

export const VISIT_A = '01J9ZXK4M2Q8R7S6T5V4W30021'
export const VISIT_B = '01J9ZXK4M2Q8R7S6T5V4W30022'
export const VISIT_C = '01J9ZXK4M2Q8R7S6T5V4W30023'

/** Accueil : enregistre les arrivées, lit la file et cherche les patients. */
export const FRONT_DESK = ['visit.register', 'agenda.read', 'patient.read', 'patient.create']
/** Caisse : lit la file (agenda.read) sans enregistrer d'arrivée. */
export const CASH_DESK = ['agenda.read', 'patient.read']

export function visit(overrides: Partial<Visit> = {}): Visit {
  return {
    id: VISIT_A,
    patient_id: '01J9ZXK4M2Q8R7S6T5V4W30011',
    reason: 'EXAM',
    occurred_at: '2026-10-15T08:15:00.000Z',
    status: 'ARRIVED',
    appointment_id: null,
    exam_invoice_id: null,
    exam_waiver_approval_id: null,
    arrival_at: '2026-10-15T08:15:00.000Z',
    closed_at: null,
    administrative_note: null,
    consultation_id: null,
    consultation_status: null,
    version: 1,
    patient: {
      id: '01J9ZXK4M2Q8R7S6T5V4W30011',
      record_number: 'EE-2026-0001',
      last_name: 'PATIENT FICTIF',
      first_names: 'Élodie Marie',
      sex: 'F',
      birth_date: '1990-05-17',
    },
    exam_fee_control: 'NOT_CONTROLLED',
    ...overrides,
  }
}

export function visitPage(items: Visit[], meta: Partial<VisitPage['meta']> = {}): VisitPage {
  return {
    data: items,
    meta: { current_page: 1, last_page: 1, per_page: 50, total: items.length, ...meta },
  }
}

const META_EMPTY = { current_page: 1, last_page: 1, per_page: 25, total: 0 }

type Reply = Response | 'network'
type Handler<A extends unknown[]> = (...args: A) => Reply | Promise<Reply>

export interface VisitsApi {
  /** Réponse de GET /auth/me ; modifiable pendant le test (session expirée…). */
  me: MeResponse | 'anonymous' | 'unreachable'
  list: Handler<[day: string | null, page: number, request: Recorded]>
  register: Handler<[body: Record<string, unknown>, request: Recorded]>
  search: Handler<[query: string, page: number, request: Recorded]>
  get: Handler<[id: string, request: Recorded]>
  requests: Recorded[]
  fetchMock: Mock
}

/** Appels enregistrés pour une méthode et un chemin (expression régulière sur le chemin). */
export function callsTo(api: VisitsApi, method: string, path: RegExp): Recorded[] {
  return api.requests.filter((request) => request.method === method && path.test(request.path))
}

export function listCalls(api: VisitsApi): Recorded[] {
  return callsTo(api, 'GET', /^\/api\/v1\/visits$/)
}

export function registerCalls(api: VisitsApi): Recorded[] {
  return callsTo(api, 'POST', /^\/api\/v1\/visits$/)
}

export function stubVisitsApi(
  options: {
    permissions?: string[]
    modules?: readonly string[]
    handlers?: Partial<Pick<VisitsApi, 'list' | 'register' | 'search' | 'get'>>
  } = {},
): VisitsApi {
  const requests: Recorded[] = []
  const api = {
    me: meResponse(options.permissions ?? FRONT_DESK, 'Réception Fictive', [
      ...(options.modules ?? ALL_TEST_MODULES),
    ]),
    list: options.handlers?.list ?? (() => json(200, visitPage([]))),
    register:
      options.handlers?.register ??
      ((body) =>
        json(201, {
          data: visit({
            id: String(body['visit_id']),
            patient_id: String(body['patient_id']),
            reason: body['reason'] as Visit['reason'],
            occurred_at: String(body['occurred_at']),
          }),
        })),
    search: options.handlers?.search ?? (() => json(200, { data: [], meta: META_EMPTY })),
    get:
      options.handlers?.get ??
      (() => apiError(404, 'RESOURCE_NOT_FOUND', 'Ressource introuvable.')),
    requests,
  } as VisitsApi

  api.fetchMock = vi.fn(async (input: unknown, init?: RequestInit): Promise<Response> => {
    const url = new URL(String(input), 'https://cabinet.test')
    const method = init?.method ?? 'GET'
    const headers = (init?.headers ?? {}) as Record<string, string>
    const body =
      typeof init?.body === 'string' ? (JSON.parse(init.body) as Record<string, unknown>) : null
    const request: Recorded = { method, path: url.pathname, query: url.searchParams, body, headers }
    requests.push(request)

    const settle = async (reply: Reply | Promise<Reply>): Promise<Response> => {
      const value = await reply
      if (value === 'network') throw new TypeError('Failed to fetch')
      return value
    }

    if (url.pathname === '/up') return json(200, { status: 'ok' })
    if (url.pathname === '/api/sanctum/csrf-cookie') return new Response(null, { status: 204 })
    if (url.pathname === '/api/v1/auth/me') {
      if (api.me === 'unreachable') throw new TypeError('Failed to fetch')
      if (api.me === 'anonymous') return apiError(401, 'AUTH_REQUIRED', 'Connexion requise.')
      return json(200, api.me)
    }
    if (url.pathname === '/api/v1/visits' && method === 'GET') {
      return settle(
        api.list(
          url.searchParams.get('date'),
          Number(url.searchParams.get('page') ?? '1'),
          request,
        ),
      )
    }
    if (url.pathname === '/api/v1/visits' && method === 'POST') {
      return settle(api.register(body ?? {}, request))
    }
    if (url.pathname === '/api/v1/patients' && method === 'GET') {
      return settle(
        api.search(
          url.searchParams.get('query') ?? '',
          Number(url.searchParams.get('page') ?? '1'),
          request,
        ),
      )
    }
    const one = /^\/api\/v1\/patients\/([^/]+)$/.exec(url.pathname)
    if (one !== null && method === 'GET') {
      return settle(api.get(decodeURIComponent(one[1] ?? ''), request))
    }
    return apiError(404, 'RESOURCE_NOT_FOUND', 'Ressource introuvable.')
  })
  vi.stubGlobal('fetch', api.fetchMock)
  return api
}

/** Remet à zéro tout l'état partagé des modules entre deux tests (applications démontées comprises). */
export function resetEverything(): void {
  resetPatientsEverything()
  takeArrivalFlash()
}

/** Saisie dans le champ de choix du patient : texte, attente du délai de recherche, réponse. */
export async function searchPatientField(
  root: ParentNode,
  text: string,
): Promise<HTMLInputElement> {
  const input = root.querySelector<HTMLInputElement>('input[role="combobox"]')
  if (input === null) throw new Error('Champ de choix du patient introuvable.')
  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
  vi.advanceTimersByTime(250)
  await flushPromises()
  return input
}

/** Choisit le résultat de rang `index` au clavier (flèche bas puis Entrée). */
export async function chooseResult(input: HTMLInputElement, index = 0): Promise<void> {
  for (let step = 0; step <= index; step += 1) {
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }))
    await flushPromises()
  }
  input.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
  )
  await flushPromises()
}
