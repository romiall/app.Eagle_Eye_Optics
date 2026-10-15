import type { ErrorCode, ErrorEnvelope } from '@/domain/contracts/generated'

import { reportServerReachable } from './network'

/**
 * Client HTTP de la SPA : une seule origine, cookie de session Sanctum, en-tête X-XSRF-TOKEN pour
 * les écritures, réponses JSON. Une erreur d'API garde son code du fichier 08 et ses erreurs par
 * champ ; l'absence de réseau est distincte d'un refus du serveur (jamais « aucun dossier »).
 */
export class ApiError extends Error {
  readonly status: number
  readonly code: ErrorCode | 'HTTP_ERROR'
  readonly fields: Readonly<Record<string, readonly string[]>>
  /** Valeurs actualisées d'un refus d'état (reste dû, quantité disponible : fichier 08). */
  readonly details: Readonly<Record<string, string | number | null>>
  readonly correlationId: string | null

  constructor(
    status: number,
    code: ErrorCode | 'HTTP_ERROR',
    message: string,
    fields: Readonly<Record<string, readonly string[]>> = {},
    correlationId: string | null = null,
    details: Readonly<Record<string, string | number | null>> = {},
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.fields = fields
    this.details = details
    this.correlationId = correlationId
  }
}

/** Le serveur n'a pas pu être joint : rien n'est conclu sur l'existence des données. */
export class NetworkUnavailable extends Error {
  constructor() {
    super('Serveur injoignable : aucune réponse reçue.')
    this.name = 'NetworkUnavailable'
  }
}

type Method = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'

function xsrfToken(): string | null {
  const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='))
  return cookie === undefined ? null : decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
}

function isEnvelope(value: unknown): value is ErrorEnvelope {
  if (typeof value !== 'object' || value === null || !('error' in value)) return false
  const error = (value as { error: unknown }).error
  return typeof error === 'object' && error !== null && 'code' in error && 'message' in error
}

export async function apiRequest<T>(method: Method, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  const token = method === 'GET' ? null : xsrfToken()
  if (token !== null) headers['X-XSRF-TOKEN'] = token

  let response: Response
  try {
    response = await fetch(path, {
      method,
      headers,
      credentials: 'same-origin',
      cache: 'no-store',
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    })
  } catch {
    reportServerReachable(false)
    throw new NetworkUnavailable()
  }
  reportServerReachable(true)

  const text = await response.text()
  const payload: unknown = text === '' ? null : (JSON.parse(text) as unknown)
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
      )
    }
    throw new ApiError(
      response.status,
      'HTTP_ERROR',
      `Réponse inattendue du serveur (${response.status}).`,
    )
  }
  return payload as T
}

/** Cookie CSRF de Sanctum, requis avant la connexion. */
export async function prepareSession(): Promise<void> {
  await apiRequest<null>('GET', '/api/sanctum/csrf-cookie')
}
