// Serveur simulé et aides de saisie pour les tests des écrans patient (P01, P02). Données FICTIVES.
// `fetch` est remplacé : le vrai client HTTP, le vrai répartiteur de mutations et le vrai routeur
// travaillent contre des réponses au format des contrats F06.
import { flushPromises } from '@vue/test-utils'
import { createMemoryHistory } from 'vue-router'
import { vi, type Mock } from 'vitest'

import { createEeoApp } from '@/app/bootstrap'
import { resetNetworkForTests } from '@/app/network'
import { MUTATION_DISPATCHER_KEY, type MutationDispatcher } from '@/app/services/mutations'
import { resetSessionForTests } from '@/app/session'
import type { MeResponse, Patient, PatientPage, PatientSummary } from '@/domain/contracts/generated'
import { resetSearchMemoryForTests } from '@/pages/patients/searchMemory'
import { takeFlash } from '@/pages/patients/flash'

import { ALL_TEST_MODULES, meResponse } from '../shell/server'

export const ID_A = '01J9ZXK4M2Q8R7S6T5V4W30011'
export const ID_B = '01J9ZXK4M2Q8R7S6T5V4W30012'
export const ID_C = '01J9ZXK4M2Q8R7S6T5V4W30013'

export const READER = ['patient.read']
export const RECEPTION = ['patient.read', 'patient.create', 'patient.update']

export function patient(overrides: Partial<Patient> = {}): Patient {
  return {
    id: ID_A,
    record_number: 'EE-2026-0001',
    last_name: 'PATIENT FICTIF',
    first_names: 'Élodie Marie',
    sex: 'F',
    birth_date: '1990-05-17',
    phone_primary: '+237600000001',
    archived: false,
    merged_into_patient_id: null,
    version: 3,
    birth_date_missing_reason: null,
    phone_secondary: null,
    phone_missing_reason: null,
    address: 'Quartier fictif\nDouala',
    profession: 'Enseignante',
    guardian_contact: null,
    legacy_record: false,
    first_known_visit_on: null,
    first_visit_certified: false,
    legacy_record_number: null,
    created_at: '2026-10-14T09:30:00.000Z',
    updated_at: '2026-10-14T09:30:00.000Z',
    ...overrides,
  }
}

export function summaryOf(source: Patient): PatientSummary {
  return {
    id: source.id,
    record_number: source.record_number,
    last_name: source.last_name,
    first_names: source.first_names,
    sex: source.sex,
    birth_date: source.birth_date,
    phone_primary: source.phone_primary,
    archived: source.archived,
    merged_into_patient_id: source.merged_into_patient_id,
    version: source.version,
  }
}

export function pageOf(
  items: PatientSummary[],
  meta: Partial<PatientPage['meta']> = {},
): PatientPage {
  return {
    data: items,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: items.length, ...meta },
  }
}

export function json(
  status: number,
  body: unknown,
  headers: Record<string, string> = {},
): Response {
  return new Response(body === null ? null : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  })
}

export function apiError(
  status: number,
  code: string,
  message: string,
  fields?: Record<string, string[]>,
): Response {
  return json(status, {
    error: { code, message, ...(fields === undefined ? {} : { fields }) },
    correlation_id: 'corr-test-0100',
  })
}

export function hostPage503(): Response {
  return new Response('<!DOCTYPE html><html><body><h1>503</h1></body></html>', {
    status: 503,
    headers: { 'Content-Type': 'text/html' },
  })
}

export interface Recorded {
  method: string
  path: string
  query: URLSearchParams
  body: Record<string, unknown> | null
  headers: Record<string, string>
}

type Reply = Response | 'network'
type Handler<A extends unknown[]> = (...args: A) => Reply | Promise<Reply>

export interface PatientsApi {
  /** Réponse de GET /auth/me ; modifiable pendant le test (session expirée…). */
  me: MeResponse | 'anonymous' | 'unreachable'
  search: Handler<[query: string, page: number, request: Recorded]>
  get: Handler<[id: string, request: Recorded]>
  create: Handler<[body: Record<string, unknown>, request: Recorded]>
  update: Handler<[id: string, body: Record<string, unknown>, request: Recorded]>
  /** GET /patients/{id}/activity (onglet « Activité ») : filtre `section` (null sans filtre) et page. */
  activity: Handler<[id: string, section: string | null, page: number, request: Recorded]>
  requests: Recorded[]
  fetchMock: Mock
}

/** Appels enregistrés pour une méthode et un chemin (expression régulière sur le chemin). */
export function callsTo(api: PatientsApi, method: string, path: RegExp): Recorded[] {
  return api.requests.filter((request) => request.method === method && path.test(request.path))
}

