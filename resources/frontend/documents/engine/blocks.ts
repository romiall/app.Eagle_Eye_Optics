import type { PageFlow } from './flow'
import type {
  Block,
  FieldRow,
  FieldsBlock,
  FontStyle,
  GroupBlock,
  HeadingBlock,
  NoticeBlock,
  ParagraphBlock,
  SignatureBlock,
  TableBlock,
  TableColumn,
  TableRow,
  TotalsBlock,
} from './model'
import { lineHeightMm } from './units'

/**
 * Pose des blocs d'un modèle de document sur les pages (D01, fichier 11) :
 *
 * - une ligne de tableau n'est jamais coupée entre deux pages (sauf si elle dépasse à elle seule une
 *   page entière : elle est alors découpée ligne de texte par ligne de texte, sans rien perdre) ;
 * - l'en-tête des colonnes est répété à chaque page, et jamais laissé seul en bas de page ;
 * - les dernières lignes d'un tableau restent solidaires du bloc de totaux qui suit : les totaux ne
 *   se retrouvent pas seuls sur une page quand on peut l'éviter ;
 * - un titre n'est jamais le dernier élément d'une page ; un paragraphe ne laisse ni ligne orpheline
 *   ni ligne veuve ;
 * - texte long et nom long passent à la ligne, un mot plus long qu'une ligne est coupé par caractères.
 */
const BLOCK_GAP = 2.2
const KEEP_WITH_NEXT = 16
const CELL_PAD_X = 1.3
const CELL_PAD_Y = 0.9
const MIN_ORPHAN_LINES = 2
const TEXT_GRAY = 0
const MUTED_GRAY = 85
const GRID_GRAY = 120
const HEADER_FILL = 226
const NOTICE_FILL = 244

export function fontStyleOf(bold: boolean, italic: boolean): FontStyle {
  if (bold && italic) return 'bolditalic'
  if (bold) return 'bold'
  return italic ? 'italic' : 'normal'
}

/** Largeurs des colonnes en mm : colonnes fixes d'abord, parts du reste ensuite, jamais au-delà de `total`. */
export function resolveColumnWidths(columns: readonly TableColumn[], total: number): number[] {
  let fixed = 0
  let parts = 0
  for (const column of columns) {
    if ('mm' in column.width) fixed += column.width.mm
    else parts += column.width.fr
  }
  const free = Math.max(total - fixed, 0)
  const raw = columns.map((column) =>
    'mm' in column.width ? column.width.mm : parts > 0 ? (free * column.width.fr) / parts : 0,
  )
  const sum = raw.reduce((accumulated, width) => accumulated + width, 0)
  const factor = sum > total ? total / sum : 1
  return raw.map((width) => width * factor)
}

interface MeasuredRow {
  cellLines: string[][]
  count: number
  height: number
  style: TableRow['style']
}

interface Chunk {
  rows: MeasuredRow[]
  /** Hauteur réservée sous le groupe pour le bloc qui doit rester solidaire. */
  extra: number
}

interface MeasuredField {
  labelLines: string[]
  valueLines: string[]
  height: number
}

interface MeasuredTotals {
  width: number
  labelWidth: number
  valueWidth: number
  height: number
  rows: {
    labelLines: string[]
    value: string
    emphasis: boolean
    sizePt: number
    height: number
  }[]
}

export class BlockPlacer {
  private readonly flow: PageFlow

  constructor(flow: PageFlow) {
    this.flow = flow
  }

  placeAll(blocks: readonly Block[]): void {
    blocks.forEach((block, index) => this.place(block, blocks[index + 1] ?? null))
  }

  place(block: Block, next: Block | null): void {
    switch (block.kind) {
      case 'heading':
        this.heading(block)
        return
      case 'paragraph':
        this.paragraph(block)
        return
      case 'fields':
        this.fields(block)
        return
      case 'table':
        this.table(block, next)
        return
      case 'totals':
        this.totals(block)
        return
      case 'notice':
        this.notice(block)
        return
      case 'signature':
        this.signature(block)
        return
      case 'group':
        this.group(block)
        return
      case 'spacer': {
        const flow = this.flow
        if (!flow.atTop()) flow.y = Math.min(flow.y + block.heightMm, flow.bottom)
        return
      }
      case 'rule': {
        const flow = this.flow
        flow.ensure(4)
        flow.line(flow.left, flow.y + 1, flow.right, flow.y + 1, 0.25, GRID_GRAY)
        flow.y += 3
        return
      }
      case 'pageBreak':
        if (!this.flow.atTop()) this.flow.newPage()
        return
    }
  }

