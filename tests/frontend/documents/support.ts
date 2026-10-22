// Aides communes des tests du moteur de documents (D01, D02). Données FICTIVES uniquement.
import type { RenderedDocument } from '@/app/services/documents'
import { layoutDocument } from '@/documents/engine/layout'
import { fixedWidthMeasurer, type TextMeasurer } from '@/documents/engine/measure'
import type { DocumentModel } from '@/documents/engine/model'
import type { DrawOp, LayoutPlan, TextOp } from '@/documents/engine/plan'
import { planText } from '@/documents/engine/preview'

/** Mesure déterministe : un caractère = la moitié du corps. Les tests de mise en page n'ont pas besoin de jsPDF. */
export const MEASURER: TextMeasurer = fixedWidthMeasurer(0.5)

export function layout(model: DocumentModel, measurer: TextMeasurer = MEASURER): LayoutPlan {
  return layoutDocument(model, measurer)
}

/** Tout le texte imprimé d'un plan (une ligne par ligne de page, pages séparées). */
export function printedText(plan: LayoutPlan): string {
  return planText(plan)
}

/** Texte de toutes les opérations, y compris le filigrane. */
export function allTexts(plan: LayoutPlan): string[] {
  return plan.pages.flatMap((page) =>
    page.ops.filter((op): op is TextOp => op.op === 'text').map((op) => op.text),
  )
}

/** Mots qu'un document ne doit JAMAIS contenir sans pièce réelle (fichier 11 et consigne du projet). */
export const FORBIDDEN_WORDS: readonly RegExp[] = [
  /\bpay[éè]e?s?\b/i,
  /\bsold[éè]e?s?\b/i,
  /\bacquitt[éè]e?s?\b/i,
  /\bgratuit(e|es|s)?\b/i,
  /\br[éè]gl[éè]e?s?\b/i,
]

export function opRight(op: DrawOp, measurer: TextMeasurer = MEASURER): number {
  switch (op.op) {
    case 'text':
      return op.x + measurer.width(op.text, op.sizePt, op.style)
    case 'line':
      return Math.max(op.x1, op.x2)
    case 'rect':
    case 'image':
      return op.x + op.w
  }
}

/** Tout reste dans la page : marges latérales respectées, rien sous le bas de page. */
export function outOfBounds(plan: LayoutPlan, measurer: TextMeasurer = MEASURER): string[] {
  const { widthMm, pageHeightMm, marginLeftMm, marginRightMm } = plan.format
  const problems: string[] = []
  plan.pages.forEach((page, index) => {
    for (const op of page.ops) {
      if (op.op === 'text' && op.angle !== 0) continue
      const right = opRight(op, measurer)
      const left = op.op === 'line' ? Math.min(op.x1, op.x2) : op.x
      if (left < marginLeftMm - 0.05 || right > widthMm - marginRightMm + 0.05) {
        problems.push(`page ${index + 1} : ${op.op} hors des marges latérales (${left}–${right})`)
      }
      const bottom =
        op.op === 'text' ? op.y : op.op === 'line' ? Math.max(op.y1, op.y2) : op.y + op.h
      if (bottom > pageHeightMm + 0.05) {
        problems.push(`page ${index + 1} : ${op.op} sous le bas de page (${bottom})`)
      }
    }
  })
  return problems
}

/**
 * Document « généré » sans jsPDF pour les tests d'écran : vrai modèle, vrai plan de mise en page
 * (mesure déterministe), octets factices. Les tests du rendu réel sont dans `pdf.test.ts`.
 */
export function fakeRendered(
  model: DocumentModel,
  overrides: Partial<RenderedDocument> = {},
): RenderedDocument {
  const plan = layout(model)
  return {
    model,
    bytes: new Uint8Array([0x25, 0x50, 0x44, 0x46, 0x2d]),
    sha256: 'a'.repeat(64),
    pageCount: plan.pages.length,
    fileName: `${model.kind.toLowerCase()}.pdf`,
    warnings: plan.warnings,
    plan,
    ...overrides,
  }
}
