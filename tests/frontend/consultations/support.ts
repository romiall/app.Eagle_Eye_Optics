// Serveur simulé et aides de saisie pour les tests de la consultation par étapes (C01) et du démarrage
// de l'examen depuis la file du jour. Données FICTIVES. `fetch` est remplacé : le vrai client HTTP, le
// vrai répartiteur de mutations et le vrai routeur travaillent contre des réponses au format des
// contrats F06 (Consultation, ConsultationResponse, Visit, VisitPage, Patient). Le serveur simulé est
// ÉTATFUL : un enregistrement d'étape change la consultation (version, état, étape) que la lecture
// suivante rend, comme le serveur réel.
import { flushPromises } from '@vue/test-utils'
import { vi, type Mock } from 'vitest'

import type { Consultation, MeResponse, Patient, Visit } from '@/domain/contracts/generated'

import {
  apiError,
  buttonByName,
  click,
  field,
  hasField,
  ID_A,
  json,
  linkTo,
  openApp,
  patient as basePatient,
  textOf,
  type,
  type Recorded,
} from '../patients/support'
import { ALL_TEST_MODULES, meResponse } from '../shell/server'
import {
  resetEverything as resetVisitsEverything,
  VISIT_A,
  visit,
  visitPage,
} from '../visits/support'

export {
  apiError,
  buttonByName,
  click,
  field,
  hasField,
  ID_A,
  json,
  linkTo,
  openApp,
  textOf,
  type,
  type Recorded,
  VISIT_A,
  visit,
  visitPage,
}

export const CONSULTATION_ID = '01J9ZXK4M2Q8R7S6T5V4W30501'
/** Identifiant du compte de `meResponse` : le professionnel de la consultation. */
export const ACCOUNT_ID = '01J9ZXK4M2Q8R7S6T5V4W30002'

/** Professionnel : lit et saisit les consultations, lit les patients et la file. */
export const CLINICIAN = [
  'clinical.read',
  'clinical.write',
  'patient.read',
  'visit.register',
  'agenda.read',
]
/** Lecture seule : consulte sans modifier. */
export const READ_ONLY = ['clinical.read', 'patient.read', 'agenda.read']

type Steps = Consultation['steps']

export function emptySteps(): Steps {
  return {
    ANAMNESIS: {
      symptoms: null,
      wears_glasses: null,
      glasses_duration: null,
      previous_correction_available: null,
      complaints: [],
    },
    HISTORIES: { histories: [] },
    VISUAL_ACUITY: { acuities: [] },
    REFRACTION: { measurements: [], refraction_observations: null, ametropias: [] },
    CENTERING: {
      centering_id: null,
      pd_total: null,
      pd_od: null,
      pd_og: null,
      inconsistency_reason: null,
    },
    CONCLUSION: {
      conclusion: null,
      referral: null,
      referral_urgency: null,
      referral_reasons: [],
    },
  }
}

export function consultation(
  overrides: Partial<Omit<Consultation, 'steps'>> & {
    steps?: Partial<Record<keyof Steps, unknown>>
  } = {},
): Consultation {
  const { steps, ...rest } = overrides
  return {
    id: CONSULTATION_ID,
    visit_id: VISIT_A,
    patient_id: ID_A,
    clinician_id: ACCOUNT_ID,
    effective_at: '2026-10-22T09:42:00.000Z',
    date_change_reason: null,
    status: 'DRAFT',
    current_revision: 0,
    completed_at: null,
    finalization_missing_data_reason: null,
    version: 1,
    steps: { ...emptySteps(), ...(steps ?? {}) } as Steps,
    revisions: [],
    ...rest,
  }
}

export function patient(overrides: Partial<Patient> = {}): Patient {
  return basePatient(overrides)
}

/** Refus du serveur avec valeurs actualisées (`details`) : conflit de version, refus d'état. */
export function errorWithDetails(
  status: number,
  code: string,
  message: string,
  details: Record<string, string | number | null>,
  fields?: Record<string, string[]>,
): Response {
  return json(status, {
    error: { code, message, details, ...(fields === undefined ? {} : { fields }) },
    correlation_id: 'corr-test-0200',
  })
}

