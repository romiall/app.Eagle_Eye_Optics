/**
 * Largeurs des glyphes de Helvetica et Helvetica-Bold (métriques AFM d'Adobe, en millièmes de corps)
 * pour les caractères que la table de largeurs de jsPDF donne mal :
 *
 *  - le texte ASCII (U+0020 à U+007E), pour lequel jsPDF arrondit au centième de corps (biais de
 *    l'ordre de -0,8 % sur un texte courant) ;
 *  - quelques caractères du jeu WinAnsi que jsPDF mesure avec sa largeur PAR DÉFAUT alors que le PDF
 *    les dessine avec leur vraie largeur : l'espace insécable (U+00A0, dessinée comme une espace),
 *    « œ », « Œ », « × », les exposants, les fractions, « € »…
 *
 * Les autres caractères (lettres accentuées, guillemets, tirets, apostrophe typographique) gardent la
 * mesure de jsPDF. Le dessin n'applique aucun crénage : la somme des largeurs est la largeur réelle.
 * `tests/frontend/documents/pdf.test.ts` compare ces tables à celles de jsPDF (écart d'un centième au
 * plus pour l'ASCII).
 */
function segments(...parts: (readonly number[])[]): readonly number[] {
  return parts.flat()
}

const DIGITS: readonly number[] = Array.from({ length: 10 }, () => 556)

/** U+0020 à U+007E, Helvetica. */
const REGULAR_ASCII: readonly number[] = segments(
  [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278],
  DIGITS,
  [278, 278, 584, 584, 584, 556, 1015],
  [
    667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667,
    611, 722, 667, 944, 667, 667, 611,
  ],
  [278, 278, 278, 469, 556, 333],
  [
    556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500,
    278, 556, 500, 722, 500, 500, 500,
  ],
  [334, 260, 334, 584],
)

/** U+0020 à U+007E, Helvetica-Bold. */
const BOLD_ASCII: readonly number[] = segments(
  [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278],
  DIGITS,
  [333, 333, 584, 584, 584, 611, 975],
  [
    722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667,
    611, 722, 667, 944, 667, 667, 611,
  ],
  [333, 278, 333, 584, 556, 333],
  [
    556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556,
    333, 611, 556, 778, 556, 556, 500,
  ],
  [389, 280, 389, 584],
)

/** [Helvetica, Helvetica-Bold] des caractères que jsPDF mesure avec sa largeur par défaut. */
const EXTRA: Readonly<Record<number, readonly [number, number]>> = {
  0x00a0: [278, 278], // espace insécable : dessinée comme une espace
  0x00a6: [260, 280], // ¦
  0x00b2: [333, 333], // ²
  0x00b3: [333, 333], // ³
  0x00b6: [537, 556], // ¶
  0x00b9: [333, 333], // ¹
  0x00bc: [834, 834], // ¼
  0x00bd: [834, 834], // ½
  0x00be: [834, 834], // ¾
  0x00d0: [722, 722], // Ð
  0x00d7: [584, 584], // ×
  0x00dd: [667, 667], // Ý
  0x00de: [667, 667], // Þ
  0x00f0: [556, 611], // ð
  0x00fd: [500, 556], // ý
  0x00fe: [556, 611], // þ
  0x0152: [1000, 1000], // Œ
  0x0153: [944, 944], // œ
  0x0160: [667, 667], // Š
  0x0161: [500, 556], // š
  0x017d: [611, 611], // Ž
  0x017e: [500, 500], // ž
  0x20ac: [556, 556], // €
}

/** Largeur en millièmes de corps d'un caractère, ou null si la mesure de jsPDF fait foi. */
export function glyphUnits(codePoint: number, bold: boolean): number | null {
  if (codePoint >= 0x20 && codePoint <= 0x7e) {
    const table = bold ? BOLD_ASCII : REGULAR_ASCII
    return table[codePoint - 0x20] ?? null
  }
  const extra = EXTRA[codePoint]
  return extra === undefined ? null : extra[bold ? 1 : 0]
}

/** Caractères ASCII dont la largeur est exacte (utilisé par les tests de cohérence avec jsPDF). */
export const ASCII_FIRST = 0x20
export const ASCII_LAST = 0x7e
