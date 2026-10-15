import { RuleViolation } from '../errors'
import { trimBlanks } from './text'

/**
 * Date civile `YYYY-MM-DD` dans le fuseau du cabinet, jamais déduite d'un minuit UTC (contrat 23).
 * Jumelle de `app/Domain/Shared/ValueObjects/CivilDate.php` : arithmétique entière (numéro de jour),
 * mois et années au calendrier civil avec report au dernier jour du mois, bornes 1000..9999.
 */
export class CivilDate {
  readonly year: number
  readonly month: number
  readonly day: number

  private constructor(year: number, month: number, day: number) {
    this.year = year
    this.month = month
    this.day = day
  }

  static of(year: number, month: number, day: number, field = 'date'): CivilDate {
    if (year < 1000 || year > 9999) {
      throw RuleViolation.invalid(
        field,
        'date.out_of_range',
        'Date hors des bornes admises (années 1000 à 9999).',
      )
    }
    if (month < 1 || month > 12 || day < 1 || day > daysInMonth(year, month)) {
      throw RuleViolation.invalid(
        field,
        'date.invalid',
        'Cette date n’existe pas dans le calendrier.',
      )
    }
    return new CivilDate(year, month, day)
  }

  /** Saisie : ISO « 2026-10-15 » ou française « 15/10/2026 ». Vide → null. */
  static parse(text: string | null, field = 'date'): CivilDate | null {
    const value = trimBlanks(text ?? '')
    if (value === '') return null
    const iso = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(value)
    if (iso !== null) return CivilDate.of(Number(iso[1]), Number(iso[2]), Number(iso[3]), field)
    const french = /^([0-9]{1,2})\/([0-9]{1,2})\/([0-9]{4})$/.exec(value)
    if (french !== null) {
      return CivilDate.of(Number(french[3]), Number(french[2]), Number(french[1]), field)
    }
    throw RuleViolation.invalid(field, 'date.format', 'Date invalide : JJ/MM/AAAA ou AAAA-MM-JJ.')
  }

  static fromString(iso: string, field = 'date'): CivilDate {
    const parts = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(iso)
    if (parts === null) {
      throw RuleViolation.invalid(field, 'date.format', 'Date invalide : JJ/MM/AAAA ou AAAA-MM-JJ.')
    }
    return CivilDate.of(Number(parts[1]), Number(parts[2]), Number(parts[3]), field)
  }

  /** Jour civil d'un instant UTC (millisecondes) dans un fuseau IANA, via Intl. */
  static fromEpochMilliseconds(milliseconds: number, timeZone: string, field = 'date'): CivilDate {
    const parts = new Intl.DateTimeFormat('en-US', {
      timeZone,
      year: 'numeric',
      month: 'numeric',
      day: 'numeric',
      calendar: 'gregory',
      numberingSystem: 'latn',
    }).formatToParts(new Date(milliseconds))
    const read = (type: Intl.DateTimeFormatPartTypes): number =>
      Number(parts.find((part) => part.type === type)?.value)
    return CivilDate.of(read('year'), read('month'), read('day'), field)
  }

  static fromDayNumber(days: number, field = 'date'): CivilDate {
    const shifted = days + 719468
    const era = Math.trunc((shifted - floorMod(shifted, 146097)) / 146097)
    const dayOfEra = shifted - era * 146097
    const yearOfEra = Math.trunc(
      (dayOfEra -
        Math.trunc(dayOfEra / 1460) +
        Math.trunc(dayOfEra / 36524) -
        Math.trunc(dayOfEra / 146096)) /
        365,
    )
    const dayOfYear =
      dayOfEra - (365 * yearOfEra + Math.trunc(yearOfEra / 4) - Math.trunc(yearOfEra / 100))
    const monthIndex = Math.trunc((5 * dayOfYear + 2) / 153)
    const day = dayOfYear - Math.trunc((153 * monthIndex + 2) / 5) + 1
    const month = monthIndex < 10 ? monthIndex + 3 : monthIndex - 9
    return CivilDate.of(yearOfEra + era * 400 + (month <= 2 ? 1 : 0), month, day, field)
  }

  toString(): string {
    return `${String(this.year).padStart(4, '0')}-${String(this.month).padStart(2, '0')}-${String(this.day).padStart(2, '0')}`
  }

  /** Numéro de jour depuis le 1970-01-01 (« days_from_civil »). */
  dayNumber(): number {
    const year = this.month <= 2 ? this.year - 1 : this.year
    const era = Math.trunc(year / 400)
    const yearOfEra = year - era * 400
    const dayOfYear =
      Math.trunc((153 * (this.month > 2 ? this.month - 3 : this.month + 9) + 2) / 5) + this.day - 1
    const dayOfEra =
      yearOfEra * 365 + Math.trunc(yearOfEra / 4) - Math.trunc(yearOfEra / 100) + dayOfYear
    return era * 146097 + dayOfEra - 719468
  }

  addDays(days: number, field = 'date'): CivilDate {
    return CivilDate.fromDayNumber(this.dayNumber() + days, field)
  }

  addMonths(months: number, field = 'date'): CivilDate {
    const index = this.year * 12 + (this.month - 1) + months
    const year = Math.trunc((index - floorMod(index, 12)) / 12)
    const month = floorMod(index, 12) + 1
    if (year < 1000 || year > 9999) {
      throw RuleViolation.invalid(
        field,
        'date.out_of_range',
        'Date hors des bornes admises (années 1000 à 9999).',
      )
    }
    return CivilDate.of(year, month, Math.min(this.day, daysInMonth(year, month)), field)
  }

  addYears(years: number, field = 'date'): CivilDate {
    return this.addMonths(12 * years, field)
  }

  compare(other: CivilDate): number {
    return this.dayNumber() === other.dayNumber()
      ? 0
      : this.dayNumber() < other.dayNumber()
        ? -1
        : 1
  }

  /** Âge en années révolues ; un 29 février compte le 1er mars d'une année non bissextile. */
  ageOn(reference: CivilDate, field = 'birth_date'): number {
    if (reference.compare(this) < 0) {
      throw RuleViolation.invalid(
        field,
        'date.after_reference',
        'La date de naissance est postérieure à la date de référence.',
      )
    }
    let age = reference.year - this.year
    if (
      reference.month < this.month ||
      (reference.month === this.month && reference.day < this.day)
    ) {
      age -= 1
    }
    return age
  }
}

export function isLeapYear(year: number): boolean {
  return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0
}

export function daysInMonth(year: number, month: number): number {
  if (month === 2) return isLeapYear(year) ? 29 : 28
  return month === 4 || month === 6 || month === 9 || month === 11 ? 30 : 31
}

function floorMod(value: number, divisor: number): number {
  return ((value % divisor) + divisor) % divisor
}
