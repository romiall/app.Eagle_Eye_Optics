import type { FontStyle } from './model'
import { MM_PER_POINT } from './units'

/**
 * Mesure de texte : la largeur en mm d'une chaîne dans une police et une taille données. La mise en
 * page n'a besoin que de cette fonction ; en production elle est fournie par les métriques de la
 * police de jsPDF (`pdf.ts`), dans les tests par une mesure déterministe.
 *
 * La mesure ignore le crénage : le PDF est dessiné sans crénage (opérateur « Tj »), la largeur
 * mesurée est donc la largeur réelle et l'alignement à droite tombe juste.
 */
export interface TextMeasurer {
  width(text: string, sizePt: number, style: FontStyle): number
}

/** Mesure à largeur fixe par caractère (fraction du corps) : déterministe, pour les tests. */
export function fixedWidthMeasurer(emFraction = 0.5): TextMeasurer {
  return {
    width: (text, sizePt) => [...text].length * sizePt * MM_PER_POINT * emFraction,
  }
}