  // --- Titres et paragraphes ---------------------------------------------------------------------

  private heading(block: HeadingBlock): void {
    const flow = this.flow
    const sizePt = flow.pt(block.level === 1 ? 14 : block.level === 2 ? 11 : 9.5)
    const lines = flow.wrap(block.text, flow.contentWidth, sizePt, 'bold')
    const height = lines.length * lineHeightMm(sizePt)
    const before = block.level === 1 ? 3.5 : 3
    if (!flow.atTop() && flow.remaining() < before + height + KEEP_WITH_NEXT) flow.newPage()
    if (!flow.atTop()) flow.y += before
    flow.lines(lines, flow.left, flow.y, flow.contentWidth, sizePt, 'bold')
    flow.y += height
    if (block.level === 2) {
      flow.line(flow.left, flow.y + 0.4, flow.right, flow.y + 0.4, 0.2, GRID_GRAY)
      flow.y += 1.6
    } else {
      flow.y += 0.8
    }
  }

  private paragraph(block: ParagraphBlock): void {
    const flow = this.flow
    const sizePt = block.sizePt ?? flow.pt(9.5)
    const style = fontStyleOf(block.bold, block.italic)
    const gray = block.muted ? MUTED_GRAY : TEXT_GRAY
    const lines = flow.wrap(block.text, flow.contentWidth, sizePt, style)
    const lineHeight = lineHeightMm(sizePt)
    let index = 0
    while (index < lines.length) {
      const remainingLines = lines.length - index
      let fit = Math.floor((flow.remaining() + 0.01) / lineHeight)
      if (fit >= remainingLines) {
        flow.lines(
          lines.slice(index),
          flow.left,
          flow.y,
          flow.contentWidth,
          sizePt,
          style,
          block.align,
          gray,
        )
        flow.y += remainingLines * lineHeight
        break
      }
      if (fit < MIN_ORPHAN_LINES && !flow.atTop()) {
        flow.newPage()
        continue
      }
      if (fit < 1) fit = 1
      let take = fit
      if (remainingLines - take === 1 && take > MIN_ORPHAN_LINES) take -= 1
      flow.lines(
        lines.slice(index, index + take),
        flow.left,
        flow.y,
        flow.contentWidth,
        sizePt,
        style,
        block.align,
        gray,
      )
      flow.y += take * lineHeight
      index += take
      flow.newPage()
    }
    flow.y += BLOCK_GAP * 0.6
  }

  // --- Champs « libellé : valeur » ---------------------------------------------------------------

  private measureFields(
    rows: readonly FieldRow[],
    labelWidth: number,
    valueWidth: number,
  ): MeasuredField[] {
    const flow = this.flow
    const labelPt = flow.pt(8.5)
    const valuePt = flow.pt(9.5)
    return rows.map((row) => {
      const labelLines = flow.wrap(row.label, labelWidth, labelPt, 'normal')
      const valueLines = flow.wrap(row.value, valueWidth, valuePt, 'normal')
      const height =
        Math.max(labelLines.length * lineHeightMm(labelPt), valueLines.length * lineHeightMm(valuePt)) +
        0.9
      return { labelLines, valueLines, height }
    })
  }

  private drawFields(
    rows: readonly MeasuredField[],
    x: number,
    top: number,
    labelWidth: number,
    valueWidth: number,
  ): void {
    const flow = this.flow
    let y = top
    for (const row of rows) {
      flow.lines(row.labelLines, x, y, labelWidth, flow.pt(8.5), 'normal', 'left', MUTED_GRAY)
      flow.lines(row.valueLines, x + labelWidth + 2, y, valueWidth, flow.pt(9.5), 'normal')
      y += row.height
    }
  }

