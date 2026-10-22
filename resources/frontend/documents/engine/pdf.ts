import { jsPDF } from 'jspdf'

import { toPdfText } from './charset'
import { fileIdentifier, stableStringify } from './hash'
import { layoutDocument } from './layout'
import type { TextMeasurer } from './measure'
import { glyphUnits } from './metrics'
import { DOCUMENT_KIND_LABELS, type DocumentModel, type FontStyle } from './model'
import type { DrawOp, LayoutPlan } from './plan'
import { MM_PER_POINT } from './units'

/**
 * Rendu PDF local (D01) : jsPDF dessine le plan de mise en page. Aucun serveur d'impression, aucune
 * requête réseau, aucune ressource distante : les polices sont les polices standard du format PDF
 * (Helvetica, encodage WinAnsi, voir `charset.ts`), le logo éventuel est une image locale en URL de
 * données.
 *
 * Reproductibilité : la date de création est l'instant du document (pas l'heure de la génération) et
 * l'identifiant de fichier est dérivé du contenu du modèle ; le même modèle donne donc les mêmes
 * octets. Une réimpression rend l'original conservé ; si l'original a disparu, la régénération depuis
 * le même snapshot et la même version de modèle retrouve la même empreinte, ou le signale.
 */
export interface RenderedPdf {
  bytes: Uint8Array
  pageCount: number
  warnings: string[]
  plan: LayoutPlan
}

const MEASURE_CACHE_LIMIT = 20_000

/**
 * Mesure fondée sur les métriques de Helvetica, sans crénage (le dessin n'en applique pas) : largeurs
 * exactes de l'ASCII et des caractères que jsPDF mesure mal (`metrics.ts`), mesure de jsPDF pour le
 * reste (lettres accentuées, guillemets, tirets).
 */
export function createJsPdfMeasurer(): TextMeasurer {
  const doc = new jsPDF({ unit: 'mm', format: [100, 100], compress: false })
  const cache = new Map<string, number>()
  return {
    width(text: string, sizePt: number, style: FontStyle): number {
      const key = `${style}\u{1F}${sizePt}\u{1F}${text}`
      const known = cache.get(key)
      if (known !== undefined) return known
      const bold = style === 'bold' || style === 'bolditalic'
      let units = 0
      let others = ''
      for (const character of text) {
        const exact = glyphUnits(character.codePointAt(0) ?? 0, bold)
        if (exact === null) others += character
        else units += exact
      }
      let em = units / 1000
      if (others !== '') {
        doc.setFont('helvetica', style)
        doc.setFontSize(sizePt)
        em += doc.getStringUnitWidth(others, { doKerning: false })
      }
      const width = em * sizePt * MM_PER_POINT
      if (cache.size >= MEASURE_CACHE_LIMIT) cache.clear()
      cache.set(key, width)
      return width
    },
  }
}

const FALLBACK_PDF_DATE = "D:20260101000000+00'00'"

/** Date PDF en UTC d'un instant ISO ; jsPDF n'accepte que 1970 à 2037. */
export function pdfDateOf(instant: string): string {
  const match = /^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})/.exec(instant)
  if (match === null) return FALLBACK_PDF_DATE
  const year = Number(match[1])
  if (year < 1970 || year > 2037) return FALLBACK_PDF_DATE
  return `D:${match[1]}${match[2]}${match[3]}${match[4]}${match[5]}${match[6]}+00'00'`
}

function printable(text: string): string {
  return toPdfText(text).text.replace(/\n/g, ' ')
}

function setGray(doc: jsPDF, kind: 'text' | 'draw' | 'fill', gray: number): void {
  if (kind === 'text') doc.setTextColor(gray, gray, gray)
  else if (kind === 'draw') doc.setDrawColor(gray, gray, gray)
  else doc.setFillColor(gray, gray, gray)
}

function setDash(doc: jsPDF, dashMm: number | null): void {
  doc.setLineDashPattern(dashMm === null ? [] : [dashMm, dashMm], 0)
}

function drawOp(doc: jsPDF, op: DrawOp): void {
  switch (op.op) {
    case 'text':
      doc.setFont('helvetica', op.style)
      doc.setFontSize(op.sizePt)
      setGray(doc, 'text', op.gray)
      if (op.angle === 0) doc.text(op.text, op.x, op.y)
      else doc.text(op.text, op.x, op.y, { angle: op.angle })
      return
    case 'line':
      setGray(doc, 'draw', op.gray)
      doc.setLineWidth(op.widthMm)
      setDash(doc, op.dashMm)
      doc.line(op.x1, op.y1, op.x2, op.y2)
      return
    case 'rect': {
      if (op.fillGray === null && op.strokeGray === null) return
      if (op.fillGray !== null) setGray(doc, 'fill', op.fillGray)
      if (op.strokeGray !== null) {
        setGray(doc, 'draw', op.strokeGray)
        doc.setLineWidth(op.lineWidthMm)
        setDash(doc, op.dashMm)
      }
      const style = op.fillGray !== null && op.strokeGray !== null ? 'FD' : op.fillGray !== null ? 'F' : 'S'
      doc.rect(op.x, op.y, op.w, op.h, style)
      return
    }
    case 'image':
      doc.addImage(op.logo.dataUrl, op.logo.format, op.x, op.y, op.w, op.h)
      return
  }
}

/** Dessine le plan dans un document jsPDF et rend les octets du PDF. */
export function renderPlanToPdf(plan: LayoutPlan, model: DocumentModel): Uint8Array {
  const { widthMm, pageHeightMm } = plan.format
  const doc = new jsPDF({
    unit: 'mm',
    format: [widthMm, pageHeightMm],
    orientation: 'portrait',
    compress: true,
    putOnlyUsedFonts: true,
    floatPrecision: 3,
  })
  doc.setProperties({
    title: printable(model.title),
    subject: printable(`${DOCUMENT_KIND_LABELS[model.kind]} - modele ${model.templateVersion}`),
    author: printable(model.letterhead.organization),
    keywords: model.kind,
    creator: "Eagle's Eye Optics Medical",
  })
  doc.setLanguage('fr-FR')
  doc.setCreationDate(pdfDateOf(model.meta.documentAt))
  doc.setFileId(fileIdentifier(stableStringify(model)))
  plan.pages.forEach((page, index) => {
    if (index > 0) doc.addPage([widthMm, pageHeightMm], 'portrait')
    for (const op of page.ops) drawOp(doc, op)
  })
  return new Uint8Array(doc.output('arraybuffer'))
}

/** Mise en page puis rendu : le plan est rendu avec le PDF pour l'aperçu et les contrôles. */
export function renderDocument(
  model: DocumentModel,
  measurer: TextMeasurer = createJsPdfMeasurer(),
): RenderedPdf {
  const plan = layoutDocument(model, measurer)
  const bytes = renderPlanToPdf(plan, model)
  return { bytes, pageCount: plan.pages.length, warnings: plan.warnings, plan }
}

/** `renderPdf(model) => Uint8Array` : les octets du PDF d'un modèle. */
export function renderPdf(model: DocumentModel): Uint8Array {
  return renderDocument(model).bytes
}
