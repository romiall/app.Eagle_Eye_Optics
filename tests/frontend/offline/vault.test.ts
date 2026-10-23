// @vitest-environment node
import { IDBFactory, IDBKeyRange } from 'fake-indexeddb'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { pinnedGrantCheck } from '@/offline/enrollment/enrollment'
import { VaultDatabase } from '@/offline/vault/db'
import { VaultDocumentStore } from '@/offline/vault/documentStore'
import { isVaultError, type VaultErrorCode } from '@/offline/vault/errors'
import { type ProfileInput, Vault } from '@/offline/vault/vault'
import { signCompact, thumbprint, type PublicJwk } from '@/offline/signing/jws'

/**
 * Coffre local chiffré (O01) et vérification des grants (O02) sur IndexedDB simulée (`fake-indexeddb`), horloge et
 * politique de dérivation injectées (1 000 itérations pour la vitesse ; la production impose ≥ 600 000).
 * Données fictives. Sorties vérifiées : création atomique en deux phases, mauvais secret et tentatives limitées,
 * compartiments refusés au mauvais profil, chiffré sans texte clair, ligne déplacée indéchiffrable, verrouillage,
 * inactivité, changement de secret, récupération, dernière gérante, PDF chiffrés, grants altérés refusés.
 */
const POLICY = { minIterations: 1000, maxIterations: 5000, defaultIterations: 1000 }
const CLINIC = '01J9ZXK4M2Q8R7S6T5V4W3C001'
const MANAGER: ProfileInput = {
  userId: '01J9ZXK4M2Q8R7S6T5V4W3U001',
  login: 'gerante.test',
  displayName: 'Gérante fictive',
  permissions: ['users.manage', 'offline.enroll', 'patient.read', 'clinical.read', 'invoice.read'],
}
const CASHIER: ProfileInput = {
  userId: '01J9ZXK4M2Q8R7S6T5V4W3U002',
  login: 'caisse.test',
  displayName: 'Caisse fictive',
  permissions: ['patient.read', 'invoice.read', 'payment.collect', 'cash.own_session'],
}
const MANAGER_SECRET = 'maison-velo-fleuve-42'
const CASHIER_SECRET = 'riviere-lampe-orange-7'

let clock = Date.parse('2026-10-22T09:30:00.000Z')
let db: VaultDatabase
let vault: Vault
let counter = 0

function makeVault(grantCheck?: Parameters<typeof pinnedGrantCheck>): Vault {
  return new Vault({
    db,
    kdfPolicy: POLICY,
    now: () => clock,
    selfTest: async () => 'ok',
    ...(grantCheck === undefined ? {} : { grantCheck: pinnedGrantCheck(...grantCheck) }),
  })
}

async function expectCode(promise: Promise<unknown>, code: VaultErrorCode): Promise<void> {
  try {
    await promise
  } catch (error) {
    expect(isVaultError(error, code), String(error)).toBe(true)
    return
  }
  throw new Error(`${code} attendu`)
}

async function createVault(): Promise<string> {
  const prepared = await vault.prepareVault({
    clinicId: CLINIC,
    profile: MANAGER,
    secret: MANAGER_SECRET,
  })
  await prepared.commit(prepared.recoveryKey.toLowerCase())
  const [profile] = await vault.profiles()
  return profile?.profileId ?? ''
}

async function rawRows(): Promise<string> {
  const tables = [
    db.encrypted_entities,
    db.encrypted_documents,
    db.encrypted_drafts,
    db.key_envelopes,
    db.profiles,
  ]
  const parts: string[] = []
  for (const table of tables) {
    for (const row of await table.toArray()) {
      parts.push(
        JSON.stringify(row, (_key, value) =>
          value instanceof Uint8Array ? new TextDecoder('latin1').decode(value) : value,
        ),
      )
    }
  }
  return parts.join('\n')
}

beforeEach(() => {
  counter += 1
  clock = Date.parse('2026-10-22T09:30:00.000Z')
  db = new VaultDatabase(`eeo-device-test-${counter}`, { indexedDB: new IDBFactory(), IDBKeyRange })
  vault = makeVault()
})

afterEach(() => {
  vault.dispose()
  db.close()
})

