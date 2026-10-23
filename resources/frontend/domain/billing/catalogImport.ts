/**
 * Import du catalogue depuis un fichier CSV (X01) : lecture du fichier SUR LE POSTE (aperçu immédiat), puis
 * le serveur revérifie chaque ligne avec les règles d'un article saisi à l'écran ; l'import crée toutes les
 * lignes ou aucune. Règles pures (ni Vue, ni réseau, ni stockage), sans calcul flottant.
 *
 * Fichier attendu : une ligne d'en-tête (noms de colonnes en français, casse et accents indifférents),
 * séparateur « ; », « , » ou tabulation détecté sur l'en-tête, guillemets doubles pour un texte qui contient
 * le séparateur, encodage UTF-8 (avec ou sans BOM). Aucun prix n'est proposé par l'application : tout vient
 * du fichier du cabinet.
 */
import type { CatalogImportRequest, TaxRule } from '@/domain/contracts/generated'
import { parseMoney } from '@/domain/values/money'

import { CATEGORY_LABELS, UNIT_INTEGER_ONLY } from './catalog'

export type CatalogImportRow = CatalogImportRequest['rows'][number]

export const IMPORT_MAX_ROWS = 500

export interface ImportProblem {
  readonly line: number
  readonly column: string | null
  readonly message: string
}

export interface ParsedImport {
  readonly rows: CatalogImportRow[]
  readonly problems: ImportProblem[]
  /** En-têtes non reconnus (ignorés, signalés). */
  readonly ignoredColumns: string[]
}

type Field =
  | 'reference'
  | 'category'
  | 'name'
  | 'brand'
  | 'model'
  | 'unit'
  | 'integerOnly'
  | 'salePrice'
  | 'purchaseCost'
  | 'tax'
  | 'stockMode'
  | 'requiresExpiry'
  | 'threshold'
  | 'alertDays'
  | 'active'
  | 'publicVisibility'

/** Colonnes du modèle, dans l'ordre du fichier modèle ; la première écriture est celle du modèle. */
export const IMPORT_COLUMNS: readonly {
  field: Field
  headers: readonly string[]
  required: boolean
}[] = [
  { field: 'reference', headers: ['reference', 'ref', 'code_article'], required: true },
  { field: 'name', headers: ['designation', 'nom', 'libelle'], required: true },
  { field: 'category', headers: ['categorie'], required: true },
  { field: 'brand', headers: ['marque'], required: false },
  { field: 'model', headers: ['modele'], required: false },
  { field: 'unit', headers: ['unite'], required: true },
  { field: 'integerOnly', headers: ['quantite_entiere'], required: false },
  { field: 'salePrice', headers: ['prix_vente', 'prix_de_vente', 'prix'], required: true },
  { field: 'purchaseCost', headers: ['cout_achat', 'prix_achat'], required: false },
  { field: 'tax', headers: ['taxe', 'code_taxe'], required: false },
  { field: 'stockMode', headers: ['mode_stock', 'stock'], required: true },
  { field: 'requiresExpiry', headers: ['perissable', 'peremption'], required: false },
  { field: 'threshold', headers: ['seuil_stock', 'seuil'], required: false },
  { field: 'alertDays', headers: ['alerte_peremption_jours', 'alerte_jours'], required: false },
  { field: 'active', headers: ['actif'], required: false },
  { field: 'publicVisibility', headers: ['visible_public', 'public'], required: false },
]

const UNIT_WORDS: Readonly<Record<string, string>> = {
  unit: 'UNIT',
  unite: 'UNIT',
  piece: 'UNIT',
  pair: 'PAIR',
  paire: 'PAIR',
  service: 'SERVICE',
  prestation: 'SERVICE',
  other: 'OTHER',
  autre: 'OTHER',
}

const STOCK_WORDS: Readonly<Record<string, CatalogImportRow['stock_mode']>> = {
  none: 'NONE',
  aucun: 'NONE',
  sans_stock: 'NONE',
  stocked: 'STOCKED',
  stocke: 'STOCKED',
  en_stock: 'STOCKED',
  made_to_order: 'MADE_TO_ORDER',
  sur_mesure: 'MADE_TO_ORDER',
  commande: 'MADE_TO_ORDER',
}

const YES = new Set(['oui', 'o', 'yes', 'y', 'vrai', 'true', '1', 'x'])
const NO = new Set(['non', 'n', 'no', 'faux', 'false', '0'])

