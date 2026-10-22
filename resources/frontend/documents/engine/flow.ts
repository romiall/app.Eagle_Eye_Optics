import { toPdfText } from './charset'
import type { TextMeasurer } from './measure'
import type { Align, FontStyle } from './model'
import type { DrawOp, PlanPage } from './plan'
import { baselineOffsetMm, lineHeightMm, type PageFormat } from './units'
import { wrapText } from './wrap'

/** Hauteur disponible d'une page continue (ticket) : jamais atteinte, la page prend la hauteur du contenu. */
export const CONTINUOUS_LIMIT = 100_000

const TOP_EPSILON = 0.01

/** Coordonnées arrondies au micron : un plan stable, comparable d'une exécution à l'autre. */
export function r3(value: number): number {
  return Math.round(value * 1000) / 1000
}

export interface FlowSnapshot {
  pageCount: number
  opCount: number
  y: number
}

/**
 * Curseur de mise en page : pages, position verticale et primitives de dessin. Il ne connaît aucun
 * type de bloc ; `blocks.ts` y pose les blocs, `chrome.ts` y dessine en-têtes et pieds de page. Tout
 * texte passe par `toPdfText` : la mesure et le dessin voient exactement la même chaîne, et les
 * caractères remplacés sont retenus pour être signalés.
 */
export class PageFlow {
  readonly pages: PlanPage[] = []
  readonly format: PageFormat
  readonly measurer: TextMeasurer
  readonly left: number
  readonly right: number
  readonly contentWidth: number
  /** Rapport du corps de texte du format au corps de référence de l'A4 (9,5 pt). */
  readonly scale: number
  readonly lossy = new Set<string>()
  readonly warnings: string[] = []
  y: number
  bottom: number
  /** Dessine l'en-tête des pages suivantes et rend l'ordonnée où le contenu reprend. */
  onNewPage: ((flow: PageFlow) => number) | null = null
  private readonly tops: number[]
  private currentIndex = 0

  constructor(format: PageFormat, measurer: TextMeasurer) {
    this.format = format
    this.measurer = measurer
    this.left = format.marginLeftMm
    this.right = format.widthMm - format.marginRightMm
    this.contentWidth = this.right - this.left
    this.scale = format.baseFontPt / 9.5
    this.pages.push({ ops: [] })
    this.y = format.marginTopMm
    this.tops = [format.marginTopMm]
    this.bottom =
      format.heightMm === null ? CONTINUOUS_LIMIT : format.heightMm - format.marginBottomMm
  }

  get page(): PlanPage {
    const page = this.pages[this.currentIndex]
    if (page === undefined) throw new Error('Aucune page en cours.')
    return page
  }

  /** Dessine sur une page déjà posée (pieds de page, filigrane) puis revient à la page en cours. */
  onPage(index: number, draw: () => void): void {
    const saved = this.currentIndex
    this.currentIndex = index
    try {
      draw()
    } finally {
      this.currentIndex = saved
    }
  }

  get continuous(): boolean {
    return this.format.heightMm === null
  }

  /** Taille de police proportionnelle au format (A4 = 1). */
  pt(sizeA4: number): number {
    return Math.round(sizeA4 * this.scale * 10) / 10
  }

  /** Définit l'ordonnée où le contenu commence sur la page en cours (sous son en-tête). */
  setTop(y: number): void {
    this.tops[this.tops.length - 1] = y
    this.y = y
  }

  topOfPage(): number {
    return this.tops[this.tops.length - 1] ?? this.format.marginTopMm
  }

  atTop(): boolean {
    return this.y <= this.topOfPage() + TOP_EPSILON
  }

  remaining(): number {
    return this.bottom - this.y
  }

  /** Hauteur d'une page entière disponible au contenu. */
  capacity(): number {
    return this.bottom - this.topOfPage()
  }

  newPage(): void {
    this.pages.push({ ops: [] })
    this.currentIndex = this.pages.length - 1
    this.tops.push(this.format.marginTopMm)
    const top = this.onNewPage === null ? this.format.marginTopMm : this.onNewPage(this)
    this.tops[this.tops.length - 1] = top
    this.y = top
  }

  /** Passe à une nouvelle page si `height` ne tient pas dans ce qui reste (sauf en haut de page). */
  ensure(height: number): void {
    if (!this.atTop() && height > this.remaining() + TOP_EPSILON) this.newPage()
  }

  snapshot(): FlowSnapshot {
    return { pageCount: this.pages.length, opCount: this.page.ops.length, y: this.y }
  }

  restore(snapshot: FlowSnapshot): void {
    this.pages.length = snapshot.pageCount
    this.tops.length = snapshot.pageCount
    this.currentIndex = snapshot.pageCount - 1
    this.page.ops.length = snapshot.opCount
    this.y = snapshot.y
  }

  /** Texte imprimable (jeu WinAnsi) ; les remplacements avec perte sont retenus. */
  safe(raw: string): string {
    const converted = toPdfText(raw)
    for (const character of converted.lossy) this.lossy.add(character)
    return converted.text
  }

  width(raw: string, sizePt: number, style: FontStyle): number {
    return this.measurer.width(this.safe(raw), sizePt, style)
  }

  wrap(raw: string, maxWidthMm: number, sizePt: number, style: FontStyle): string[] {
    return wrapText(this.safe(raw), maxWidthMm, sizePt, style, this.measurer)
  }

  push(op: DrawOp): void {
    this.page.ops.push(op)
  }

  text(
    x: number,
    baselineY: number,
    raw: string,
    sizePt: number,
    style: FontStyle,
    gray = 0,
    angle = 0,
  ): void {
    const text = this.safe(raw)
    if (text === '') return
    this.push({ op: 'text', x: r3(x), y: r3(baselineY), text, sizePt, style, gray, angle })
  }

  /**
   * Écrit des lignes déjà découpées à partir de `top`, alignées dans la boîte `[x, x + boxWidth]`, et
   * rend la hauteur occupée. L'alignement est calculé ici avec la même mesure que la découpe.
   */
  lines(
    lines: readonly string[],
    x: number,
    top: number,
    boxWidth: number,
    sizePt: number,
    style: FontStyle,
    align: Align = 'left',
    gray = 0,
  ): number {
    const lineHeight = lineHeightMm(sizePt)
    const baseline = baselineOffsetMm(sizePt)
    lines.forEach((line, index) => {
      if (line === '') return
      const free = boxWidth - this.width(line, sizePt, style)
      const shift = align === 'right' ? free : align === 'center' ? free / 2 : 0
      const baselineY = top + index * lineHeight + baseline
      this.text(x + Math.max(shift, 0), baselineY, line, sizePt, style, gray)
    })
    return lines.length * lineHeight
  }

  line(
    x1: number,
    y1: number,
    x2: number,
    y2: number,
    widthMm: number,
    gray = 0,
    dashMm: number | null = null,
  ): void {
    this.push({
      op: 'line',
      x1: r3(x1),
      y1: r3(y1),
      x2: r3(x2),
      y2: r3(y2),
      widthMm,
      gray,
      dashMm,
    })
  }

  rect(
    x: number,
    y: number,
    w: number,
    h: number,
    options: {
      fillGray?: number
      strokeGray?: number
      lineWidthMm?: number
      dashMm?: number
    } = {},
  ): void {
    this.push({
      op: 'rect',
      x: r3(x),
      y: r3(y),
      w: r3(w),
      h: r3(h),
      fillGray: options.fillGray ?? null,
      strokeGray: options.strokeGray ?? null,
      lineWidthMm: options.lineWidthMm ?? 0.2,
      dashMm: options.dashMm ?? null,
    })
  }
}
