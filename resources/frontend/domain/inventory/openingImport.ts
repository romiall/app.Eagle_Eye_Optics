/**
 * Stock d'ouverture depuis un fichier CSV (X01, « importer catalogue, stock ») : le fichier REMPLIT le
 * formulaire de stock initial existant ; la gérante relit les lignes, puis les enregistre par le circuit
 * habituel (motif, approbation directe, mot de passe confirmé, mouvement OPENING par ligne). Rien n'est
 * envoyé par cette lecture. Règles pures, sans calcul flottant.
 *
 * Colonnes : reference (obligatoire, article du catalogue actif et géré en stock), quantite (obligatoire,
 * telle qu'écrite : « 2 » ou « 1,5 » selon l'unité, vérifiée par le formulaire), emplacement (code ou nom ;
 * facultatif s'il n'existe qu'un emplacement), lot, peremption (JJ/MM/AAAA ou AAAA-MM-JJ), cout_unitaire
 * (FCFA entiers, facultatif : un coût inconnu reste vide, jamais 0).
 */
import {
  headerKey,
  type ImportProblem,
  IMPORT_MAX_ROWS,
  parseCsv,
} from '@/domain/billing/catalogImport'
import type { CatalogItem, StockLocation } from '@/domain/contracts/generated'
import { CivilDate } from '@/domain/values/civilDate'
import { parseMoney } from '@/domain/values/money'
import { newUlid } from '@/domain/values/ulid'

import type { OpeningLineForm } from './opening'

const COLUMNS = {
  reference: ['reference', 'ref', 'code_article'],
  quantity: ['quantite', 'qte'],
  location: ['emplacement', 'lieu'],
  batch: ['lot', 'numero_lot'],
  expires: ['peremption', 'date_peremption', 'expiration'],
  cost: ['cout_unitaire', 'cout_achat', 'prix_achat'],
} as const

type Column = keyof typeof COLUMNS

/** Comparaison des références comme la base : casse et accents ignorés, ponctuation conservée. */
function referenceKey(reference: string): string {
  return reference
    .normalize('NFD')
    .replace(/\p{Mn}+/gu, '')
    .toLowerCase()
    .trim()
}

export function openingTemplateCsv(): string {
  return [
    'reference;quantite;emplacement;lot;peremption;cout_unitaire',
    'EX-MONT-001;3;;;;',
    'EX-COLL-001;10;;LOT-EXEMPLE;31/12/2027;',
  ].join('\r\n')
}

export function readOpeningCsv(
  csv: string,
  items: readonly CatalogItem[],
  locations: readonly StockLocation[],
  newId: () => string = newUlid,
): { lines: OpeningLineForm[]; problems: ImportProblem[] } {
  const records = parseCsv(csv)
  const header = records[0]
  if (header === undefined)
    return { lines: [], problems: [{ line: 1, column: null, message: 'Le fichier est vide.' }] }

  const positions = new Map<Column, number>()
  header.forEach((name, position) => {
    const key = headerKey(name)
    for (const [column, names] of Object.entries(COLUMNS) as [Column, readonly string[]][]) {
      if (names.includes(key) && !positions.has(column)) positions.set(column, position)
    }
  })
  const missing = (['reference', 'quantity'] as const).filter((column) => !positions.has(column))
  if (missing.length > 0) {
    return {
      lines: [],
      problems: missing.map((column) => ({
        line: 1,
        column: COLUMNS[column][0],
        message: `Colonne obligatoire absente : « ${COLUMNS[column][0]} ».`,
      })),
    }
  }
  const rows = records.slice(1)
  if (rows.length === 0)
    return {
      lines: [],
      problems: [{ line: 2, column: null, message: 'Aucune ligne après l’en-tête.' }],
    }
  if (rows.length > IMPORT_MAX_ROWS) {
    return {
      lines: [],
      problems: [
        {
          line: IMPORT_MAX_ROWS + 2,
          column: null,
          message: `Au plus ${IMPORT_MAX_ROWS} lignes par fichier.`,
        },
      ],
    }
  }

  const byReference = new Map(items.map((item) => [referenceKey(item.reference), item]))
  const locationOf = (text: string): StockLocation | null => {
    const key = headerKey(text)
    return (
      locations.find(
        (location) => headerKey(location.code) === key || headerKey(location.name) === key,
      ) ?? null
    )
  }
  const onlyLocation = locations.length === 1 ? (locations[0] ?? null) : null

  const problems: ImportProblem[] = []
  const lines: OpeningLineForm[] = []
  rows.forEach((cells, offset) => {
    const line = offset + 2
    const cell = (column: Column): string => {
      const position = positions.get(column)
      return position === undefined ? '' : (cells[position] ?? '').trim()
    }
    const problem = (column: string, message: string): void => {
      problems.push({ line, column, message })
    }

    const item = byReference.get(referenceKey(cell('reference'))) ?? null
    if (cell('reference') === '') problem('reference', 'La référence est obligatoire.')
    else if (item === null)
      problem('reference', 'Référence absente du catalogue (importez d’abord le catalogue).')
    else if (item.stock_mode !== 'STOCKED' || !item.active) {
      problem('reference', 'Seul un article actif géré en stock reçoit un stock initial.')
    }

    let location: StockLocation | null = onlyLocation
    if (cell('location') !== '') {
      location = locationOf(cell('location'))
      if (location === null)
        problem('emplacement', 'Emplacement inconnu : indiquez son code ou son nom.')
    } else if (location === null) {
      problem('emplacement', 'Plusieurs emplacements existent : indiquez lequel.')
    }

    const quantity = cell('quantity')
    if (quantity === '') problem('quantite', 'La quantité est obligatoire.')

    let expiresOn: string | null = null
    if (cell('expires') !== '') {
      try {
        expiresOn = CivilDate.parse(cell('expires'))?.toString() ?? null
      } catch {
        problem('peremption', 'Date invalide : JJ/MM/AAAA ou AAAA-MM-JJ.')
      }
    }

    let unitCost: number | null = null
    try {
      unitCost = parseMoney(cell('cost'), 'cout_unitaire')
    } catch {
      problem('cout_unitaire', 'Montant invalide : un nombre entier de FCFA, par exemple 25 000.')
    }

    lines.push({
      movementId: newId(),
      lotId: newId(),
      itemId: item?.id ?? null,
      locationId: location?.id ?? null,
      quantity: quantity === '' ? null : quantity,
      batchNumber: cell('batch'),
      expiresOn,
      unitCost,
    })
  })

  return { lines: problems.length === 0 ? lines : [], problems }
}