  private fields(block: FieldsBlock): void {
    const flow = this.flow
    if (block.rows.length === 0) return
    if (block.columns === 2 && block.rows.length > 1 && flow.contentWidth > 120) {
      const gutter = 8
      const columnWidth = (flow.contentWidth - gutter) / 2
      const labelWidth = Math.min(columnWidth * 0.42, 34)
      const valueWidth = columnWidth - labelWidth - 2
      const half = Math.ceil(block.rows.length / 2)
      const leftRows = this.measureFields(block.rows.slice(0, half), labelWidth, valueWidth)
      const rightRows = this.measureFields(block.rows.slice(half), labelWidth, valueWidth)
      const sum = (rows: readonly MeasuredField[]): number =>
        rows.reduce((total, row) => total + row.height, 0)
      const height = Math.max(sum(leftRows), sum(rightRows))
      if (height <= flow.capacity()) {
        flow.ensure(height)
        this.drawFields(leftRows, flow.left, flow.y, labelWidth, valueWidth)
        this.drawFields(rightRows, flow.left + columnWidth + gutter, flow.y, labelWidth, valueWidth)
        flow.y += height + BLOCK_GAP
        return
      }
    }
    const labelWidth = Math.min(flow.contentWidth * 0.36, 48)
    const valueWidth = flow.contentWidth - labelWidth - 2
    for (const row of this.measureFields(block.rows, labelWidth, valueWidth)) {
      flow.ensure(row.height)
      this.drawFields([row], flow.left, flow.y, labelWidth, valueWidth)
      flow.y += row.height
    }
    flow.y += BLOCK_GAP
  }

  // --- Totaux ------------------------------------------------------------------------------------

  private measureTotals(block: TotalsBlock): MeasuredTotals {
    const flow = this.flow
    const normalPt = flow.pt(9.5)
    const strongPt = flow.pt(10.5)
    const width = flow.continuous ? flow.contentWidth : Math.min(flow.contentWidth, 100)
    let valueWidth = 0
    for (const row of block.rows) {
      const used = flow.width(row.value, row.emphasis ? strongPt : normalPt, row.emphasis ? 'bold' : 'normal')
      valueWidth = Math.max(valueWidth, used)
    }
    valueWidth += 0.5
    const labelWidth = Math.max(width - valueWidth - 3, 18)
    const rows = block.rows.map((row) => {
      const sizePt = row.emphasis ? strongPt : normalPt
      const labelLines = flow.wrap(row.label, labelWidth, sizePt, row.emphasis ? 'bold' : 'normal')
      const height = labelLines.length * lineHeightMm(sizePt) + (row.emphasis ? 2.4 : 1.2)
      return { labelLines, value: row.value, emphasis: row.emphasis, sizePt, height }
    })
    const height = rows.reduce((total, row) => total + row.height, 0) + 0.5
    return { width, labelWidth, valueWidth, height, rows }
  }

  private totals(block: TotalsBlock): void {
    const flow = this.flow
    const measured = this.measureTotals(block)
    flow.ensure(measured.height)
    const x = flow.right - measured.width
    let y = flow.y
    for (const row of measured.rows) {
      const style: FontStyle = row.emphasis ? 'bold' : 'normal'
      if (row.emphasis) flow.line(x, y, flow.right, y, 0.35, TEXT_GRAY)
      const top = y + (row.emphasis ? 1.2 : 0.6)
      flow.lines(row.labelLines, x, top, measured.labelWidth, row.sizePt, style)
      flow.lines(
        [row.value],
        x + measured.labelWidth + 3,
        top,
        measured.valueWidth,
        row.sizePt,
        style,
        'right',
      )
      y += row.height
    }
    flow.y = y + 0.5 + BLOCK_GAP
  }

  // --- Encadrés et signatures --------------------------------------------------------------------

  private notice(block: NoticeBlock): void {
    const flow = this.flow
    const padding = 2.2
    const titlePt = flow.pt(9.5)
    const textPt = flow.pt(9)
    const inner = flow.contentWidth - 2 * padding
    const titleLines = block.title === null ? [] : flow.wrap(block.title, inner, titlePt, 'bold')
    const textStyle: FontStyle = block.tone === 'test' ? 'bold' : 'normal'
    const textLines = flow.wrap(block.text, inner, textPt, textStyle)
    const titleHeight = titleLines.length * lineHeightMm(titlePt)
    const height = 2 * padding + titleHeight + textLines.length * lineHeightMm(textPt)
    flow.ensure(height + 1)
    const top = flow.y
    // Le ton se lit aussi sans couleur : trait épais (test), pointillé (avertissement), fond gris.
    if (block.tone === 'test') {
      flow.rect(flow.left, top, flow.contentWidth, height, { strokeGray: 0, lineWidthMm: 0.7 })
    } else if (block.tone === 'warning') {
      flow.rect(flow.left, top, flow.contentWidth, height, {
        strokeGray: 40,
        lineWidthMm: 0.45,
        dashMm: 1.6,
      })
    } else {
      flow.rect(flow.left, top, flow.contentWidth, height, {
        fillGray: NOTICE_FILL,
        strokeGray: GRID_GRAY,
        lineWidthMm: 0.25,
      })
    }
    flow.lines(titleLines, flow.left + padding, top + padding, inner, titlePt, 'bold')
    flow.lines(textLines, flow.left + padding, top + padding + titleHeight, inner, textPt, textStyle)
    flow.y += height + BLOCK_GAP
  }

