import { IDBFactory, IDBKeyRange } from 'fake-indexeddb'

import type { Preparation } from '@/domain/contracts/generated'
import { storePreparation } from '@/offline/allocations/allocations'
import { pinnedGrantCheck } from '@/offline/enrollment/enrollment'
import { signCompact, thumbprint, type PublicJwk } from '@/offline/signing/jws'
import { VaultDatabase } from '@/offline/vault/db'
import { type ProfileInput, Vault } from '@/offline/vault/vault'

/**
 * Poste PRÉPARÉ fictif pour les tests O03/O04 : coffre sur IndexedDB simulée, profil de la gérante, clé ES256 du poste,
 * clé du serveur épinglée, poste lié, grant signé valide (permissions données) et coffre ouvert. Horloge injectée.
 * Rien n'est réel : identifiants, clés et secrets sont fabriqués pour le test.
 */
export const CLINIC = '01J9ZXK4M2Q8R7S6T5V4W3C001'
export const DEVICE = '01J9ZXK4M2Q8R7S6T5V4W3D001'
export const GRANT = '01J9ZXK4M2Q8R7S6T5V4W3G001'
export const MANAGER_SECRET = 'maison-velo-fleuve-42'
const POLICY = { minIterations: 1000, maxIterations: 5000, defaultIterations: 1000 }

export interface PreparedDevice {
  vault: Vault
  db: VaultDatabase
  factory: IDBFactory
  devicePublicJwk: PublicJwk
  profileId: string
  clock: { now: number }
  /** Clé de récupération du coffre (connue seulement à la création ; O07 : ouverture d'un export). */
  recoveryKey: string | null
  deviceId: string
  reopen(): Promise<PreparedDevice>
}

export const ALL_PERMISSIONS = [
  'patient.read',
  'patient.create',
  'patient.update',
  'visit.register',
  'invoice.read',
  'invoice.create',
  'invoice.issue',
  'payment.collect',
  'cash.own_session',
  'clinical.read',
  'clinical.write',
  'users.manage',
  'offline.enroll',
]

export async function preparedDevice(
  options: {
    permissions?: string[]
    validUntil?: string
    name?: string
    factory?: IDBFactory
    clock?: { now: number }
    deviceId?: string
    recoveryKey?: string | null
  } = {},
): Promise<PreparedDevice> {
  const deviceId = options.deviceId ?? DEVICE
  let recoveryKey = options.recoveryKey ?? null
  const factory = options.factory ?? new IDBFactory()
  const clock = options.clock ?? { now: Date.parse('2026-10-22T09:30:00.000Z') }
  const name = options.name ?? `eeo-device-test-${Math.random().toString(36).slice(2)}`
  const db = new VaultDatabase(name, { indexedDB: factory, IDBKeyRange })
  const vault: Vault = new Vault({
    db,
    kdfPolicy: POLICY,
    now: () => clock.now,
    selfTest: async () => 'ok',
    grantCheck: pinnedGrantCheck(
      () => vault.serverKeys(),
      () => vault.clinicId(),
    ),
  })
  const permissions = options.permissions ?? ALL_PERMISSIONS
  const manager: ProfileInput = {
    userId: '01J9ZXK4M2Q8R7S6T5V4W3U001',
    login: 'gerante.test',
    displayName: 'Gérante fictive',
    permissions,
  }

  let profileId: string
  let devicePublicJwk: PublicJwk
  if ((await vault.status()) === 'absent') {
    const prepared = await vault.prepareVault({
      clinicId: CLINIC,
      profile: manager,
      secret: MANAGER_SECRET,
    })
    await prepared.commit(prepared.recoveryKey)
    recoveryKey = prepared.recoveryKey
    profileId = (await vault.profiles())[0]?.profileId ?? ''
    await vault.unlock(profileId, MANAGER_SECRET)
    devicePublicJwk = await vault.createSigningKey({ profileId, secret: MANAGER_SECRET })
    const pair = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, true, [
      'sign',
      'verify',
    ])
    const exported = await crypto.subtle.exportKey('jwk', pair.publicKey)
    const serverJwk: PublicJwk = {
      kty: 'EC',
      crv: 'P-256',
      x: String(exported.x),
      y: String(exported.y),
    }
    const kid = await thumbprint(serverJwk)
    await vault.pinServerKeys([{ kid, status: 'CURRENT', jwk: serverJwk }])
    await vault.bindDevice(deviceId)
    const claims = {
      catalog_sha256: 'a'.repeat(64),
      catalog_version: 'catalogue-test',
      clinic_id: CLINIC,
      compartments: ['ADMINISTRATIVE', 'CLINICAL', 'FINANCIAL', 'MANAGER'],
      device_id: deviceId,
      grant_id: GRANT,
      issued_at: '2026-10-22T09:00:00.000Z',
      permissions,
      rules_version: '2026-10-14.1',
      user_id: manager.userId,
      v: 1,
      valid_until: options.validUntil ?? '2026-10-25T09:00:00.000Z',
    }
    const jws = await signCompact(claims, pair.privateKey, kid, 'eeo-offline-grant+jws')
    await vault.storeGrant({
      grantId: GRANT,
      userId: manager.userId,
      deviceId,
      jws,
      claims,
      validUntil: claims.valid_until,
      storedAt: '',
    })
    await vault.noteServerTime('2026-10-22T09:00:00.000Z')
    await vault.lock('TEST')
  } else {
    profileId = (await vault.profiles())[0]?.profileId ?? ''
    devicePublicJwk = (await vault.signingPublicKey()) as PublicJwk
  }
  await vault.unlock(profileId, MANAGER_SECRET)

  const device: PreparedDevice = {
    vault,
    db,
    factory,
    devicePublicJwk,
    profileId,
    clock,
    recoveryKey,
    deviceId,
    reopen: async () => {
      vault.dispose()
      db.close()
      return preparedDevice({ ...options, factory, clock, name, deviceId, recoveryKey })
    },
  }
  return device
}

