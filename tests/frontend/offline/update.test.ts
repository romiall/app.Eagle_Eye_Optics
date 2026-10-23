// @vitest-environment node
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

import { describe, expect, it } from 'vitest'

import { createPwaOptions, NAVIGATE_FALLBACK_DENYLIST } from '@/app/pwa.config'
import { APP_RELEASE } from '@/app/release'
import {
  decideUpdate,
  type ReleaseCompatibility,
  type UpdateFacts,
} from '@/offline/update/updateGate'
import { LOCAL_SCHEMA_VERSION } from '@/offline/migrations/registry'

/**
 * Mise à jour sûre (O06 ; AT45) : une nouvelle version n'est jamais installée d'office ; elle est bloquée si la release
 * du serveur ne reprend plus une pièce en attente, à confirmer si des pièces attendent (profil verrouillé, export ancien,
 * stockage plein) ; aucune purge du stockage dans le code de l'application ; API et fichiers privés exclus du repli SPA.
 */
const NOW = Date.parse('2026-10-22T10:00:00.000Z')

function compatibility(overrides: Partial<ReleaseCompatibility> = {}): ReleaseCompatibility {
  return {
    release: '0.1.0-l0',
    rules_versions: ['2026-10-14.1'],
    commands: { CreatePatient: ['1'], RegisterPayment: ['1'] },
    vault_formats: [1],
    local_schema_versions: [1, 2, 3, 4, 5],
    ...overrides,
  }
}

function facts(overrides: Partial<UpdateFacts> = {}): UpdateFacts {
  return {
    pending: [],
    openCompartments: ['ADMINISTRATIVE', 'FINANCIAL'],
    localSchemaVersion: 5,
    vaultFormat: 1,
    lastExportAt: null,
    storage: { usage: 10, quota: 1000 },
    compatibility: compatibility(),
    now: NOW,
    ...overrides,
  }
}

const PAYMENT = {
  commandName: 'RegisterPayment',
  schemaVersion: '1',
  rulesVersion: '2026-10-14.1',
  compartment: 'FINANCIAL',
}

describe('mise à jour sûre (O06)', () => {
  it('sans pièce en attente, la mise à jour est libre', () => {
    expect(decideUpdate(facts())).toEqual({ decision: 'FREE', reasons: [], pendingCount: 0 })
  })

  it('des pièces en attente compatibles demandent une confirmation, avec export et profil verrouillé signalés', () => {
    const readiness = decideUpdate(
      facts({
        pending: [PAYMENT, { ...PAYMENT, commandName: 'CreatePatient', compartment: 'CLINICAL' }],
      }),
    )
    expect(readiness.decision).toBe('CONFIRM')
    expect(readiness.pendingCount).toBe(2)
    expect(readiness.reasons.map((reason) => reason.code)).toEqual([
      'PENDING_COMMANDS',
      'LOCKED_PROFILE_COMMANDS',
      'NO_RECENT_EXPORT',
    ])

    const exported = decideUpdate(
      facts({ pending: [PAYMENT], lastExportAt: '2026-10-22T09:00:00.000Z' }),
    )
    expect(exported.reasons.map((reason) => reason.code)).toEqual(['PENDING_COMMANDS'])
  })

  it('une version de commande, de règles, de coffre ou de schéma non reprise bloque', () => {
    expect(
      decideUpdate(
        facts({
          pending: [PAYMENT],
          compatibility: compatibility({ commands: { RegisterPayment: ['2'] } }),
        }),
      ).reasons[0],
    ).toMatchObject({ code: 'COMMAND_VERSION_DROPPED', blocking: true })
    expect(
      decideUpdate(
        facts({
          pending: [PAYMENT],
          compatibility: compatibility({ rules_versions: ['2027-01-01.1'] }),
        }),
      ).decision,
    ).toBe('BLOCKED')
    expect(
      decideUpdate(facts({ compatibility: compatibility({ vault_formats: [2] }) })).reasons[0]
        ?.code,
    ).toBe('VAULT_FORMAT_DROPPED')
    expect(
      decideUpdate(facts({ compatibility: compatibility({ local_schema_versions: [6] }) }))
        .reasons[0]?.code,
    ).toBe('LOCAL_SCHEMA_UNSUPPORTED')
  })

  it('serveur injoignable : bloquée s’il y a des pièces en attente, libre sinon', () => {
    expect(decideUpdate(facts({ pending: [PAYMENT], compatibility: null })).decision).toBe(
      'BLOCKED',
    )
    expect(decideUpdate(facts({ compatibility: null })).decision).toBe('FREE')
  })

  it('un stockage plein à 90 % ou plus demande une confirmation (calcul entier)', () => {
    expect(decideUpdate(facts({ storage: { usage: 900, quota: 1000 } })).reasons[0]?.code).toBe(
      'STORAGE_ALMOST_FULL',
    )
    expect(decideUpdate(facts({ storage: { usage: 899, quota: 1000 } })).decision).toBe('FREE')
  })

  it('la release du client est celle de la déclaration de compatibilité et du contrat', () => {
    const declaration = JSON.parse(
      readFileSync(join(process.cwd(), 'contracts/compatibility.json'), 'utf8'),
    ) as { release: string; local_schema_versions: number[] }
    expect(APP_RELEASE).toBe(declaration.release)
    expect(declaration.local_schema_versions).toContain(LOCAL_SCHEMA_VERSION)
  })

  it('le service worker attend la décision : ni activation ni prise de contrôle forcées', () => {
    const options = createPwaOptions({}, 'r1')
    expect(options.registerType).toBe('prompt')
    expect(options.workbox?.skipWaiting).toBe(false)
    expect(options.workbox?.clientsClaim).toBe(false)
    for (const path of [
      '/api/v1/devices/01J9ZXK4M2Q8R7S6T5V4W3D001/recovered-push',
      '/api/v1/documents/x/download',
      '/storage/app/private/x.pdf',
    ]) {
      expect(
        NAVIGATE_FALLBACK_DENYLIST.some((pattern) => pattern.test(path)),
        path,
      ).toBe(true)
    }
  })

  it('aucune purge du stockage du poste dans le code de l’application', () => {
    const root = join(process.cwd(), 'resources/frontend')
    const files: string[] = []
    const walk = (dir: string): void => {
      for (const entry of readdirSync(dir)) {
        const path = join(dir, entry)
        if (statSync(path).isDirectory()) walk(path)
        else if (/\.(ts|vue)$/.test(entry) && !entry.endsWith('generated.ts')) files.push(path)
      }
    }
    walk(root)
    const forbidden = [
      /indexedDB\.deleteDatabase\s*\(/,
      /Dexie\.delete\s*\(/,
      /\bdb\.delete\s*\(\s*\)/,
      /caches\.delete\s*\(/,
      /localStorage\.clear\s*\(/,
    ]
    const offenders = files.flatMap((file) => {
      const source = readFileSync(file, 'utf8')
      return forbidden
        .filter((pattern) => pattern.test(source))
        .map((pattern) => `${relative(root, file)} : ${pattern}`)
    })
    expect(offenders).toEqual([])
  })
})
