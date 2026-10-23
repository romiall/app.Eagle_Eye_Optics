import type { ErrorCode, ErrorEnvelope } from '@/domain/contracts/generated'

import { reportServerReachable } from './network'

/**
 * Client HTTP de la SPA : une seule origine, cookie de session Sanctum, en-tête X-XSRF-TOKEN pour
 * les écritures, réponses JSON. Une erreur d'API garde son code du fichier 08, ses erreurs par
 * champ, ses valeurs actualisées et son délai `Retry-After` ; l'absence de réseau est distincte
 * d'un refus du serveur (jamais « aucun dossier »).
 */
export class ApiError extends Error {
  readonly status: number
  readonly code: ErrorCode | 'HTTP_ERROR'
  readonly fields: Readonly<Record<string, readonly string[]>>
  /** Valeurs actualisées d'un refus d'état (reste dû, quantité disponible : fichier 08). */
  readonly details: Readonly<Record<string, string | number | null>>
  readonly correlationId: string | null
  /** Délai demandé par le serveur avant une reprise (en-tête Retry-After, en secondes), ou null. */
  readonly retryAfterSeconds: number | null
  /** Faux si ce n'est pas l'application qui a répondu (page HTML d'un hébergeur, d'un proxy). */
  readonly fromApplication: boolean

  constructor(
    status: number,
    code: ErrorCode | 'HTTP_ERROR',
    message: string,
    fields: Readonly<Record<string, readonly string[]>> = {},
    correlationId: string | null = null,
    details: Readonly<Record<string, string | number | null>> = {},
    retryAfterSeconds: number | null = null,
    fromApplication = true,
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.fields = fields
    this.details = details
    this.correlationId = correlationId
    this.retryAfterSeconds = retryAfterSeconds
    this.fromApplication = fromApplication
  }
}

/**
 * Aucune réponse complète : réseau coupé, délai dépassé ou réponse interrompue. Rien n'est conclu
 * sur l'existence des données ni sur l'application d'une écriture.
 */
export class NetworkUnavailable extends Error {
  constructor() {
    super('Serveur injoignable : aucune réponse complète reçue.')
    this.name = 'NetworkUnavailable'
  }
}

type Method = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'

/** Une requête sans réponse au-delà de ce délai est abandonnée : l'écran ne reste pas bloqué. */
export const REQUEST_TIMEOUT_MS = 30_000

function xsrfToken(): string | null {
  const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='))
  return cookie === undefined ? null : decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
}

function isEnvelope(value: unknown): value is ErrorEnvelope {
  if (typeof value !== 'object' || value === null || !('error' in value)) return false
  const error = (value as { error: unknown }).error
  return typeof error === 'object' && error !== null && 'code' in error && 'message' in error
}

const NOT_JSON = Symbol('not-json')

function parsePayload(text: string): unknown {
  if (text === '') return null
  try {
    return JSON.parse(text) as unknown
  } catch {
    return NOT_JSON
  }
}

/** `Retry-After` en secondes (forme entière seulement ; une date HTTP n'est pas interprétée). */
function retryAfter(response: Response): number | null {
  const value = response.headers.get('Retry-After')
  return value !== null && /^[0-9]{1,6}$/.test(value.trim()) ? Number(value.trim()) : null
}

function timeoutSignal(): AbortSignal | undefined {
  return typeof AbortSignal !== 'undefined' && typeof AbortSignal.timeout === 'function'
    ? AbortSignal.timeout(REQUEST_TIMEOUT_MS)
    : undefined
}

export async function apiRequest<T>(
  method: Method,
  path: string,
  body?: unknown,
  extraHeaders: Readonly<Record<string, string>> = {},
): Promise<T> {
  const headers: Record<string, string> = { ...extraHeaders, Accept: 'application/json' }
  // Octets bruts (original PDF d'un document) : envoyés tels quels, type de contenu fourni par l'appelant.
  // `ArrayBuffer.isView` reconnaît les octets d'un autre contexte d'exécution (iframe, tests jsdom) que `instanceof`.
  const raw = ArrayBuffer.isView(body)
  if (body !== undefined && !raw) headers['Content-Type'] = 'application/json'
  const token = method === 'GET' ? null : xsrfToken()
  if (token !== null) headers['X-XSRF-TOKEN'] = token
  const signal = timeoutSignal()

  let response: Response
  let text: string
  try {
    response = await fetch(path, {
      method,
      headers,
      credentials: 'same-origin',
      cache: 'no-store',
      ...(signal === undefined ? {} : { signal }),
      ...(body === undefined ? {} : { body: raw ? (body as BodyInit) : JSON.stringify(body) }),
    })
    // Une réponse interrompue (corps coupé, délai dépassé) n'est pas une réponse.
    text = await response.text()
  } catch {
    reportServerReachable(false)
    throw new NetworkUnavailable()
  }

  const payload = parsePayload(text)
  if (payload === NOT_JSON) {
    // Page HTML d'un hébergeur (503, 504, 508), d'un pare-feu applicatif ou d'un portail captif :
    // une erreur du serveur, jamais un plantage de l'interface ni une réponse prise pour valide.
    // Ce n'est pas l'application qui a répondu : elle n'est pas comptée comme joignable.
    reportServerReachable(false)
    throw new ApiError(
      response.ok ? 502 : response.status,
      'HTTP_ERROR',
      `Réponse inattendue du serveur (${response.status}).`,
      {},
      null,
      {},
      retryAfter(response),
      false,
    )
  }
  reportServerReachable(true)
  if (!response.ok) {
    if (isEnvelope(payload)) {
      const fields = (payload.error.fields ?? {}) as Record<string, readonly string[]>
      throw new ApiError(
        response.status,
        payload.error.code,
        payload.error.message,
        fields,
        payload.correlation_id,
        (payload.error.details ?? {}) as Record<string, string | number | null>,
        retryAfter(response),
      )
    }
    throw new ApiError(
      response.status,
      'HTTP_ERROR',
      `Réponse inattendue du serveur (${response.status}).`,
      {},
      null,
      {},
      retryAfter(response),
    )
  }
  return payload as T
}

/** Cookie CSRF de Sanctum, requis avant la connexion et renouvelé après un 419. */
export async function prepareSession(): Promise<void> {
  await apiRequest<null>('GET', '/api/sanctum/csrf-cookie')
}
