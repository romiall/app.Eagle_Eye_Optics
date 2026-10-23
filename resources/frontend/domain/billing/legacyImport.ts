/**
 * Reprise des créances papier depuis un fichier CSV (X01) : lecture SUR LE POSTE (aperçu immédiat), puis le
 * serveur revérifie chaque créance ; la reprise crée toutes les pièces ou aucune. Règles pures (ni Vue, ni
 * réseau, ni stockage), sans calcul flottant ; le reste dû vient de la règle jumelle `legacyOpeningBalance`
 * (même règle que le serveur).
 *
 * Colonnes : reference_papier (obligatoire, unique dans le fichier), dossier (numéro de dossier du patient,
 * EE-AAAA-NNNN, obligatoire), total_origine (FCFA entiers, obligatoire), deja_paye (FCFA entiers, vide = 0),
 * date_piece (JJ/MM/AAAA ou AAAA-MM-JJ, facultative). Aucune somme n'est arrondie ni devinée.
 */
import type { LegacyBalanceImportRequest } from '@/domain/contracts/generated'
import { RuleViolation } from '@/domain/errors'
import { CivilDate } from '@/domain/values/civilDate'
import { parseMoney } from '@/domain/values/money'

import { headerKey, type ImportProblem, parseCsv } from './catalogImport'
import { legacyOpeningBalance } from './legacy'

export type LegacyImportRow = LegacyBalanceImportRequest['rows'][number]

export const LEGACY_MAX_ROWS = 500

export interface ParsedLegacyImport {
  readonly rows: LegacyImportRow[]
  /** Reste dû de chaque ligne lue (même ordre que `rows`), pour l'aperçu. */
  readonly openings: number[]
  readonly problems: ImportProblem[]
}

const COLUMNS = {
  reference: ['reference_papier', 'reference', 'numero_papier'],
  record: ['dossier', 'numero_dossier', 'patient'],
  total: ['total_origine', 'total'],
  paid: ['deja_paye', 'acompte', 'deja_regle'],
  date: ['date_piece', 'date'],
} as const

type Column = keyof typeof COLUMNS

/** Comparaison des références comme la base : casse et accents ignorés, ponctuation conservée. */
export function legacyReferenceKey(reference: string): string {
  return reference
    .normalize('NFD')
    .replace(/\p{Mn}+/gu, '')
    .toLowerCase()
    .trim()
}

/** Reste dû d'une créance, ou le message de la règle jumelle (montants incohérents). */
export function openingOrProblem(originalTotal: number, alreadyPaid: number): number | string {
  try {
    return legacyOpeningBalance(originalTotal, alreadyPaid)
  } catch (error) {
    if (error instanceof RuleViolation) return error.message
    throw error
  }
}

export function legacyTemplateCsv(): string {
  return [
    'reference_papier;dossier;total_origine;deja_paye;date_piece',
    'EX-CARNET-0001;EE-2026-0001;80 000;30 000;04/11/2025',
    'EX-CARNET-0002;EE-2026-0002;45 000;0;',
  ].join('\r\n')
}

export function readLegacyCsv(csv: string): ParsedLegacyImport {
  const records = parseCsv(csv)
  const header = records[0]
  if (header === undefined) {
    return {
      rows: [],
      openings: [],
      problems: [{ line: 1, column: null, message: 'Le fichier est vide.' }],
    }
  }

  const positions = new Map<Column, number>()
  header.forEach((name, position) => {
    const key = headerKey(name)
    for (const [column, names] of Object.entries(COLUMNS) as [Column, readonly string[]][]) {
      if (names.includes(key) && !positions.has(column)) positions.set(column, position)
    }
  })
  const missing = (['reference', 'record', 'total'] as const).filter(
    (column) => !positions.has(column),
  )
  if (missing.length > 0) {
    return {
      rows: [],
      openings: [],
      problems: missing.map((column) => ({
        line: 1,
        column: COLUMNS[column][0],
        message: `Colonne obligatoire absente : « ${COLUMNS[column][0]} ».`,
      })),
    }
  }
  const dataRows = records.slice(1)
  if (dataRows.length === 0) {
    return {
      rows: [],
      openings: [],
      problems: [{ line: 2, column: null, message: 'Aucune créance après l’en-tête.' }],
    }
  }
  if (dataRows.length > LEGACY_MAX_ROWS) {
    return {
      rows: [],
      openings: [],
      problems: [
        {
          line: LEGACY_MAX_ROWS + 2,
          column: null,
          message: `Au plus ${LEGACY_MAX_ROWS} créances par fichier : découpez le carnet en plusieurs fichiers.`,
        },
      ],
    }
  }

  const problems: ImportProblem[] = []
  const rows: LegacyImportRow[] = []
  const openings: number[] = []
  const seen = new Map<string, number>()
  dataRows.forEach((cells, offset) => {
    const line = offset + 2
    const cell = (column: Column): string => {
      const position = positions.get(column)
      return position === undefined ? '' : (cells[position] ?? '').trim()
    }
    const problem = (column: string, message: string): void => {
      problems.push({ line, column, message })
    }

    const reference = cell('reference')
    if (reference === '')
      problem('reference_papier', 'La référence de la pièce papier est obligatoire.')
    else {
      const key = legacyReferenceKey(reference)
      const first = seen.get(key)
      if (first !== undefined)
        problem('reference_papier', `Référence déjà présente à la ligne ${first} du fichier.`)
      else seen.set(key, line)
    }

    const record = cell('record')
    if (record === '') problem('dossier', 'Le numéro de dossier du patient est obligatoire.')

    const money = (column: Column, name: string, emptyIsZero: boolean): number | null => {
      const text = cell(column)
      if (text === '') {
        if (!emptyIsZero) problem(name, 'Le montant est obligatoire.')
        return emptyIsZero ? 0 : null
      }
      try {
        return parseMoney(text, name)
      } catch {
        problem(name, 'Montant invalide : un nombre entier de FCFA, par exemple 80 000.')
        return null
      }
    }
    const total = money('total', 'total_origine', false)
    const paid = money('paid', 'deja_paye', true)
    let opening: number | null = null
    if (total !== null && paid !== null) {
      const result = openingOrProblem(total, paid)
      if (typeof result === 'string') problem('deja_paye', result)
      else opening = result
    }

    let date: string | null = null
    if (cell('date') !== '') {
      try {
        date = CivilDate.parse(cell('date'))?.toString() ?? null
      } catch {
        problem('date_piece', 'Date invalide : JJ/MM/AAAA ou AAAA-MM-JJ.')
      }
    }

    rows.push({
      line,
      patient_id: null,
      patient_record_number: record === '' ? null : record,
      paper_reference: reference === '' ? null : reference,
      original_total: total,
      already_paid: paid,
      original_invoice_on: date,
    })
    openings.push(opening ?? 0)
  })

  return problems.length === 0 ? { rows, openings, problems } : { rows: [], openings: [], problems }
}
