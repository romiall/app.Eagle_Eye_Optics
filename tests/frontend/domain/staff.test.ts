import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  changeNeedsReason,
  endOfCivilDayUtc,
  generatePassword,
  GENERATED_PASSWORD_LENGTH,
  loginError,
  normalizeLogin,
  OVERRIDABLE_PERMISSIONS,
  passwordError,
  PERMISSIONS,
  roleLabel,
  STAFF_ROLES,
} from '@/domain/access/staff'

const ROOT = join(import.meta.dirname, '..', '..', '..')

/** Lignes `('code', 'libellé')` de l'INSERT `permissions` du référentiel seedé. */
function seededPermissions(): { code: string; label: string }[] {
  const sql = readFileSync(join(ROOT, 'database', '02_reference_data.sql'), 'utf8')
  const start = sql.indexOf('INSERT INTO `permissions`')
  const block = sql.slice(start, sql.indexOf(';', start))
  return [...block.matchAll(/\('([a-z_.]+)', '((?:[^']|'')*)'\)/g)].map((match) => ({
    code: match[1] ?? '',
    label: (match[2] ?? '').replace(/''/g, "'"),
  }))
}

describe('référentiel des droits (copie exacte du SQL)', () => {
  it('reprend les codes, les libellés et l’ordre de la table permissions', () => {
    expect(PERMISSIONS.map((entry) => ({ code: entry.code, label: entry.label }))).toEqual(
      seededPermissions(),
    )
  })

  it('ne propose jamais « Gérer utilisateurs et droits » en dérogation', () => {
    expect(OVERRIDABLE_PERMISSIONS.map((entry) => entry.code)).not.toContain('users.manage')
    expect(OVERRIDABLE_PERMISSIONS).toHaveLength(PERMISSIONS.length - 1)
  })

  it('nomme les quatre profils seedés, et rend tel quel un code inconnu', () => {
    expect(STAFF_ROLES).toEqual(['MANAGER', 'RECEPTION', 'CLINICIAN', 'CASHIER'])
    expect(roleLabel('CASHIER')).toBe('Caisse')
    expect(roleLabel('INCONNU')).toBe('INCONNU')
  })
})

describe('règles de saisie identiques au serveur', () => {
  it('identifiant : minuscules, chiffres, . _ - ; commence par une lettre ou un chiffre', () => {
    expect(loginError('marie.ngono')).toBeNull()
    expect(loginError('  Marie.Ngono ')).toBeNull()
    expect(normalizeLogin('  Marie.Ngono ')).toBe('marie.ngono')
    expect(loginError('')).not.toBeNull()
    expect(loginError('ab')).not.toBeNull()
    expect(loginError('.marie')).not.toBeNull()
    expect(loginError('marié')).not.toBeNull()
    expect(loginError('marie ngono')).not.toBeNull()
    expect(loginError('a'.repeat(65))).not.toBeNull()
  })

  it('mot de passe : 12 caractères, lettres et chiffres, 72 octets, différent de l’identifiant', () => {
    expect(passwordError('MotDePasse2026', 'marie.ngono')).toBeNull()
    expect(passwordError('', 'x')).not.toBeNull()
    expect(passwordError('Court2026', 'x')).not.toBeNull()
    expect(passwordError('SansAucunChiffre', 'x')).not.toBeNull()
    expect(passwordError('123456789012', 'x')).not.toBeNull()
    // 72 octets au plus : « é » compte deux octets.
    expect(passwordError(`${'é'.repeat(36)}1`, 'x')).not.toBeNull()
    expect(passwordError('marie.ngono1', 'marie.ngono1')).not.toBeNull()
    expect(passwordError(' Marie.Ngono1 ', 'marie.ngono1')).not.toBeNull()
  })

  it('un mot de passe généré respecte les règles et ne répète pas le précédent', () => {
    const first = generatePassword()
    const second = generatePassword()
    expect(first).toHaveLength(GENERATED_PASSWORD_LENGTH)
    expect(passwordError(first, 'marie.ngono')).toBeNull()
    expect(first).not.toBe(second)
    expect(first).not.toMatch(/[0O1lI]/)
  })

  it('un aléa sans chiffre est rejeté puis retenté', () => {
    let calls = 0
    const random = (values: Uint32Array): Uint32Array => {
      calls += 1
      // Premier tirage : uniquement l'index 0 (« a ») ; second : alternance lettre / chiffre.
      values.forEach((_value, index) => {
        values[index] = calls === 1 ? 0 : index % 2 === 0 ? 0 : 48
      })
      return values
    }
    const password = generatePassword(random)
    expect(calls).toBe(2)
    expect(passwordError(password, 'x')).toBeNull()
  })

  it('motif exigé pour désactiver un compte ou lui retirer un profil, pas pour en ajouter', () => {
    const before = { active: true, roles: ['RECEPTION', 'CASHIER'] }
    expect(changeNeedsReason(before, { active: false, roles: ['RECEPTION', 'CASHIER'] })).toBe(true)
    expect(changeNeedsReason(before, { active: true, roles: ['RECEPTION'] })).toBe(true)
    expect(
      changeNeedsReason(before, { active: true, roles: ['RECEPTION', 'CASHIER', 'CLINICIAN'] }),
    ).toBe(false)
    expect(changeNeedsReason({ active: false, roles: ['RECEPTION'] }, { active: true, roles: ['RECEPTION'] })).toBe(false)
  })
})

describe('échéance d’une dérogation : fin du jour civil du cabinet, jamais minuit UTC', () => {
  it('Douala (UTC+1) : le 30 novembre inclus se termine à 23 h UTC', () => {
    expect(endOfCivilDayUtc('2026-11-30', 'Africa/Douala')).toBe('2026-11-30T23:00:00.000Z')
  })

  it('fin de mois et d’année', () => {
    expect(endOfCivilDayUtc('2026-12-31', 'Africa/Douala')).toBe('2026-12-31T23:00:00.000Z')
    expect(endOfCivilDayUtc('2026-02-28', 'UTC')).toBe('2026-03-01T00:00:00.000Z')
  })

  it('une date mal formée ne produit aucun instant', () => {
    expect(endOfCivilDayUtc('30/11/2026', 'Africa/Douala')).toBeNull()
    expect(endOfCivilDayUtc('', 'Africa/Douala')).toBeNull()
  })
})