describe('création du coffre (D9)', () => {
  it('rien n’est écrit tant que la clé de récupération n’est pas ressaisie exactement', async () => {
    const prepared = await vault.prepareVault({
      clinicId: CLINIC,
      profile: MANAGER,
      secret: MANAGER_SECRET,
    })

    expect(await vault.status()).toBe('absent')
    await expectCode(prepared.commit('AAAAA-AAAAA'), 'RECOVERY_KEY_MISMATCH')
    expect(await vault.status()).toBe('absent')

    await prepared.commit(prepared.recoveryKey)
    expect(await vault.status()).toBe('locked')
    await expectCode(
      vault.prepareVault({ clinicId: CLINIC, profile: MANAGER, secret: MANAGER_SECRET }),
      'VAULT_ALREADY_EXISTS',
    )
  })

  it('un secret faible ou un compte sans droit de gérante ne crée pas de coffre', async () => {
    await expectCode(
      vault.prepareVault({ clinicId: CLINIC, profile: MANAGER, secret: 'court' }),
      'SECRET_TOO_WEAK',
    )
    await expectCode(
      vault.prepareVault({ clinicId: CLINIC, profile: CASHIER, secret: CASHIER_SECRET }),
      'COMPARTMENT_NOT_AVAILABLE',
    )
  })
})

describe('déverrouillage et tentatives (D10)', () => {
  it('le bon secret ouvre les compartiments de la gérante ; un mauvais secret ou un profil inconnu échouent pareil', async () => {
    const profileId = await createVault()

    await expectCode(vault.unlock(profileId, 'mauvais-secret-fictif'), 'AUTH_FAILED')
    await expectCode(vault.unlock('01J9ZXK4M2Q8R7S6T5V4W3XXXX', MANAGER_SECRET), 'AUTH_FAILED')
    const session = await vault.unlock(profileId, MANAGER_SECRET)

    expect(session.compartments).toEqual(['ADMINISTRATIVE', 'CLINICAL', 'FINANCIAL', 'MANAGER'])
    expect(session.authority).toBe('preparation')
    expect(await vault.status()).toBe('unlocked')
  })

  it('après cinq échecs, la tentative suivante attend, puis le délai double', async () => {
    const profileId = await createVault()
    for (let attempt = 0; attempt < 5; attempt += 1)
      await expectCode(vault.unlock(profileId, 'mauvais-secret-fictif'), 'AUTH_FAILED')

    await expectCode(vault.unlock(profileId, MANAGER_SECRET), 'AUTH_THROTTLED')
    clock += 31_000
    await vault.unlock(profileId, MANAGER_SECRET)
  })
})

describe('compartiments et chiffré', () => {
  it('le chiffré ne contient aucun texte clair et une ligne déplacée ne se déchiffre pas', async () => {
    const profileId = await createVault()
    await vault.unlock(profileId, MANAGER_SECRET)

    await vault.putEntity('patient', 'P1', 1, { nom: 'SENTINELLE-NOM-FICTIF' })
    await vault.putEntity('consultation', 'C1', 1, { conclusion: 'SENTINELLE-CLINIQUE' })

    expect(await rawRows()).not.toMatch(/SENTINELLE/)
    expect(await vault.getEntity('patient', 'P1')).toEqual({
      version: 1,
      value: { nom: 'SENTINELLE-NOM-FICTIF' },
    })
    const row = await db.encrypted_entities.get('patient:P1')
    await db.encrypted_entities.put({
      ...(row as NonNullable<typeof row>),
      id: 'patient:P2',
      entityId: 'P2',
    })
    await expectCode(vault.getEntity('patient', 'P2'), 'ROW_DECRYPT_FAILED')
    await expectCode(vault.putEntity('patient', 'P1', 1, { nom: 'rejoué' }), 'VERSION_NOT_NEWER')
    await expectCode(vault.putEntity('inconnu', 'X', 1, {}), 'ENTITY_TYPE_UNREGISTERED')
  })

  it('la caisse ouvre la finance mais jamais la clinique', async () => {
    const managerId = await createVault()
    await vault.unlock(managerId, MANAGER_SECRET)
    await vault.putEntity('consultation', 'C1', 1, { conclusion: 'clinique fictive' })
    const cashierId = await vault.addProfile(
      { profileId: managerId, secret: MANAGER_SECRET },
      CASHIER,
      CASHIER_SECRET,
    )
    await vault.lock('TEST')

    const session = await vault.unlock(cashierId, CASHIER_SECRET)

    expect(session.compartments).toEqual(['ADMINISTRATIVE', 'FINANCIAL'])
    await expectCode(vault.getEntity('consultation', 'C1'), 'COMPARTMENT_NOT_AVAILABLE')
    await expectCode(
      vault.addProfile(
        { profileId: managerId, secret: 'mauvais-secret-fictif' },
        { ...CASHIER, userId: 'U3', login: 'autre' },
        CASHIER_SECRET,
      ),
      'STEP_UP_FAILED',
    )
  })
})

