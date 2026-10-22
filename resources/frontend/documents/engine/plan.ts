import type { DocumentLogo, FontStyle } from './model'
import type { PageFormat } from './units'

/**
 * Plan de dessin : le résultat de la mise en page, indépendant de jsPDF. Chaque page est une liste
 * d'opérations aux coordonnées finales (mm, origine en haut à gauche). Le MÊME plan alimente le rendu
 * PDF et l'aperçu à l'écran (SVG) : ce que l'on voit à l'écran est ce qui est imprimé. Les textes
 * sont déjà au jeu de caractères des PDF (`toPdfText`).
 *
 * Les niveaux de gris vont de 0 (noir) à 255 (blanc) : impression noir et blanc, aucune couleur.
 */
export interface TextOp {
  op: 'text'
  /** Coordonnée gauche du texte (jamais centrée ni alignée à droite par la bibliothèque PDF). */
  x: number
  /** Ligne de base. */
  y: number
  text: string
  sizePt: number
  style: FontStyle
  gray: number
  /** Rotation en degrés (sens trigonométrique), 0 pour le texte horizontal. */
  angle: number
}

export interface LineOp {
  op: 'line'
  x1: number
  y1: number
  x2: number
  y2: number
  widthMm: number
  gray: number
  /** Longueur du trait et de l'espace des pointillés, ou null pour un trait plein. */
  dashMm: number | null
}

export interface RectOp {
  op: 'rect'
  x: number
  y: number
  w: number
  h: number
  fillGray: number | null
  strokeGray: number | null
  lineWidthMm: number
  dashMm: number | null
}

export interface ImageOp {
  op: 'image'
  x: number
  y: number
  w: number
  h: number
  logo: DocumentLogo
}

export type DrawOp = TextOp | LineOp | RectOp | ImageOp

export interface PlanPage {
  ops: DrawOp[]
}

export interface ResolvedFormat extends PageFormat {
  /** Hauteur effective de la page (celle du contenu pour une page continue). */
  pageHeightMm: number
}

export interface LayoutPlan {
  format: ResolvedFormat
  pages: PlanPage[]
  /** Anomalies de mise en page ou de texte (caractères remplacés, bloc plus haut qu'une page…). */
  warnings: string[]
}
