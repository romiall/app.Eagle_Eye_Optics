import { RuleViolation } from '../errors'
import { CivilDate } from './civilDate'
import { floorMod, intDiv } from './integer'

/**
 * Instant UTC à la milliseconde, forme fixe `2026-10-14T09:30:00.000Z`, jumeau de
 * `app/Domain/Shared/ValueObjects/Instant.php`. Porté par un nombre entier de millisecondes, jamais
 * antérieur à 1970 : aucun événement de l'application ne le précède, et c'est avant 1919 que les
 * bases de fuseaux de PHP et des navigateurs divergent pour Africa/Douala.
 */
export class Instant {
  readonly epochMilliseconds: number

  private constructor(epochMilliseconds: number) {
    this.epochMilliseconds = epochMilliseconds
  }

  static fromEpochMilliseconds(milliseconds: number): Instant {
    if (!Number.isSafeInteger(milliseconds) || milliseconds < 0) {
      throw new Error('Instant hors plage (antérieur à 1970 ou non entier).')
    }
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
    const milliseconds =
      ((date.dayNumber() * 24 + hour) * 60 + minute) * 60_000 + second * 1000 + Number(parts[7])
    if (milliseconds < 0) {
      throw RuleViolation.invalid(
        field,
        'instant.out_of_range',
        'Instant antérieur au 1er janvier 1970 (UTC).',
      )
    }
    return new Instant(milliseconds)
  }

  toString(): string {
    const milliseconds = floorMod(this.epochMilliseconds, 86_400_000)
    const days = intDiv(this.epochMilliseconds - milliseconds, 86_400_000)
    const pad = (value: number, length = 2): string => String(value).padStart(length, '0')
    return (
      `${CivilDate.fromDayNumber(days).toString()}T` +
      `${pad(intDiv(milliseconds, 3_600_000))}:${pad(intDiv(milliseconds % 3_600_000, 60_000))}:` +
      `${pad(intDiv(milliseconds % 60_000, 1000))}.${pad(milliseconds % 1000, 3)}Z`
    )
  }

  civilDate(timeZone: string, field = 'date'): CivilDate {
    return CivilDate.fromEpochMilliseconds(this.epochMilliseconds, timeZone, field)
  }
}
