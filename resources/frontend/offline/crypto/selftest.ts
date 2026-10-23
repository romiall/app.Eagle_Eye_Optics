import { unwrapAesKey, wrap } from './kdf'

/**
 * Auto-test Web Crypto (vault.md D25), au premier démarrage, à la création et à la préparation du poste : le
 * navigateur doit être en contexte sécurisé, envelopper une clé AES-GCM avec AAD, la déballer avec le MÊME AAD et
 * REFUSER un autre AAD. Sinon le coffre est refusé (`WEBCRYPTO_SELFTEST_FAILED`) : aucune donnée n'y est écrite.
 */
export type SelfTestOutcome = 'ok' | 'insecure-context' | 'failed'

export async function webCryptoSelfTest(
  subtle: SubtleCrypto | undefined = globalThis.crypto?.subtle,
): Promise<SelfTestOutcome> {
  if (typeof globalThis.isSecureContext === 'boolean' && !globalThis.isSecureContext)
    return 'insecure-context'
  if (subtle === undefined) return 'failed'
  try {
    const deps = { subtle }
    const kek = await subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, [
      'wrapKey',
      'unwrapKey',
    ])
    const key = await subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, [
      'encrypt',
      'decrypt',
    ])
    const sealed = await wrap(key, kek, 'raw', ['selftest', 1], deps)
    await unwrapAesKey(sealed, kek, ['selftest', 1], false, deps)
    try {
      await unwrapAesKey(sealed, kek, ['selftest', 2], false, deps)
      return 'failed'
    } catch {
      return 'ok'
    }
  } catch {
    return 'failed'
  }
}
