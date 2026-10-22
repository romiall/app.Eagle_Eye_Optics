import { BlockPlacer } from './blocks'
import { drawContinuationHeader, drawFirstHeader, drawPageFurniture, prepareFooter } from './chrome'
import { PageFlow } from './flow'
import type { TextMeasurer } from './measure'
import type { DocumentModel } from './model'
import type { LayoutPlan } from './plan'
import { PAGE_FORMATS } from './units'

/**
 * Mise en page d'un modèle de document (D01) : pages, positions et dessins finaux, sans aucune
 * dépendance à jsPDF hors de la mesure du texte. Fonction pure : le même modèle et la même mesure
 * donnent toujours le même plan, donc le même PDF et le même aperçu.
 *
 * Ordre : pied de page mesuré d'abord (il réserve le bas de chaque page), en-tête de la première page,
 * blocs du corps, puis pieds de page, numéros « Page k/N » et filigrane sur les pages obtenues.
 */
export function layoutDocument(model: DocumentModel, measurer: TextMeasurer): LayoutPlan {
  const format = PAGE_FORMATS[model.format]
  const flow = new PageFlow(format, measurer)

  const footer = prepareFooter(flow, model)
  if (format.heightMm !== null) {
    flow.bottom = format.heightMm - format.marginBottomMm - footer.height - 2
  }

  flow.onNewPage = (current) => drawContinuationHeader(current, model)
  flow.setTop(drawFirstHeader(flow, model))

  new BlockPlacer(flow).placeAll(model.body)

  const contentEndY = flow.y
  const pageHeightMm =
    format.heightMm ?? Math.ceil(contentEndY + footer.height + format.marginBottomMm + 2)
  drawPageFurniture(flow, model, footer, pageHeightMm, contentEndY)

  const warnings = [...model.warnings, ...flow.warnings]
  if (flow.lossy.size > 0) {
    const listed = [...flow.lossy].map((character) => `« ${character} »`).join(', ')
    warnings.push(`Caractères non imprimables remplacés dans le PDF : ${listed}.`)
  }
  return { format: { ...format, pageHeightMm }, pages: flow.pages, warnings }
}
