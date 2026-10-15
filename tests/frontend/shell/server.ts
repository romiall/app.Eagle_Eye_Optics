// Serveur simulé pour les tests de la coquille : réponses de /api/v1/auth/me, connexion et
// déconnexion, au format des contrats F06 (MeResponse, ErrorEnvelope). Données fictives.
import { vi, type Mock } from 'vitest'

import type { Me, MeResponse } from '@/domain/contracts/generated'

export function meResponse(permissions: string[], displayName = 'Gérante Fictive'): MeResponse {
  const me: Me = {
    id: '01J9ZXK4M2Q8R7S6T5V4W30002',
    login: 'gerante.fictive',
    display_name: displayName,
    email: null,
    active: true,
    version: 1,
    roles: ['MANAGER'],
    permissions,
    clinic: {
      id: '01J9ZXK4M2Q8R7S6T5V4W30003',
      name: 'TEST CABINET FICTIF',
      timezone: 'Africa/Douala',
      currency: 'XAF',
      production_documents_enabled: false,
    },
  }
  return { data: me }
}

function json(status: number, body: unknown): Response {
  return new Response(body === null ? null : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

export interface ScriptedError {
  status: number
  code: string
  message: string
  fields?: Record<string, string[]>
}

export interface ServerScript {
  /** GET /auth/me : compte, 401, aucune réponse (réseau coupé) ou erreur 500 du serveur. */
  me: MeResponse | 'anonymous' | 'unreachable' | 'server-error'
  /** Réponse de POST /auth/login ; absente : aucune réponse (réseau coupé). */
  login?: MeResponse | ScriptedError
  /** POST /auth/logout : 204 par défaut. */
  logout?: 'ok' | 'unreachable' | ScriptedError
}

/** Remplace fetch ; rend le mock pour compter les appels. */
export function stubServer(script: ServerScript): Mock {
  const fetchMock = vi.fn(async (input: unknown, init?: RequestInit): Promise<Response> => {
    const url = String(input)
    const method = init?.method ?? 'GET'
    if (url === '/api/v1/auth/me') {
      if (script.me === 'unreachable') throw new TypeError('Failed to fetch')
      if (script.me === 'server-error') {
        return json(500, {
          error: { code: 'INTERNAL_ERROR', message: 'Erreur interne.' },
          correlation_id: 'corr-test-0004',
        })
      }
      if (script.me === 'anonymous') {
        return json(401, {
          error: { code: 'AUTH_REQUIRED', message: 'Connexion requise.' },
          correlation_id: 'corr-test-0001',
        })
      }
      return json(200, script.me)
    }
    if (url === '/api/sanctum/csrf-cookie') return new Response(null, { status: 204 })
    if (url === '/api/v1/auth/login' && method === 'POST') {
      const login = script.login
      if (login === undefined) throw new TypeError('Failed to fetch')
      if ('data' in login) return json(200, login)
      return json(login.status, {
        error: {
          code: login.code,
          message: login.message,
          ...(login.fields === undefined ? {} : { fields: login.fields }),
        },
        correlation_id: 'corr-test-0002',
      })
    }
    if (url === '/api/v1/auth/logout' && method === 'POST') {
      const logout = script.logout ?? 'ok'
      if (logout === 'unreachable') throw new TypeError('Failed to fetch')
      if (logout === 'ok') return new Response(null, { status: 204 })
      return json(logout.status, {
        error: { code: logout.code, message: logout.message },
        correlation_id: 'corr-test-0005',
      })
    }
    return json(404, {
      error: { code: 'RESOURCE_NOT_FOUND', message: 'Ressource introuvable.' },
      correlation_id: 'corr-test-0003',
    })
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}