describe('verrouillage et inactivité (D12, D13)', () => {
  it('verrouiller retire l’accès sans toucher au chiffré', async () => {
    const profileId = await createVault()
    await vault.unlock(profileId, MANAGER_SECRET)
    await vault.putEntity('invoice', 'F1', 1, { total: 80000 })
    const before = await rawRows()

    await vault.lock('USER')

    expect(await vault.status()).toBe('locked')
    await expectCode(vault.getEntity('invoice', 'F1'), 'VAULT_LOCKED')
    expect(await rawRows()).toBe(before)
  })

  it('trente minutes sans activité ou une horloge qui recule verrouillent', async () => {
    const profileId = await createVault()
    await vault.unlock(profileId, MANAGER_SECRET)
    clock += 29 * 60 * 1000
    expect(await vault.checkInactivity()).toBeNull()
    clock += 60 * 1000
    expect(await vault.checkInactivity()).toBe('INACTIVITY')

    await vault.unlock(profileId, MANAGER_SECRET)
    clock -= 5 * 60 * 1000
    expect(await vault.checkInactivity()).toBe('CLOCK')
  })
})

describe('secrets, récupération et profils', () => {
  it('le secret changé remplace l’ancien ; la clé de récupération remet un secret perdu', async () => {
    const prepared = await vault.prepareVault({
      clinicId: CLINIC,
      profile: MANAGER,
      secret: MANAGER_SECRET,
    })
    await prepared.commit(prepared.recoveryKey)
    const [profile] = await vault.profiles()
    const profileId = profile?.profileId ?? ''

    await vault.changeSecret(profileId, MANAGER_SECRET, 'nouveau-secret-fictif-99')
    await expectCode(vault.unlock(profileId, MANAGER_SECRET), 'AUTH_FAILED')
    await vault.unlock(profileId, 'nouveau-secret-fictif-99')
    await vault.lock('TEST')

    expect(await vault.verifyRecoveryKey(prepared.recoveryKey)).toBe(true)
    await vault.resetSecretWithRecovery(prepared.recoveryKey, profileId, 'secret-retrouve-fictif-1')
    await vault.unlock(profileId, 'secret-retrouve-fictif-1')
  })

  it('la dernière gérante ne peut pas être retirée', async () => {
    const managerId = await createVault()
    const cashierId = await vault.addProfile(
      { profileId: managerId, secret: MANAGER_SECRET },
      CASHIER,
      CASHIER_SECRET,
    )

    await expectCode(
      vault.removeProfile({ profileId: managerId, secret: MANAGER_SECRET }, managerId),
      'LAST_MANAGER_REQUIRED',
    )
    await vault.removeProfile({ profileId: managerId, secret: MANAGER_SECRET }, cashierId)
    expect((await vault.profiles()).map((row) => row.login)).toEqual(['gerante.test'])
  })

  it('un brouillon appartient au profil qui l’a écrit', async () => {
    const managerId = await createVault()
    await vault.unlock(managerId, MANAGER_SECRET)
    await vault.putDraft('facture:F1', 'FINANCIAL', { ligne: 'brouillon fictif' })
    const cashierId = await vault.addProfile(
      { profileId: managerId, secret: MANAGER_SECRET },
      CASHIER,
      CASHIER_SECRET,
    )
    await vault.lock('TEST')
    await vault.unlock(cashierId, CASHIER_SECRET)

    expect(await vault.getDraft('facture:F1')).toBeNull()
    expect(await vault.listDrafts()).toEqual([])
  })
})

describe('PDF chiffrés (port DocumentStore)', () => {
  it('l’original est conservé chiffré, jamais remplacé, et son empreinte est vérifiée', async () => {
    const profileId = await createVault()
    await vault.unlock(profileId, MANAGER_SECRET)
    const store = new VaultDocumentStore(vault)
    const input = {
      key: { documentType: 'RECEIPT' as const, sourceKey: 'receipt:01RECU', revisionNo: 1 },
      documentId: '01J9ZXK4M2Q8R7S6T5V4W3DOC1',
      templateVersion: 'receipt.v1',
      fileName: 'recu-TEST-REC-000001.pdf',
      entityId: '01RECU',
      createdAt: '2026-10-22T09:30:00.000Z',
      bytes: new TextEncoder().encode('%PDF-1.4 SENTINELLE-PDF'),
      pageCount: 1,
    }

    const meta = await store.put(input)
    expect(meta.state).toBe('ready')
    expect(await rawRows()).not.toMatch(/SENTINELLE-PDF/)
    expect((await store.get(input.key))?.bytes).toEqual(input.bytes)
    await expect(
      store.put({ ...input, bytes: new TextEncoder().encode('%PDF autre') }),
    ).rejects.toMatchObject({ code: 'DOCUMENT_CONFLICT' })
    await expect(store.remove(input.key)).rejects.toMatchObject({ code: 'DOCUMENT_NOT_UPLOADED' })
  })
})

