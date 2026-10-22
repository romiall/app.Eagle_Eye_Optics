import { NBSP } from './charset'
import { invalid } from './errors'

/**
 * Mise en forme française des valeurs imprimées (D01, fichier 11). Les montants sont des entiers FCFA
 * affichés par groupes de trois chiffres avec une espace INSÉCABLE U+00A0 (jamais l'espace fine U+202F
 * de l'interface : elle n'existe pas dans le jeu de caractères des PDF, voir `charset.ts`), sans aucun
 * calcul flottant : les sommes se font en BigInt. Une valeur absente s'imprime par un tiret, jamais par
 * zéro (fichier 11 : « non mesuré indiqué par tiret/mention, jamais zéro par défaut »).
 */
export const DEFAULT_TIME_ZONE = 'Africa/Douala'
export const CURRENCY_LABEL = 'FCFA'
/** Tiret cadratin : valeur absente ou non mesurée. */
export const MISSING = '\u{2014}'

const MONEY_LIMIT = 1_000_000_000_000

function groupDigits(digits: string): string {
  const groups: string[] = []
  for (let end = digits.length; end > 0; end -= 3) {
    groups.unshift(digits.slice(Math.max(0, end - 3), end))
  }
  return groups.join(NBSP)
}

/** « 80 000 » (espaces insécables), « -50 000 » ; refuse un montant non entier ou hors bornes. */
export function formatNumberFr(amount: number, field = 'amount'): string {
  if (!Number.isSafeInteger(amount) || Math.abs(amount) > MONEY_LIMIT) {
    throw invalid('amount.invalid', field, 'Montant non entier ou hors des bornes admises.')
  }
  return (amount < 0 ? '-' : '') + groupDigits(String(Math.abs(amount)))
}

/** « 80 000 FCFA » : l'unité et les groupes de chiffres ne se coupent jamais en fin de ligne. */
export function formatXaf(amount: number, field = 'amount'): string {
  return `${formatNumberFr(amount, field)}${NBSP}${CURRENCY_LABEL}`
}

/** Somme exacte de montants entiers (BigInt) ; refuse un total hors des bornes admises. */
export function sumMoney(values: readonly number[], field = 'amount'): number {
  let total = 0n
  for (const value of values) {
    if (!Number.isSafeInteger(value)) {
      throw invalid('amount.invalid', field, 'Montant non entier.')
    }
    total += BigInt(value)
  }
  if (total > BigInt(MONEY_LIMIT) || total < -BigInt(MONEY_LIMIT)) {
    throw invalid('amount.out_of_range', field, 'Total hors des bornes admises.')
  }
  return Number(total)
}

/** Quantité décimale canonique (« 2.000 », « 1.500 ») : « 2 », « 1,5 » ; jamais arrondie. */
export function formatQuantity(canonical: string, field = 'quantity'): string {
  const match = /^(-?)([0-9]+)(?:\.([0-9]+))?$/.exec(canonical)
  if (match === null) throw invalid('quantity.invalid', field, 'Quantité invalide.')
  const integer = (match[2] ?? '0').replace(/^0+(?=[0-9])/, '')
  const fraction = (match[3] ?? '').replace(/0+$/, '')
  return `${match[1] ?? ''}${groupDigits(integer)}${fraction === '' ? '' : `,${fraction}`}`
}

/** Taux en pourcentage canonique (« 19.2500 ») : « 19,25 % » ; zéro est une valeur. */
export function formatRate(canonical: string, field = 'rate'): string {
  const match = /^([0-9]+)(?:\.([0-9]+))?$/.exec(canonical)
  if (match === null) throw invalid('rate.invalid', field, 'Taux invalide.')
  const integer = (match[1] ?? '0').replace(/^0+(?=[0-9])/, '')
  const fraction = (match[2] ?? '').replace(/0+$/, '')
  return `${integer}${fraction === '' ? '' : `,${fraction}`}${NBSP}%`
}

/**
 * Valeur optique (sphère, cylindre, addition, DP, hauteur) : signe explicite pour toute valeur non
 * nulle, virgule française, décimales telles qu'enregistrées. Zéro est visible (« 0,00 ») ; une
 * valeur absente est un tiret, JAMAIS zéro.
 */