/** Clé de comparaison : minuscules, sans accents, séparateurs ramenés à « _ ». */
export function headerKey(text: string): string {
  return text
    .normalize('NFD')
    .replace(/\p{Mn}+/gu, '')
    .toLowerCase()
    .trim()
    .replace(/[\s\-'’.]+/g, '_')
}

/** Séparateur de l'en-tête : celui des trois qui y apparaît le plus (hors guillemets). */
function delimiterOf(headerLine: string): string {
  const counts = new Map<string, number>([
    [';', 0],
    [',', 0],
    ['\t', 0],
  ])
  let quoted = false
  for (const character of headerLine) {
    if (character === '"') quoted = !quoted
    else if (!quoted && counts.has(character))
      counts.set(character, (counts.get(character) ?? 0) + 1)
  }
  let best = ';'
  for (const [candidate, count] of counts) {
    if (count > (counts.get(best) ?? 0)) best = candidate
  }
  return best
}

/** Lecture CSV (RFC 4180) : guillemets doublés, séparateurs et retours à la ligne entre guillemets. */
export function parseCsv(text: string): string[][] {
  const source = text.startsWith('\u{FEFF}') ? text.slice(1) : text
  const firstLine = source.split(/\r?\n/, 1)[0] ?? ''
  const delimiter = delimiterOf(firstLine)
  const records: string[][] = []
  let record: string[] = []
  let field = ''
  let quoted = false
  for (let index = 0; index < source.length; index++) {
    const character = source[index] ?? ''
    if (quoted) {
      if (character === '"') {
        if (source[index + 1] === '"') {
          field += '"'
          index++
        } else {
          quoted = false
        }
      } else {
        field += character
      }
      continue
    }
    if (character === '"' && field === '') {
      quoted = true
    } else if (character === delimiter) {
      record.push(field)
      field = ''
    } else if (character === '\n' || character === '\r') {
      if (character === '\r' && source[index + 1] === '\n') index++
      record.push(field)
      records.push(record)
      record = []
      field = ''
    } else {
      field += character
    }
  }
  if (field !== '' || record.length > 0) {
    record.push(field)
    records.push(record)
  }
  return records.filter((row) => row.some((cell) => cell.trim() !== ''))
}

function text(value: string): string | null {
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

function yesNo(value: string, fallback: boolean): boolean | 'invalid' {
  const key = headerKey(value)
  if (key === '') return fallback
  if (YES.has(key)) return true
  if (NO.has(key)) return false
  return 'invalid'
}

/** Catégorie par son code (FRAME…) ou son libellé (« Monture »). */
function categoryCode(value: string): string | null {
  const key = headerKey(value)
  if (key === '') return null
  for (const [code, label] of Object.entries(CATEGORY_LABELS)) {
    if (headerKey(code) === key || headerKey(label) === key) return code
  }
  return value.trim().toUpperCase()
}

function unitCode(value: string): string | null {
  const key = headerKey(value)
  if (key === '') return null
  return UNIT_WORDS[key] ?? value.trim().toUpperCase()
}

function stockMode(value: string): CatalogImportRow['stock_mode'] | 'invalid' {
  const key = headerKey(value)
  if (key === '') return null
  return STOCK_WORDS[key] ?? 'invalid'
}

/**
 * Lignes du fichier → lignes de la requête d'import. Les problèmes de lecture (montant mal écrit, oui/non
 * illisible, taxe inconnue, colonne obligatoire absente) sont dits avec leur numéro de ligne du fichier ; le
 * reste des règles (catégorie, unité, cohérences) est vérifié par le serveur à l'aperçu.
 */
export function readCatalogCsv(csv: string, taxRules: readonly TaxRule[]): ParsedImport {
  const records = parseCsv(csv)
  const problems: ImportProblem[] = []
  const header = records[0]
  if (header === undefined) {
    return {
      rows: [],
      problems: [{ line: 1, column: null, message: 'Le fichier est vide.' }],
      ignoredColumns: [],
    }
  }

  const positions = new Map<Field, number>()
  const ignoredColumns: string[] = []
  header.forEach((name, position) => {
    const key = headerKey(name)
    const column = IMPORT_COLUMNS.find((candidate) => candidate.headers.includes(key))
    if (column === undefined) {
      if (key !== '') ignoredColumns.push(name.trim())
    } else if (!positions.has(column.field)) {
      positions.set(column.field, position)
    }
  })
  for (const column of IMPORT_COLUMNS) {
    if (column.required && !positions.has(column.field)) {
      problems.push({
        line: 1,
        column: column.headers[0] ?? null,
        message: `Colonne obligatoire absente : « ${column.headers[0] ?? ''} ».`,
      })
    }
  }
  if (problems.length > 0) return { rows: [], problems, ignoredColumns }

  const dataRows = records.slice(1)
  if (dataRows.length === 0) {
    return {
      rows: [],
      problems: [{ line: 2, column: null, message: 'Aucun article après l’en-tête.' }],
      ignoredColumns,
    }
  }
  if (dataRows.length > IMPORT_MAX_ROWS) {
    return {
      rows: [],
      problems: [
        {
          line: IMPORT_MAX_ROWS + 2,
          column: null,
          message: `Au plus ${IMPORT_MAX_ROWS} articles par fichier : découpez le catalogue en plusieurs fichiers.`,
        },
      ],
      ignoredColumns,
    }
  }

  const taxByCode = new Map(taxRules.map((rule) => [headerKey(rule.code), rule.id]))
  const rows: CatalogImportRow[] = []
  dataRows.forEach((cells, offset) => {
    const line = offset + 2
    const cell = (field: Field): string => {
      const position = positions.get(field)
      return position === undefined ? '' : (cells[position] ?? '')
    }
    const problem = (column: string, message: string): void => {
      problems.push({ line, column, message })
    }

    const money = (field: Field, column: string): number | null => {
      try {
        return parseMoney(cell(field), column)
      } catch {
        problem(column, 'Montant invalide : un nombre entier de FCFA, par exemple 80 000.')
        return null
      }
    }

    const unit = unitCode(cell('unit'))
    const integerOnly = yesNo(
      cell('integerOnly'),
      unit === null ? false : (UNIT_INTEGER_ONLY[unit] ?? false),
    )
    const requiresExpiry = yesNo(cell('requiresExpiry'), false)
    const active = yesNo(cell('active'), true)
    const visibility = yesNo(cell('publicVisibility'), false)
    for (const [value, column] of [
      [integerOnly, 'quantite_entiere'],
      [requiresExpiry, 'perissable'],
      [active, 'actif'],
      [visibility, 'visible_public'],
    ] as const) {
      if (value === 'invalid') problem(column, 'Valeur attendue : oui ou non.')
    }

    const mode = stockMode(cell('stockMode'))
    if (mode === 'invalid')
      problem('mode_stock', 'Mode de stock attendu : sans stock, stocké ou sur mesure.')

    let taxRuleId: string | null = null
    const taxCode = headerKey(cell('tax'))
    if (taxCode !== '') {
      taxRuleId = taxByCode.get(taxCode) ?? null
      if (taxRuleId === null)
        problem('taxe', 'Code de taxe inconnu dans les règles fiscales du cabinet.')
    }

    const alertText = cell('alertDays').trim()
    let alertDays: number | null = null
    if (alertText !== '') {
      if (/^[0-9]{1,4}$/.test(alertText)) alertDays = Number(alertText)
      else problem('alerte_peremption_jours', 'Nombre entier de jours attendu.')
    }

    rows.push({
      line,
      reference: text(cell('reference')),
      category_code: categoryCode(cell('category')),
      name: text(cell('name')),
      brand: text(cell('brand')),
      model: text(cell('model')),
      unit_code: unit,
      quantity_integer_only: integerOnly === true,
      sale_price: money('salePrice', 'prix_vente'),
      purchase_cost: money('purchaseCost', 'cout_achat'),
      tax_rule_id: taxRuleId,
      stock_mode: mode === 'invalid' ? null : mode,
      requires_expiry: requiresExpiry === true,
      low_stock_threshold: text(cell('threshold')),
      expiry_alert_days: alertDays,
      active: active !== false,
      public_visibility: visibility === true,
    })
  })

  return { rows, problems, ignoredColumns }
}

/** Fichier modèle à remplir (en-tête et deux lignes d'exemple FICTIVES, à remplacer). */
export function catalogTemplateCsv(): string {
  const header = IMPORT_COLUMNS.map((column) => column.headers[0] ?? '').join(';')
  return [
    header,
    'EX-MONT-001;Monture exemple (à remplacer);Monture;Marque exemple;Modèle 1;Paire;oui;50 000;;;stocké;non;2;;oui;non',
    'EX-EXAM-001;Examen de vue (exemple);Prestation / examen;;;Prestation;non;5 000;;;sans stock;non;;;oui;non',
  ].join('\r\n')
}
