import type { LayoutPlan, PlanPage, TextOp } from './plan'
import { fontSizeMm } from './units'

/**
 * Aperçu d'un plan de mise en page (D01, D02) : le MÊME plan que le PDF, donc ce que l'on voit est ce
 * qui s'imprime (aux polices près : l'aperçu dessine avec la police du système, le PDF avec Helvetica).
 *  - `planPageLines` : le texte de la page, ligne par ligne, pour les lecteurs d'écran et les tests ;
 *  - `pageToSvg` : la page en SVG autonome, pour les fichiers de contrôle visuel (« golden ») ;
 *  - `grayCss` : niveau de gris du plan en couleur CSS, pour l'aperçu à l'écran.
 */
export function grayCss(gray: number): string {
  const level = Math.max(0, Math.min(255, Math.round(gray)))
  const hex = level.toString(16).padStart(2, '0')
  return `#${hex}${hex}${hex}`
}

/** Écart maximal (mm) entre les lignes de base de deux textes d'une même ligne visuelle. */
const SAME_LINE_TOLERANCE_MM = 0.6

/**
 * Lignes de texte d'une page (texte horizontal seulement : le filigrane n'est pas du contenu). Les
 * textes dont les lignes de base diffèrent de moins de 0,6 mm (libellé et valeur de tailles
 * différentes, cellules d'un même rang) forment une ligne, de gauche à droite, séparés par « | ».
 */
export function planPageLines(page: PlanPage): string[] {
  const texts = page.ops
    .filter((op): op is TextOp => op.op === 'text' && op.angle === 0)
    .sort((left, right) => left.y - right.y || left.x - right.x)
  const lines: { y: number; fragments: { x: number; text: string }[] }[] = []
  for (const op of texts) {
    const last = lines[lines.length - 1]
    if (last !== undefined && op.y - last.y <= SAME_LINE_TOLERANCE_MM) {
      last.fragments.push({ x: op.x, text: op.text })
    } else {
      lines.push({ y: op.y, fragments: [{ x: op.x, text: op.text }] })
    }
  }
  return lines.map((line) =>
    line.fragments
      .sort((left, right) => left.x - right.x)
      .map((fragment) => fragment.text)
      .join(' | '),
  )
}

/** Tout le texte du document, page par page. */
export function planText(plan: LayoutPlan): string {
  return plan.pages.map((page) => planPageLines(page).join('\n')).join('\n\n')
}

function escapeXml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

/** Une page du plan en SVG autonome (millimètres), pour inspection visuelle. */
export function pageToSvg(plan: LayoutPlan, index: number): string {
  const { widthMm, pageHeightMm } = plan.format
  const page = plan.pages[index]
  if (page === undefined) throw new RangeError('Page inexistante.')
  const parts: string[] = [
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${widthMm} ${pageHeightMm}" width="${widthMm}mm" height="${pageHeightMm}mm" font-family="Helvetica, Arial, sans-serif">`,
    `<rect x="0" y="0" width="${widthMm}" height="${pageHeightMm}" fill="#ffffff"/>`,
  ]
  for (const op of page.ops) {
    if (op.op === 'text') {
      const rotate = op.angle === 0 ? '' : ` transform="rotate(${-op.angle} ${op.x} ${op.y})"`
      parts.push(
        `<text x="${op.x}" y="${op.y}" font-size="${fontSizeMm(op.sizePt).toFixed(3)}" font-weight="${op.style.startsWith('bold') ? 'bold' : 'normal'}" font-style="${op.style.endsWith('italic') ? 'italic' : 'normal'}" fill="${grayCss(op.gray)}"${rotate}>${escapeXml(op.text)}</text>`,
      )
    } else if (op.op === 'line') {
      const dash = op.dashMm === null ? '' : ` stroke-dasharray="${op.dashMm} ${op.dashMm}"`
      parts.push(
        `<line x1="${op.x1}" y1="${op.y1}" x2="${op.x2}" y2="${op.y2}" stroke="${grayCss(op.gray)}" stroke-width="${op.widthMm}"${dash}/>`,
      )
    } else if (op.op === 'rect') {
      const fill = op.fillGray === null ? 'none' : grayCss(op.fillGray)
      const stroke = op.strokeGray === null ? 'none' : grayCss(op.strokeGray)
      const dash = op.dashMm === null ? '' : ` stroke-dasharray="${op.dashMm} ${op.dashMm}"`
      parts.push(
        `<rect x="${op.x}" y="${op.y}" width="${op.w}" height="${op.h}" fill="${fill}" stroke="${stroke}" stroke-width="${op.lineWidthMm}"${dash}/>`,
      )
    } else {
      parts.push(
        `<image x="${op.x}" y="${op.y}" width="${op.w}" height="${op.h}" href="${escapeXml(op.logo.dataUrl)}"/>`,
      )
    }
  }
  parts.push('</svg>')
  return parts.join('\n')
}

