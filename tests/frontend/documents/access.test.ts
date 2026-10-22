// @vitest-environment node
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  DOCUMENT_PERMISSIONS,
  documentCompartment,
  isClinicalDocument,
  mayOpenDocument,
} from '@/documents/access'
import { DOCUMENT_KIND_LABELS, type DocumentKind } from '@/documents/engine/model'

const ROOT = join(import.meta.dirname, '..', '..', '..')

const KINDS = Object.keys(DOCUMENT_KIND_LABELS) as DocumentKind[]

/** Permissions du référentiel seedé (database/02_reference_data.sql). */
function seededPermissions(): Set<string> {
  const sql = readFileSync(join(ROOT, 'database', '02_reference_data.sql'), 'utf8')
  const start = sql.indexOf('INSERT INTO `permissions`')
  const block = sql.slice(start, sql.indexOf(';', start))
  return new Set([...block.matchAll(/\('([a-z_.]+)', '/g)].map((match) => match[1] ?? ''))
}

/** Rôles du référentiel et leurs permissions. */
function rolePermissions(): Map<string, string[]> {
  const sql = readFileSync(join(ROOT, 'database', '02_reference_data.sql'), 'utf8')
  const start = sql.indexOf('INSERT INTO `role_permissions`')
  const block = sql.slice(start, sql.indexOf(';', start))
  const roles = new Map<string, string[]>()
  for (const match of block.matchAll(/\('([A-Z_]+)', '([a-z_.]+)'\)/g)) {
    const role = match[1] ?? ''
    roles.set(role, [...(roles.get(role) ?? []), match[2] ?? ''])
  }
  return roles
}

describe('droits d’accès aux documents', () => {
  it('chaque type de document a des permissions du référentiel seedé', () => {
    const seeded = seededPermissions()

    expect(seeded.size).toBeGreaterThanOrEqual(49)
    for (const kind of KINDS) {
      expect(DOCUMENT_PERMISSIONS[kind].length, kind).toBeGreaterThan(0)
      for (const permission of DOCUMENT_PERMISSIONS[kind]) {
        expect(seeded.has(permission), `${kind} : ${permission}`).toBe(true)
      }
    }
  })

  it('un document clinique n’est jamais ouvert par un droit de caisse ou d’accueil', () => {
    const cashier = rolePermissions().get('CASHIER') ?? []
    const reception = rolePermissions().get('RECEPTION') ?? []

    expect(cashier.length).toBeGreaterThan(0)
    for (const kind of KINDS.filter((candidate) => isClinicalDocument(candidate))) {
      expect(mayOpenDocument(kind, cashier), `caisse / ${kind}`).toBe(false)
      expect(mayOpenDocument(kind, reception), `accueil / ${kind}`).toBe(false)
    }
  })

  it('la caisse ouvre les pièces financières, pas le dossier clinique', () => {
    const cashier = rolePermissions().get('CASHIER') ?? []

    for (const kind of ['INVOICE', 'RECEIPT', 'CREDIT_NOTE', 'REFUND', 'ACCOUNT_STATEMENT'] as const) {
      expect(mayOpenDocument(kind, cashier), kind).toBe(true)
    }
    expect(mayOpenDocument('CLINICAL_RECORD', cashier)).toBe(false)
    expect(mayOpenDocument('OPTICAL_PRESCRIPTION', cashier)).toBe(false)
  })

  it('le professionnel ouvre prescription, ordonnance, lettre et dossier, pas les reçus ni les remboursements', () => {
    const clinician = rolePermissions().get('CLINICIAN') ?? []

    for (const kind of [
      'OPTICAL_PRESCRIPTION',
      'MEDICATION_ORDER',
      'REFERRAL_LETTER',
      'CLINICAL_RECORD',
    ] as const) {
      expect(mayOpenDocument(kind, clinician), kind).toBe(true)
    }
    expect(mayOpenDocument('RECEIPT', clinician)).toBe(false)
    expect(mayOpenDocument('REFUND', clinician)).toBe(false)
    expect(mayOpenDocument('CASH_SESSION_REPORT', clinician)).toBe(false)
  })

  it('le dossier clinique en PDF demande le droit d’export (« exporter dossier/prescription »)', () => {
    expect(DOCUMENT_PERMISSIONS.CLINICAL_RECORD).toEqual(['clinical.export'])
    expect(mayOpenDocument('CLINICAL_RECORD', ['clinical.read'])).toBe(false)
    expect(mayOpenDocument('CLINICAL_RECORD', ['clinical.export'])).toBe(true)
  })

  it('sans aucun droit, rien ne s’ouvre', () => {
    for (const kind of KINDS) expect(mayOpenDocument(kind, []), kind).toBe(false)
  })

  it('les documents cliniques relèvent du compartiment CLINICAL, les pièces financières de FINANCIAL', () => {
    for (const kind of KINDS) {
      expect(documentCompartment(kind), kind).toBe(isClinicalDocument(kind) ? 'CLINICAL' : 'FINANCIAL')
    }
    expect(KINDS.filter((kind) => isClinicalDocument(kind)).sort()).toEqual([
      'CLINICAL_RECORD',
      'MEDICATION_ORDER',
      'OPTICAL_PRESCRIPTION',
      'REFERRAL_LETTER',
    ])
  })
})