  private signature(block: SignatureBlock): void {
    const flow = this.flow
    const count = block.boxes.length
    if (count === 0) return
    const gap = 6
    const boxWidth = (flow.contentWidth - gap * (count - 1)) / count
    const labelPt = flow.pt(8.5)
    const labels = block.boxes.map((box) => flow.wrap(box.label, boxWidth, labelPt, 'normal'))
    const labelHeight = Math.max(...labels.map((lines) => lines.length)) * lineHeightMm(labelPt)
    const boxHeight = Math.max(...block.boxes.map((box) => box.heightMm))
    const total = labelHeight + 1 + boxHeight
    flow.ensure(total + 3)
    if (!flow.atTop()) flow.y += 3
    const top = flow.y
    block.boxes.forEach((box, index) => {
      const x = flow.left + index * (boxWidth + gap)
      flow.lines(labels[index] ?? [], x, top, boxWidth, labelPt, 'normal', 'left', MUTED_GRAY)
      flow.rect(x, top + labelHeight + 1, boxWidth, box.heightMm, {
        strokeGray: 100,
        lineWidthMm: 0.25,
      })
    })
    flow.y = top + total + BLOCK_GAP
  }

  // --- Groupe solidaire --------------------------------------------------------------------------

  private group(block: GroupBlock): void {
    const flow = this.flow
    if (!block.keepTogether) {
      this.placeAll(block.blocks)
      return
    }
    const start = flow.snapshot()
    const startedAtTop = flow.atTop()
    this.placeAll(block.blocks)
    if (flow.pages.length > start.pageCount && !startedAtTop) {
      // Le groupe a débordé : on le reprend au début de la page suivante.
      flow.restore(start)
      flow.newPage()
      this.placeAll(block.blocks)
      if (flow.pages.length > start.pageCount + 1) {
        flow.warnings.push('Un groupe de blocs solidaires est plus haut qu’une page.')
      }
    }
  }

  // --- Tableaux ----------------------------------------------------------------------------------