type Reply = Response | 'network'
type Handler<A extends unknown[]> = (...args: A) => Reply | Promise<Reply>

export interface ConsultationApi {
  /** Réponse de GET /auth/me ; modifiable pendant le test (session expirée…). */
  me: MeResponse | 'anonymous' | 'unreachable'
  /** Consultation enregistrée sur le serveur : un enregistrement d'étape la change. */
  server: Consultation
  patient: Patient
  getConsultation: Handler<[id: string, request: Recorded]>
  getPatient: Handler<[id: string, request: Recorded]>
  save: Handler<[id: string, step: string, body: Record<string, unknown>, request: Recorded]>
  start: Handler<[visitId: string, body: Record<string, unknown>, request: Recorded]>
  list: Handler<[day: string | null, page: number, request: Recorded]>
  requests: Recorded[]
  fetchMock: Mock
}

const CONSULTATION_PATH = /^\/api\/v1\/consultations\/([^/]+)$/
const STEP_PATH = /^\/api\/v1\/consultations\/([^/]+)\/steps\/([^/]+)$/
const START_PATH = /^\/api\/v1\/visits\/([^/]+)\/consultations$/
const PATIENT_PATH = /^\/api\/v1\/patients\/([^/]+)$/

export function callsTo(api: ConsultationApi, method: string, path: RegExp): Recorded[] {
  return api.requests.filter((request) => request.method === method && path.test(request.path))
}

export function patchCalls(api: ConsultationApi): Recorded[] {
  return callsTo(api, 'PATCH', STEP_PATH)
}

export function startCalls(api: ConsultationApi): Recorded[] {
  return callsTo(api, 'POST', START_PATH)
}

export function consultationReads(api: ConsultationApi): Recorded[] {
  return callsTo(api, 'GET', CONSULTATION_PATH)
}

export function listCalls(api: ConsultationApi): Recorded[] {
  return callsTo(api, 'GET', /^\/api\/v1\/visits$/)
}

export function stubConsultationApi(
  options: {
    permissions?: string[]
    modules?: readonly string[]
    displayName?: string
    consultation?: Consultation
    patient?: Patient
    handlers?: Partial<
      Pick<ConsultationApi, 'getConsultation' | 'getPatient' | 'save' | 'start' | 'list'>
    >
  } = {},
): ConsultationApi {
  const requests: Recorded[] = []
  const api = {
    me: meResponse(options.permissions ?? CLINICIAN, options.displayName ?? 'Dr Fictif', [
      ...(options.modules ?? ALL_TEST_MODULES),
    ]),
    server: options.consultation ?? consultation(),
    patient: options.patient ?? patient(),
    requests,
  } as ConsultationApi

  api.getConsultation = options.handlers?.getConsultation ?? (() => json(200, { data: api.server }))
  api.getPatient = options.handlers?.getPatient ?? (() => json(200, { data: api.patient }))
  api.save =
    options.handlers?.save ??
    ((_id, step, body) => {
      if (body['expected_version'] !== api.server.version) {
        return errorWithDetails(409, 'VERSION_CONFLICT', 'La consultation a été modifiée.', {
          current_version: api.server.version,
        })
      }
      const sent = body['step'] as { data: unknown }
      api.server = {
        ...api.server,
        status: 'IN_PROGRESS',
        version: api.server.version + 1,
        steps: { ...api.server.steps, [step]: sent.data } as Steps,
      }
      return json(200, { data: api.server })
    })
  api.start =
    options.handlers?.start ??
    ((visitId, body) => {
      api.server = consultation({
        id: String(body['consultation_id']),
        visit_id: visitId,
        patient_id: String(body['patient_id']),
        effective_at: String(body['effective_at']),
        status: 'DRAFT',
      })
      return json(201, { data: api.server })
    })
  api.list = options.handlers?.list ?? (() => json(200, visitPage([])))

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
    const started = START_PATH.exec(url.pathname)
    if (started !== null && method === 'POST') {
      return settle(api.start(decodeURIComponent(started[1] ?? ''), body ?? {}, request))
    }
    const stepped = STEP_PATH.exec(url.pathname)
    if (stepped !== null && method === 'PATCH') {
      return settle(
        api.save(
          decodeURIComponent(stepped[1] ?? ''),
          decodeURIComponent(stepped[2] ?? ''),
          body ?? {},
          request,
        ),
      )
    }
    const one = CONSULTATION_PATH.exec(url.pathname)
    if (one !== null && method === 'GET') {
      return settle(api.getConsultation(decodeURIComponent(one[1] ?? ''), request))
    }
    const person = PATIENT_PATH.exec(url.pathname)
    if (person !== null && method === 'GET') {
      return settle(api.getPatient(decodeURIComponent(person[1] ?? ''), request))
    }
    return apiError(404, 'RESOURCE_NOT_FOUND', 'Ressource introuvable.')
  })
  vi.stubGlobal('fetch', api.fetchMock)
  return api
}

