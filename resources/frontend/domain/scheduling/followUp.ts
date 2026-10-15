import { RuleViolation } from '../errors'
import type { CivilDate } from '../values/civilDate'
import { assertSafeInteger } from '../values/integer'

/**
 * Dates d'un contrôle recommandé (AT22), jumelles de `app/Domain/Scheduling/Rules/FollowUpDates.php` :
 * départ choisi par le professionnel + délai au calendrier civil ; aucune durée de traitement supposée.
 */
export type IntervalUnit = 'DAY' | 'MONTH' | 'YEAR'
export type AdvanceUnit = 'DAY' | 'MONTH'

export function recommendedDate(anchor: CivilDate, value: number, unit: string): CivilDate {
  assertSafeInteger(value, 'interval_value')
  if (value < 1) {
    throw RuleViolation.invalid(
      'interval_value',
      'follow_up.interval_positive',
      'Le délai du contrôle doit être d’au moins 1.',
    )
  }
  if (unit === 'DAY') return anchor.addDays(value, 'recommended_date')
  if (unit === 'MONTH') return anchor.addMonths(value, 'recommended_date')
  if (unit === 'YEAR') return anchor.addYears(value, 'recommended_date')
  throw RuleViolation.invalid('interval_unit', 'follow_up.unit_unknown', 'Unité de délai inconnue.')
}

export function reminderDate(recommended: CivilDate, advance: number, unit: string): CivilDate {
  assertSafeInteger(advance, 'advance_value')
  if (advance < 0) {
    throw RuleViolation.invalid(
      'advance_value',
      'follow_up.advance_negative',
      'L’avance d’un rappel ne peut pas être négative.',
    )
  }
  if (unit === 'DAY') return recommended.addDays(-advance, 'reminder_date')
  if (unit === 'MONTH') return recommended.addMonths(-advance, 'reminder_date')
  throw RuleViolation.invalid('advance_unit', 'follow_up.unit_unknown', 'Unité de délai inconnue.')
}