  private table(block: TableBlock, next: Block | null): void {
    const flow = this.flow
    const sizePt = block.sizePt ?? flow.pt(9)
    const headSize = Math.max(sizePt - 0.5, 6.5)
    const lineHeight = lineHeightMm(sizePt)
    const widths = resolveColumnWidths(block.columns, flow.contentWidth)
    const columns = block.columns.map((column, index) => {
      let x = flow.left
      for (let before = 0; before < index; before += 1) x += widths[before] ?? 0
      return { column, width: widths[index] ?? 0, x }
    })

    const headerLines = columns.map(({ column, width }) =>
      flow.wrap(column.header, width - 2 * CELL_PAD_X, headSize, 'bold'),
    )
    const headerHeight =
      Math.max(1, ...headerLines.map((lines) => lines.length)) * lineHeightMm(headSize) +
      2 * CELL_PAD_Y

    const rows: MeasuredRow[] = block.rows.map((row) => {
      const cellLines = columns.map(({ width }, index) =>
        flow.wrap(
          row.cells[index] ?? '',
          width - 2 * CELL_PAD_X,
          sizePt,
          row.style === 'strong' ? 'bold' : 'normal',
        ),
      )
      const count = Math.max(1, ...cellLines.map((lines) => lines.length))
      return { cellLines, count, height: count * lineHeight + 2 * CELL_PAD_Y, style: row.style }
    })

    let runTop: number | null = null

    const drawHeader = (): void => {
      const top = flow.y
      flow.rect(flow.left, top, flow.contentWidth, headerHeight, { fillGray: HEADER_FILL })
      flow.line(flow.left, top, flow.right, top, 0.4, TEXT_GRAY)
      columns.forEach(({ column, width, x }, index) => {
        flow.lines(
          headerLines[index] ?? [],
          x + CELL_PAD_X,
          top + CELL_PAD_Y,
          width - 2 * CELL_PAD_X,
          headSize,
          'bold',
          column.align,
        )
      })
      flow.line(flow.left, top + headerHeight, flow.right, top + headerHeight, 0.4, TEXT_GRAY)
      runTop = top
      flow.y = top + headerHeight
    }

    /** Filets verticaux d'une portion de tableau (une page), tracés une fois la portion terminée. */
    const flushRun = (): void => {
      if (runTop === null) return
      let x = flow.left
      for (let index = 0; index <= columns.length; index += 1) {
        flow.line(x, runTop, x, flow.y, 0.15, GRID_GRAY)
        x += columns[index]?.width ?? 0
      }
      runTop = null
    }

    const drawRow = (row: MeasuredRow, from: number, to: number): void => {
      const top = flow.y
      const height = (to - from) * lineHeight + 2 * CELL_PAD_Y
      const style: FontStyle = row.style === 'strong' ? 'bold' : 'normal'
      const gray = row.style === 'muted' ? MUTED_GRAY : TEXT_GRAY
      columns.forEach(({ column, width, x }, index) => {
        flow.lines(
          (row.cellLines[index] ?? []).slice(from, to),
          x + CELL_PAD_X,
          top + CELL_PAD_Y,
          width - 2 * CELL_PAD_X,
          sizePt,
          style,
          column.align,
          gray,
        )
      })
      flow.line(flow.left, top + height, flow.right, top + height, 0.15, GRID_GRAY)
      flow.y = top + height
    }

    if (rows.length === 0) {
      flow.ensure(headerHeight + 2)
      drawHeader()
      flushRun()
      flow.warnings.push('Un tableau du document ne contient aucune ligne.')
      flow.y += BLOCK_GAP
      return
    }

    // Dernières lignes solidaires du bloc de totaux qui suit.
    const keep = Math.min(Math.max(block.keepLastRows, 0), rows.length)
    const trailing =
      keep > 0 && next !== null && next.kind === 'totals'
        ? this.measureTotals(next).height + BLOCK_GAP + 1
        : 0
    const chunks: Chunk[] = rows
      .slice(0, rows.length - keep)
      .map((row) => ({ rows: [row], extra: 0 }))
    if (keep > 0) chunks.push({ rows: rows.slice(rows.length - keep), extra: trailing })

    let needHeader = true

    /** Ligne plus haute qu'une page entière : découpée par lignes de texte, rien n'est perdu. */
    const drawSplitRow = (row: MeasuredRow): void => {
      let from = 0
      while (from < row.count) {
        if (needHeader) {
          if (!flow.atTop() && flow.y + headerHeight + lineHeight + 2 * CELL_PAD_Y > flow.bottom) {
            flushRun()
            flow.newPage()
          }
          drawHeader()
          needHeader = false
        }
        let fit = Math.floor((flow.bottom - flow.y - 2 * CELL_PAD_Y) / lineHeight)
        if (fit < 1) {
          if (!flow.atTop()) {
            flushRun()
            flow.newPage()
            needHeader = true
            continue
          }
          fit = 1
        }
        const take = Math.min(fit, row.count - from)
        drawRow(row, from, from + take)
        from += take
        if (from < row.count) {
          flushRun()
          flow.newPage()
          needHeader = true
        }
      }
    }

    for (const chunk of chunks) {
      const chunkHeight = chunk.rows.reduce((total, row) => total + row.height, 0) + chunk.extra
      const headNeed = needHeader ? headerHeight : 0
      if (flow.y + headNeed + chunkHeight > flow.bottom + 0.01) {
        if (chunkHeight + headerHeight > flow.capacity() + 0.01) {
          // Plus haut qu'une page : ligne par ligne, découpe de ligne en dernier recours.
          for (const row of chunk.rows) {
            if (row.height + headerHeight > flow.capacity() + 0.01) {
              drawSplitRow(row)
            } else {
              if (flow.y + (needHeader ? headerHeight : 0) + row.height > flow.bottom + 0.01) {
                if (!flow.atTop()) {
                  flushRun()
                  flow.newPage()
                }
                needHeader = true
              }
              if (needHeader) {
                drawHeader()
                needHeader = false
              }
              drawRow(row, 0, row.count)
            }
          }
          continue
        }
        if (!flow.atTop()) {
          flushRun()
          flow.newPage()
        }
        needHeader = true
      }
      if (needHeader) {
        drawHeader()
        needHeader = false
      }
      for (const row of chunk.rows) drawRow(row, 0, row.count)
    }
    flushRun()
    flow.y += BLOCK_GAP
  }
}