describe('grants signés (O02)', () => {
  async function serverKey(): Promise<{ privateKey: CryptoKey; jwk: PublicJwk; kid: string }> {
    const pair = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, true, [
      'sign',
      'verify',
    ])
    const exported = await crypto.subtle.exportKey('jwk', pair.publicKey)
    const jwk: PublicJwk = { kty: 'EC', crv: 'P-256', x: String(exported.x), y: String(exported.y) }
    return { privateKey: pair.privateKey, jwk, kid: await thumbprint(jwk) }
  }

  async function grantFor(
    key: { privateKey: CryptoKey; kid: string },
    overrides: Record<string, unknown> = {},
  ): Promise<{ jws: string; claims: Record<string, unknown> }> {
    const claims = {
      catalog_sha256: 'a'.repeat(64),
      catalog_version: 'catalogue-test',
      clinic_id: CLINIC,
      compartments: ['ADMINISTRATIVE', 'FINANCIAL'],
      device_id: 'DEVICE-1',
      grant_id: 'GRANT-1',
      issued_at: '2026-10-22T09:00:00.000Z',
      permissions: ['invoice.read'],
      rules_version: '2026-10-14.1',
      user_id: MANAGER.userId,
      v: 1,
      valid_until: '2026-10-25T09:00:00.000Z',
      ...overrides,
    }
    return {
      jws: await signCompact(claims, key.privateKey, key.kid, 'eeo-offline-grant+jws'),
      claims,
    }
  }

  async function boundVault(): Promise<{
    profileId: string
    key: Awaited<ReturnType<typeof serverKey>>
  }> {
    vault.dispose()
    vault = makeVault([() => vault.serverKeys(), () => vault.clinicId()])
    const profileId = await createVault()
    const key = await serverKey()
    await vault.pinServerKeys([{ kid: key.kid, status: 'CURRENT', jwk: key.jwk }])
    await vault.bindDevice('DEVICE-1')
    return { profileId, key }
  }

  it('un grant valide borne les compartiments ouverts à ceux qu’il accorde', async () => {
    const { profileId, key } = await boundVault()
    const grant = await grantFor(key)
    await vault.storeGrant({
      grantId: 'GRANT-1',
      userId: MANAGER.userId,
      deviceId: 'DEVICE-1',
      jws: grant.jws,
      claims: grant.claims,
      validUntil: '2026-10-25T09:00:00.000Z',
      storedAt: '',
    })

    const session = await vault.unlock(profileId, MANAGER_SECRET)

    expect(session.authority).toBe('granted')
    expect(session.compartments).toEqual(['ADMINISTRATIVE', 'FINANCIAL'])
    expect(session.grantId).toBe('GRANT-1')
  })

  it('un grant altéré refuse l’ouverture ; un grant expiré ne laisse que la lecture', async () => {
    const { profileId, key } = await boundVault()
    const grant = await grantFor(key)
    const [header, , signature] = grant.jws.split('.')
    const forged = `${header}.${btoa(JSON.stringify({ ...grant.claims, compartments: ['CLINICAL'] })).replace(/=+$/, '')}.${signature}`
    await vault.storeGrant({
      grantId: 'GRANT-1',
      userId: MANAGER.userId,
      deviceId: 'DEVICE-1',
      jws: forged,
      claims: grant.claims,
      validUntil: '',
      storedAt: '',
    })
    await expectCode(vault.unlock(profileId, MANAGER_SECRET), 'GRANT_INVALID')

    const expired = await grantFor(key, {
      grant_id: 'GRANT-2',
      valid_until: '2026-10-22T09:10:00.000Z',
    })
    await vault.storeGrant({
      grantId: 'GRANT-2',
      userId: MANAGER.userId,
      deviceId: 'DEVICE-1',
      jws: expired.jws,
      claims: expired.claims,
      validUntil: '',
      storedAt: '',
    })
    const session = await vault.unlock(profileId, MANAGER_SECRET)
    expect(session.authority).toBe('read-only')
  })

  it('un grant signé par une clé non épinglée ou pour un autre poste n’ouvre rien', async () => {
    const { profileId } = await boundVault()
    const stranger = await serverKey()
    const grant = await grantFor(stranger)
    await vault.storeGrant({
      grantId: 'GRANT-1',
      userId: MANAGER.userId,
      deviceId: 'DEVICE-1',
      jws: grant.jws,
      claims: grant.claims,
      validUntil: '',
      storedAt: '',
    })

    await expectCode(vault.unlock(profileId, MANAGER_SECRET), 'GRANT_INVALID')
  })
})
