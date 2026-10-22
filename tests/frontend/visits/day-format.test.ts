import { afterEach, describe, expect, it } from 'vitest'

import { formatDayFr, formatTimeFr } from '@/app/clinicTime'
import { resetNetworkForTests } from '@/app/network'
import { refreshSession, resetSessionForTests } from '@/app/session'
import { CivilDate } from '@/domain/values/civilDate'

import { meResponse, stubServer } from '../shell/server'

// Heure d'arrivée à l'heure du cabinet et jour civil en toutes lettres (file du jour, P03).
afterEach(() => {
  resetSessionForTests()
  resetNetworkForTests()
})

async function connectedWithTimeZone(timezone: string): Promise<void> {
  const me = meResponse(['agenda.read'])
  me.data.clinic.timezone = timezone
  stubServer({ me })
  await refreshSession()
}

describe('heure d’arrivée à l’heure du cabinet', () => {
  it('sans compte connu : Douala (UTC+1), heures et minutes sur deux chiffres', () => {
    expect(formatTimeFr('2026-10-15T08:15:00.000Z')).toBe('09h15')
    expect(formatTimeFr('2026-10-15T09:05:59.999Z')).toBe('10h05')
    expect(formatTimeFr('2026-10-14T23:30:00.000Z')).toBe('00h30')
    expect(formatTimeFr('2026-10-15T22:59:00.000Z')).toBe('23h59')
  })

  it('suit le fuseau du cabinet connecté', async () => {
    await connectedWithTimeZone('Pacific/Kiritimati')

    // UTC+14.
    expect(formatTimeFr('2026-10-14T12:00:00.000Z')).toBe('02h00')
  })

  it('un fuseau inconnu du navigateur retombe sur le fuseau par défaut au lieu de casser l’écran', async () => {
    await connectedWithTimeZone('Mars/Phobos')

    expect(formatTimeFr('2026-10-15T08:15:00.000Z')).toBe('09h15')
  })
})

describe('jour civil en toutes lettres', () => {
  it('écrit le jour de la semaine, le jour, le mois et l’année', () => {
    expect(formatDayFr(CivilDate.fromString('2026-10-15'))).toBe('jeudi 15 octobre 2026')
    expect(formatDayFr(CivilDate.fromString('2026-10-22'))).toBe('jeudi 22 octobre 2026')
    expect(formatDayFr(CivilDate.fromString('2028-02-29'))).toBe('mardi 29 février 2028')
  })

  it('ne dépend pas du fuseau du cabinet : c’est une date du calendrier', async () => {
    await connectedWithTimeZone('Pacific/Kiritimati')
    expect(formatDayFr(CivilDate.fromString('2026-10-15'))).toBe('jeudi 15 octobre 2026')
    resetSessionForTests()
    await connectedWithTimeZone('Pacific/Pago_Pago')
    expect(formatDayFr(CivilDate.fromString('2026-10-15'))).toBe('jeudi 15 octobre 2026')
  })
})