// --- Formes prêtes à afficher (aperçu à l'écran) ---------------------------------------------------

export type PreviewShape =
  | {
      kind: 'text'
      key: number
      x: number
      y: number
      text: string
      fontSize: number
      bold: boolean
      italic: boolean
      fill: string
      /** Valeur de l'attribut `transform`, ou null pour un texte horizontal. */
      transform: string | null
    }
  | {
      kind: 'line'
      key: number
      x1: number
      y1: number
      x2: number
      y2: number
      stroke: string
      width: number
      dash: string | null
    }
  | {
      kind: 'rect'
      key: number
      x: number
      y: number
      w: number
      h: number
      fill: string
      stroke: string
      width: number
      dash: string | null
    }
  | { kind: 'image'; key: number; x: number; y: number; w: number; h: number; href: string }

export interface PreviewPage {
  number: number
  widthMm: number
  heightMm: number
  shapes: PreviewShape[]
  /** Le texte de la page, ligne par ligne (alternative textuelle de l'aperçu). */
  lines: string[]
}

/** Pages du plan sous forme de formes SVG prêtes à afficher, sans HTML ni style inline. */
export function previewPages(plan: LayoutPlan): PreviewPage[] {
  const { widthMm, pageHeightMm } = plan.format
  return plan.pages.map((page, index) => ({
    number: index + 1,
    widthMm,
    heightMm: pageHeightMm,
    lines: planPageLines(page),
    shapes: page.ops.map((op, position): PreviewShape => {
      if (op.op === 'text') {
        return {
          kind: 'text',
          key: position,
          x: op.x,
          y: op.y,
          text: op.text,
          fontSize: fontSizeMm(op.sizePt),
          bold: op.style.startsWith('bold'),
          italic: op.style.endsWith('italic'),
          fill: grayCss(op.gray),
          transform: op.angle === 0 ? null : `rotate(${-op.angle} ${op.x} ${op.y})`,
        }
      }
      if (op.op === 'line') {
        return {
          kind: 'line',
          key: position,
          x1: op.x1,
          y1: op.y1,
          x2: op.x2,
          y2: op.y2,
          stroke: grayCss(op.gray),
          width: op.widthMm,
          dash: op.dashMm === null ? null : `${op.dashMm} ${op.dashMm}`,
        }
      }
      if (op.op === 'rect') {
        return {
          kind: 'rect',
          key: position,
          x: op.x,
          y: op.y,
          w: op.w,
          h: op.h,
          fill: op.fillGray === null ? 'none' : grayCss(op.fillGray),
          stroke: op.strokeGray === null ? 'none' : grayCss(op.strokeGray),
          width: op.lineWidthMm,
          dash: op.dashMm === null ? null : `${op.dashMm} ${op.dashMm}`,
        }
      }
      return {
        kind: 'image',
        key: position,
        x: op.x,
        y: op.y,
        w: op.w,
        h: op.h,
        href: op.logo.dataUrl,
      }
    }),
  }))
}
