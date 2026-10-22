import { afterEach, describe, expect, it } from 'vitest'

import {
  clinicTimeZone,
  clinicToday,
  DEFAULT_CLINIC_TIME_ZONE,
  formatInstantFr,
} from '@/app/clinicTime'
import { resetNetworkForTests } from '@/app/network'
import { refreshSession, resetSessionForTests } from '@/app/session'

import { meResponse, stubServer } from './server'

// Jour civil et heure du cabinet (fuseau du cabinet connecté, jamais l'UTC du serveur).
afterEach(() => {
  resetSessionForTests()
  resetNetworkForTests()
})

async function connectedWithTimeZone(timezone: string): Promise<void> {
  const me = meResponse(['patient.read'])
  me.data.clinic.timezone = timezone
  stubServer({ me })
  await refreshSession()
}

describe('jour civil et heure du cabinet', () => {
  it('sans compte connu, le fuseau proposé par les sources : Africa/Douala', () => {
    expect(clinicTimeZone()).toBe(DEFAULT_CLINIC_TIME_ZONE)
    expect(DEFAULT_CLINIC_TIME_ZONE).toBe('Africa/Douala')
  })

  it('le jour civil suit le fuseau du cabinet, pas le minuit UTC', async () => {
    await connectedWithTimeZone('Africa/Douala')

    // 23h30 UTC le 14 : il est déjà 0h30 le 15 à Douala (UTC+1).
    expect(clinicToday(Date.parse('2026-10-14T23:30:00.000Z')).toString()).toBe('2026-10-15')
    expect(clinicToday(Date.parse('2026-10-14T22:59:59.000Z')).toString()).toBe('2026-10-14')
  })

  it('suit un autre fuseau quand le cabinet en a un autre', async () => {
    await connectedWithTimeZone('Pacific/Kiritimati')

    // UTC+14 : le 14 à 12h UTC est le 15 à 2h.
    expect(clinicToday(Date.parse('2026-10-14T12:00:00.000Z')).toString()).toBe('2026-10-15')
  })

  it('un fuseau inconnu du navigateur retombe sur le fuseau par défaut au lieu de casser l’écran', async () => {
    await connectedWithTimeZone('Mars/Phobos')

    expect(clinicToday(Date.parse('2026-10-14T23:30:00.000Z')).toString()).toBe('2026-10-15')
    expect(formatInstantFr('2026-10-15T09:42:00.000Z')).toBe('15/10/2026 à 10h42')
  })

  it('affiche un instant à l’heure du cabinet, jour et mois sur deux chiffres', async () => {
    await connectedWithTimeZone('Africa/Douala')

    expect(formatInstantFr('2026-10-15T09:42:00.000Z')).toBe('15/10/2026 à 10h42')
    expect(formatInstantFr('2026-01-02T23:05:00.000Z')).toBe('03/01/2026 à 00h05')
  })
})
