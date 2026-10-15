import { RuleViolation } from '../errors'
import { CivilDate } from './civilDate'

/**
 * Instant UTC à la milliseconde, forme fixe `2026-10-14T09:30:00.000Z`, jumeau de
 * `app/Domain/Shared/ValueObjects/Instant.php`. Porté par un nombre entier de millisecondes.
 */
export class Instant {
  readonly epochMilliseconds: number

  private constructor(epochMilliseconds: number) {
    this.epochMilliseconds = epochMilliseconds
  }

  static fromEpochMilliseconds(milliseconds: number): Instant {
    if (!Number.isSafeInteger(milliseconds)) throw new Error('Instant hors plage.')
    return new Instant(milliseconds)
  }

  static parse(text: string | null, field = 'occurred_at'): Instant | null {
    if (text === null || text === '') return null
    const parts =
      /^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})\.([0-9]{3})Z$/.exec(text)
    const invalid = (): RuleViolation =>
      RuleViolation.invalid(
        field,
        'instant.format',
        'Instant invalide : forme AAAA-MM-JJTHH:MM:SS.mmmZ en UTC.',
      )
    if (parts === null) throw invalid()
    const date = CivilDate.of(Number(parts[1]), Number(parts[2]), Number(parts[3]), field)
    const [hour, minute, second] = [Number(parts[4]), Number(parts[5]), Number(parts[6])]
    if (hour > 23 || minute > 59 || second > 59) throw invalid()
    return new Instant(
      ((date.dayNumber() * 24 + hour) * 60 + minute) * 60_000 + second * 1000 + Number(parts[7]),
    )
  }

  toString(): string {
    const milliseconds = ((this.epochMilliseconds % 86_400_000) + 86_400_000) % 86_400_000
    const days = Math.trunc((this.epochMilliseconds - milliseconds) / 86_400_000)
    const pad = (value: number, length = 2): string => String(value).padStart(length, '0')
    return (
      `${CivilDate.fromDayNumber(days).toString()}T` +
      `${pad(Math.trunc(milliseconds / 3_600_000))}:${pad(Math.trunc((milliseconds % 3_600_000) / 60_000))}:` +
      `${pad(Math.trunc((milliseconds % 60_000) / 1000))}.${pad(milliseconds % 1000, 3)}Z`
    )
  }

  civilDate(timeZone: string, field = 'date'): CivilDate {
    return CivilDate.fromEpochMilliseconds(this.epochMilliseconds, timeZone, field)
  }
}