export function formatDiopter(value: string | null, field = 'diopter'): string {
  if (value === null) return MISSING
  const match = /^([+-]?)([0-9]+)(?:\.([0-9]+))?$/.exec(value)
  if (match === null) throw invalid('diopter.invalid', field, 'Valeur optique invalide.')
  const integer = (match[2] ?? '0').replace(/^0+(?=[0-9])/, '')
  const fraction = match[3] ?? ''
  const isZero = /^0*$/.test(integer + fraction)
  const sign = isZero ? '' : match[1] === '-' ? '-' : '+'
  return `${sign}${integer}${fraction === '' ? '' : `,${fraction}`}`
}

/** Mesure positive sans signe (DP, hauteur de montage) suivie de son unité : « 63,00 mm ». */
export function formatMillimeters(value: string | null, field = 'millimeters'): string {
  if (value === null) return MISSING
  const text = formatDiopter(value, field)
  return `${text.startsWith('+') ? text.slice(1) : text}${NBSP}mm`
}

/** Axe en degrés : entier de 0 à 180, « 90° » ; absent : tiret. */
export function formatAxis(axis: number | null, field = 'axis'): string {
  if (axis === null) return MISSING
  if (!Number.isInteger(axis) || axis < 0 || axis > 180) {
    throw invalid('axis.invalid', field, 'Axe hors de 0 à 180 degrés.')
  }
  return `${axis}\u{00B0}`
}

const INSTANT = /^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}Z$/

export function parseInstant(value: string, field = 'instant'): Date {
  const date = new Date(value)
  if (!INSTANT.test(value) || Number.isNaN(date.getTime())) {
    throw invalid('instant.invalid', field, 'Instant invalide : forme AAAA-MM-JJTHH:MM:SS.mmmZ.')
  }
  return date
}

interface ZonedParts {
  day: string
  month: string
  year: string
  hour: string
  minute: string
}

/**
 * Parties d'un instant UTC à l'heure du cabinet. Un fuseau inconnu du navigateur retombe sur celui
 * du cabinet par défaut (proposition d'après les sources) plutôt que de bloquer une impression.
 */
function zonedParts(instant: string, timeZone: string, field: string): ZonedParts {
  const date = parseInstant(instant, field)
  const parts = (zone: string): Intl.DateTimeFormatPart[] =>
    new Intl.DateTimeFormat('fr-FR', {
      timeZone: zone,
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    }).formatToParts(date)
  let found: Intl.DateTimeFormatPart[]
  try {
    found = parts(timeZone)
  } catch (error) {
    if (!(error instanceof RangeError)) throw error
    found = parts(DEFAULT_TIME_ZONE)
  }
  const read = (type: Intl.DateTimeFormatPartTypes): string =>
    found.find((part) => part.type === type)?.value ?? '00'
  return {
    day: read('day'),
    month: read('month'),
    year: read('year'),
    hour: read('hour'),
    minute: read('minute'),
  }
}

/** « 15/10/2026 » (jour civil du cabinet, jamais un minuit UTC). */
export function formatDateFr(instant: string, timeZone: string, field = 'date'): string {
  const parts = zonedParts(instant, timeZone, field)
  return `${parts.day}/${parts.month}/${parts.year}`
}

/** « 15/10/2026 à 10h42 » à l'heure du cabinet. */
export function formatDateTimeFr(instant: string, timeZone: string, field = 'date'): string {
  const parts = zonedParts(instant, timeZone, field)
  return `${parts.day}/${parts.month}/${parts.year} \u{00E0} ${parts.hour}h${parts.minute}`
}

/** Jour civil « AAAA-MM-JJ » d'un instant, à l'heure du cabinet. */
export function civilDateOf(instant: string, timeZone: string, field = 'date'): string {
  const parts = zonedParts(instant, timeZone, field)
  return `${parts.year}-${parts.month}-${parts.day}`
}

/** « 2026-10-15 » → « 15/10/2026 » (date civile déjà au fuseau du cabinet : aucun calcul de fuseau). */
export function formatCivilDateFr(civil: string, field = 'date'): string {
  const match = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(civil)
  if (match === null) throw invalid('date.invalid', field, 'Date civile invalide : AAAA-MM-JJ.')
  return `${match[3]}/${match[2]}/${match[1]}`
}

/** « NOM Prénoms » tel qu'enregistré dans le snapshot ; le prénom absent n'est pas inventé. */
export function formatPersonName(lastName: string, firstNames: string | null): string {
  const first = firstNames === null ? '' : firstNames.trim()
  return first === '' ? lastName : `${lastName} ${first}`
}

/** Fin d'un identifiant opaque (référence lisible sans exposer l'identifiant entier). */
export function shortReference(identifier: string): string {
  return identifier.slice(-8)
}
