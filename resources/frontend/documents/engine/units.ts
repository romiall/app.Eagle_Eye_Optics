/**
 * Unités et formats de page du moteur de documents (D01, fichier 11). Toutes les longueurs sont en
 * millimètres, l'origine est en haut à gauche de la page, `y` croît vers le bas. Les tailles de
 * police sont en points (1 pt = 25,4 / 72 mm).
 *
 * Marges A4 proposées par le fichier 11 : 12 à 15 mm. A4 est le format par défaut ; le ticket
 * thermique est une OPTION (« après confirmation de l’imprimante », fichier 11) dont la hauteur
 * suit le contenu (page continue, sans saut de page).
 */
export const MM_PER_POINT = 0.352778

/** Interligne : multiple de la taille de police (texte lisible, accents compris). */
export const LINE_FACTOR = 1.22

export type PageFormatId = 'A4' | 'A5' | 'TICKET_80'

export interface PageFormat {
  id: PageFormatId
  widthMm: number
  /** Hauteur fixe, ou null pour une page continue (la hauteur est celle du contenu). */
  heightMm: number | null
  marginTopMm: number
  marginRightMm: number
  marginBottomMm: number
  marginLeftMm: number
  /** Corps du texte courant en points. */
  baseFontPt: number
  /** Texte sur l'étiquette du format dans les écrans. */
  label: string
}

export const PAGE_FORMATS: Readonly<Record<PageFormatId, PageFormat>> = {
  A4: {
    id: 'A4',
    widthMm: 210,
    heightMm: 297,
    marginTopMm: 14,
    marginRightMm: 14,
    marginBottomMm: 14,
    marginLeftMm: 14,
    baseFontPt: 9.5,
    label: 'A4',
  },
  A5: {
    id: 'A5',
    widthMm: 148,
    heightMm: 210,
    marginTopMm: 10,
    marginRightMm: 10,
    marginBottomMm: 10,
    marginLeftMm: 10,
    baseFontPt: 9,
    label: 'A5',
  },
  TICKET_80: {
    id: 'TICKET_80',
    widthMm: 80,
    heightMm: null,
    marginTopMm: 4,
    marginRightMm: 4,
    marginBottomMm: 6,
    marginLeftMm: 4,
    baseFontPt: 8,
    label: 'Ticket 80 mm',
  },
}

export function isPageFormatId(value: string): value is PageFormatId {
  return Object.prototype.hasOwnProperty.call(PAGE_FORMATS, value)
}

/** Hauteur d'une ligne de texte de `sizePt` points, en mm. */
export function lineHeightMm(sizePt: number): number {
  return sizePt * MM_PER_POINT * LINE_FACTOR
}

/** Taille de police en mm. */
export function fontSizeMm(sizePt: number): number {
  return sizePt * MM_PER_POINT
}

/**
 * Décalage de la ligne de base depuis le haut d'une ligne de hauteur `lineHeightMm(sizePt)` : le
 * corps du texte (environ 0,96 fois sa taille) est centré dans la ligne, la ligne de base se trouve
 * aux trois quarts de la taille sous son sommet.
 */
export function baselineOffsetMm(sizePt: number): number {
  const size = fontSizeMm(sizePt)
  return (lineHeightMm(sizePt) - 0.96 * size) / 2 + 0.75 * size
}
