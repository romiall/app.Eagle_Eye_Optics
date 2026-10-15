/**
 * Refus d'une règle pure (F08), jumeau de `app/Domain/Shared/RuleViolation.php` : même code d'API
 * (fichier 08), même champ et même identifiant de règle. Les vecteurs de `contracts/vectors/`
 * comparent ces trois valeurs dans les deux moteurs.
 */
export type RuleErrorCode =
  'VALIDATION_FAILED' | 'PAYMENT_EXCEEDS_BALANCE' | 'STOCK_UNAVAILABLE' | 'LOT_EXPIRED'

export type RuleDetails = Readonly<Record<string, string | number | null>>

export class RuleViolation extends Error {
  readonly code: RuleErrorCode
  readonly rule: string
  readonly field: string
  readonly details: RuleDetails

  constructor(
    code: RuleErrorCode,
    rule: string,
    field: string,
    message: string,
    details: RuleDetails = {},
  ) {
    super(message)
    this.name = 'RuleViolation'
    this.code = code
    this.rule = rule
    this.field = field
    this.details = details
  }

  static invalid(
    field: string,
    rule: string,
    message: string,
    details: RuleDetails = {},
  ): RuleViolation {
    return new RuleViolation('VALIDATION_FAILED', rule, field, message, details)
  }
}
