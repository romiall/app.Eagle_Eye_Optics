import { clinicTimeZone } from '@/app/clinicTime'
import { CivilDate } from '@/domain/values/civilDate'

/**
 * Instants UTC d'une heure locale du cabinet (jamais l'heure du navigateur) : l'heure locale lue à minuit UTC donne
 * le décalage du fuseau (Africa/Douala : +1 h, sans changement d'heure) ; un fuseau en retard sur UTC tombe la
 * veille. Saisie « HH:MM » contrôlée ; null si elle est invalide.
 */
export function localInstant(
  day: CivilDate,
  time: string,
  timeZone: string = clinicTimeZone(),
): string | null {
  const match = /^([01][0-9]|2[0-3]):([0-5][0-9])$/.exec(time.trim())
  if (match === null) return null
  const utcMidnight = Date.UTC(day.year, day.month - 1, day.day)
  const localHour = Number(
    new Intl.DateTimeFormat('en-GB', { timeZone, hour: '2-digit', hourCycle: 'h23' }).format(
      new Date(utcMidnight),
    ),
  )
  const sameDay = CivilDate.fromEpochMilliseconds(utcMidnight, timeZone).compare(day) === 0
  const offsetHours = sameDay ? localHour : localHour - 24
  const minutes = Number(match[1]) * 60 + Number(match[2])
  return new Date(utcMidnight - offsetHours * 3_600_000 + minutes * 60_000).toISOString()
}

/** Minuit du cabinet d'un jour civil, en instant UTC. */
export function localMidnight(day: CivilDate, timeZone: string = clinicTimeZone()): string {
  return localInstant(day, '00:00', timeZone) as string
}