export function stubPatientsApi(
  options: {
    permissions?: string[]
    modules?: readonly string[]
    handlers?: Partial<Pick<PatientsApi, 'search' | 'get' | 'create' | 'update' | 'activity'>>
  } = {},
): PatientsApi {
  const requests: Recorded[] = []
  const api = {
    me: meResponse(options.permissions ?? RECEPTION, 'Réception Fictive', [
      ...(options.modules ?? ALL_TEST_MODULES),
    ]),
    search: options.handlers?.search ?? (() => json(200, pageOf([]))),
    get:
      options.handlers?.get ??
      (() => apiError(404, 'RESOURCE_NOT_FOUND', 'Ressource introuvable.')),
    create:
      options.handlers?.create ??
      ((body) =>
        json(201, {
          data: { patient_id: body['patient_id'], record_number: 'EE-2026-0001', version: 1 },
        })),
    update:
      options.handlers?.update ??
      ((_id, body) => json(200, { data: { patient_id: body['patient_id'], version: 4 } })),
    activity:
      options.handlers?.activity ??
      (() => apiError(404, 'RESOURCE_NOT_FOUND', 'Ressource introuvable.')),
    requests,
  } as PatientsApi

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
    if (url.pathname === '/api/v1/patients' && method === 'GET') {
      return settle(
        api.search(
          url.searchParams.get('query') ?? '',
          Number(url.searchParams.get('page') ?? '1'),
          request,
        ),
      )
    }
    if (url.pathname === '/api/v1/patients' && method === 'POST') {
      return settle(api.create(body ?? {}, request))
    }
    const activity = /^\/api\/v1\/patients\/([^/]+)\/activity$/.exec(url.pathname)
    if (activity !== null && method === 'GET') {
      return settle(
        api.activity(
          decodeURIComponent(activity[1] ?? ''),
          url.searchParams.get('section'),
          Number(url.searchParams.get('page') ?? '1'),
          request,
        ),
      )
    }
    const one = /^\/api\/v1\/patients\/([^/]+)$/.exec(url.pathname)
    if (one !== null) {
      const id = decodeURIComponent(one[1] ?? '')
      if (method === 'GET') return settle(api.get(id, request))
      if (method === 'PATCH') return settle(api.update(id, body ?? {}, request))
    }
    return apiError(404, 'RESOURCE_NOT_FOUND', 'Ressource introuvable.')
  })
  vi.stubGlobal('fetch', api.fetchMock)
  return api
}

/** Applications montées par le test en cours : démontées même si le test échoue avant `unmount()`. */
const mounted: (() => void)[] = []

export async function openApp(path: string, options: { dispatcher?: MutationDispatcher } = {}) {
  const { app, router } = createEeoApp(createMemoryHistory())
  if (options.dispatcher !== undefined) app.provide(MUTATION_DISPATCHER_KEY, options.dispatcher)
  await router.push(path)
  await router.isReady()
  const root = document.createElement('div')
  document.body.append(root)
  app.mount(root)
  let stopped = false
  const unmount = (): void => {
    if (stopped) return
    stopped = true
    app.unmount()
  }
  mounted.push(unmount)
  await flushPromises()
  return { root, router, app, unmount }
}

/** Remet à zéro tout l'état partagé des modules entre deux tests. */
export function resetEverything(): void {
  // Une application restée montée réagirait à l'état partagé du test suivant.
  for (const unmountApp of mounted.splice(0)) unmountApp()
  resetSessionForTests()
  resetNetworkForTests()
  resetSearchMemoryForTests()
  takeFlash()
  document.body.innerHTML = ''
}

// --- Saisie comme l'utilisateur -------------------------------------------------------------------

export function field(
  root: ParentNode,
  label: string,
): HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement {
  const labelElement = [...root.querySelectorAll('label')].find((candidate) =>
    candidate.textContent?.trim().startsWith(label),
  )
  const id = labelElement?.getAttribute('for')
  const element = id === undefined || id === null ? null : root.querySelector(`[id="${id}"]`)
  if (element === null) throw new Error(`Champ « ${label} » introuvable.`)
  return element as HTMLInputElement
}

export function hasField(root: ParentNode, label: string): boolean {
  return [...root.querySelectorAll('label')].some((candidate) =>
    candidate.textContent?.trim().startsWith(label),
  )
}

export async function type(root: ParentNode, label: string, value: string): Promise<void> {
  const element = field(root, label)
  element.value = value
  element.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

/** Date de naissance : saisie puis sortie du champ (lecture par les règles de date). */
export async function typeDate(root: ParentNode, label: string, value: string): Promise<void> {
  const element = field(root, label)
  element.value = value
  element.dispatchEvent(new Event('input', { bubbles: true }))
  element.dispatchEvent(new Event('blur'))
  await flushPromises()
}

export async function choose(root: ParentNode, label: string, value: string): Promise<void> {
  const element = field(root, label)
  element.value = value
  element.dispatchEvent(new Event('change', { bubbles: true }))
  await flushPromises()
}

export async function submitForm(root: ParentNode): Promise<void> {
  const form = root.querySelector('main form[novalidate]:not([role="search"])')
  if (form === null) throw new Error('Formulaire introuvable.')
  form.dispatchEvent(new Event('submit', { cancelable: true }))
  await flushPromises()
}

export function buttonByName(root: ParentNode, name: string | RegExp): HTMLButtonElement | null {
  return (
    ([...root.querySelectorAll('button')].find((button) => {
      const text = button.textContent?.replace(/\s+/g, ' ').trim() ?? ''
      return typeof name === 'string' ? text === name : name.test(text)
    }) as HTMLButtonElement | undefined) ?? null
  )
}

export async function click(element: Element | null): Promise<void> {
  if (element === null) throw new Error('Élément à cliquer introuvable.')
  ;(element as HTMLElement).click()
  await flushPromises()
}

export function textOf(root: ParentNode): string {
  return (root.textContent ?? '').replace(/\s+/g, ' ')
}

export function linkTo(root: ParentNode, href: string): HTMLAnchorElement | null {
  return root.querySelector(`a[href="${href}"]`)
}

/** Remplit le minimum valide d'une création : nom, téléphone, naissance. */
export async function fillValidIdentity(root: ParentNode): Promise<void> {
  await type(root, 'Nom', 'PATIENT FICTIF')
  await type(root, 'Prénom(s)', 'Élodie Marie')
  await typeDate(root, 'Date de naissance', '17/05/1990')
  await type(root, 'Téléphone principal', '+237 600 00 00 01')
}