export function resetEverything(): void {
  resetVisitsEverything()
  try {
    window.localStorage.clear()
    window.sessionStorage.clear()
  } catch {
    // Stockage indisponible : rien à nettoyer.
  }
}

export const CONSULTATION_URL = `/consultations/${CONSULTATION_ID}`

/** Visite démarrable : examen de vue arrivé, sans consultation. */
export function startable(overrides: Partial<Visit> = {}): Visit {
  return visit({ ...overrides })
}

// --- Saisie comme l'utilisateur -------------------------------------------------------------------

/**
 * Case à cocher ou bouton radio dont le libellé est exactement `label`, sinon commence par `label`,
 * dans `root`.
 */
export function box(root: ParentNode, label: string): HTMLInputElement {
  const labels = [...root.querySelectorAll('label')]
  const text = (candidate: HTMLLabelElement): string =>
    candidate.textContent?.replace(/\s+/g, ' ').trim() ?? ''
  const labelElement =
    labels.find((candidate) => text(candidate) === label) ??
    labels.find((candidate) => text(candidate).startsWith(label))
  const id = labelElement?.getAttribute('for')
  const element = id === undefined || id === null ? null : root.querySelector(`[id="${id}"]`)
  if (!(element instanceof HTMLInputElement)) throw new Error(`Case « ${label} » introuvable.`)
  return element
}

/** Coche ou décoche une case (clic, comme l'utilisateur). */
export async function toggle(root: ParentNode, label: string): Promise<void> {
  const element = box(root, label)
  element.click()
  await flushPromises()
}

/** Choix d'un groupe de boutons radio ou de cases : « Oui » dans « Porte actuellement des lunettes ». */
export async function choice(root: ParentNode, legend: string, label: string): Promise<void> {
  box(group(root, legend), label).click()
  await flushPromises()
}

/** Groupe (fieldset) dont la légende commence par `legend`. */
export function group(root: ParentNode, legend: string): HTMLFieldSetElement {
  const found = [...root.querySelectorAll('fieldset')].find((candidate) =>
    candidate.querySelector('legend')?.textContent?.trim().startsWith(legend),
  )
  if (found === undefined) throw new Error(`Groupe « ${legend} » introuvable.`)
  return found
}

export async function choose(root: ParentNode, label: string, value: string): Promise<void> {
  const element = field(root, label)
  element.value = value
  element.dispatchEvent(new Event('change', { bubbles: true }))
  await flushPromises()
}

export async function submitForm(root: ParentNode): Promise<void> {
  const form = root.querySelector('main form[novalidate]')
  if (form === null) throw new Error('Formulaire introuvable.')
  form.dispatchEvent(new Event('submit', { cancelable: true }))
  await flushPromises()
}

/** Ouvre la consultation par son adresse. */
export async function openConsultation(path = CONSULTATION_URL) {
  return openApp(path)
}