/** Préparation serveur fictive (O03) : plages patient, facture et reçu, un quota, une facture attribuée. */
export function samplePreparation(overrides: Partial<Preparation> = {}): Preparation {
  return {
    device_id: DEVICE,
    grants: [],
    number_allocations: [
      {
        allocation_id: '01J9ZXK4M2Q8R7S6T5V4W3A001',
        grant_id: GRANT,
        document_type: 'PATIENT',
        document_year: 2026,
        range_start: '41',
        range_end: '43',
        last_consumed_value: null,
        status: 'ACTIVE',
        prefix: 'EE',
        width: 4,
      },
      {
        allocation_id: '01J9ZXK4M2Q8R7S6T5V4W3A002',
        grant_id: GRANT,
        document_type: 'INVOICE',
        document_year: 2026,
        range_start: '301',
        range_end: '600',
        last_consumed_value: null,
        status: 'ACTIVE',
        prefix: 'TEST-FAC',
        width: 6,
      },
      {
        allocation_id: '01J9ZXK4M2Q8R7S6T5V4W3A003',
        grant_id: GRANT,
        document_type: 'RECEIPT',
        document_year: 2026,
        range_start: '1',
        range_end: '300',
        last_consumed_value: null,
        status: 'ACTIVE',
        prefix: 'TEST-REC',
        width: 6,
      },
    ],
    stock_allocations: [
      {
        allocation_id: '01J9ZXK4M2Q8R7S6T5V4W3Q001',
        item_id: '01J9ZXK4M2Q8R7S6T5V4W3I001',
        stock_position_id: '01J9ZXK4M2Q8R7S6T5V4W3P001',
        stock_lot_id: null,
        allocated_quantity: '3.000',
        consumed_quantity: '0.000',
        returned_quantity: '0.000',
        status: 'ACTIVE',
      },
    ],
    write_owners: [
      {
        owner_id: '01J9ZXK4M2Q8R7S6T5V4W3O001',
        invoice_id: '01J9ZXK4M2Q8R7S6T5V4W3F001',
        epoch: 1,
      },
    ],
    catalog: {
      catalog_version: 'catalogue-test',
      snapshot_sha256: 'b'.repeat(64),
      snapshot: {
        items: [
          {
            id: '01J9ZXK4M2Q8R7S6T5V4W3I001',
            reference: 'FR-001',
            category_code: 'FRAME',
            name: 'Monture fictive',
            unit_code: 'UNIT',
            quantity_integer_only: true,
            sale_price: 40000,
            tax_rule_id: null,
            stock_mode: 'STOCKED',
            requires_expiry: false,
            active: true,
            version: 1,
          },
          {
            id: '01J9ZXK4M2Q8R7S6T5V4W3I002',
            reference: 'EX-001',
            category_code: 'SERVICE',
            name: 'Examen fictif',
            unit_code: 'SERVICE',
            quantity_integer_only: false,
            sale_price: 15000,
            tax_rule_id: null,
            stock_mode: 'NONE',
            requires_expiry: false,
            active: true,
            version: 1,
          },
        ],
        tax_rules: [],
      },
    },
    rules_version: '2026-10-14.1',
    server_time: '2026-10-22T09:20:00.000Z',
    last_accepted_sequence: '0',
    last_accepted_hash: null,
    ...overrides,
  }
}

export async function prepared(
  options: Parameters<typeof preparedDevice>[0] = {},
): Promise<PreparedDevice> {
  const device = await preparedDevice(options)
  await storePreparation(device.vault, samplePreparation(), '2026-10-22T09:25:00.000Z')
  return device
}
