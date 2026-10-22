import type { TextMeasurer } from './measure'
import type { FontStyle } from './model'

const EPSILON = 0.001

/** Coupe un mot plus long qu'une ligne en morceaux qui tiennent, sans tiret ajouté ni perte. */
function splitLongWord(
  word: string,
  maxWidthMm: number,
  sizePt: number,
  style: FontStyle,
  measurer: TextMeasurer,
): string[] {
  const pieces: string[] = []
  let current = ''
  for (const character of word) {
    const candidate = current + character
    if (current !== '' && measurer.width(candidate, sizePt, style) > maxWidthMm + EPSILON) {
      pieces.push(current)
      current = character
    } else {
      current = candidate
    }
  }
  if (current !== '') pieces.push(current)
  return pieces
}

/**
 * Découpe un texte en lignes de largeur au plus `maxWidthMm`. Les « \n » sont des retours à la ligne
 * forcés (une ligne vide est conservée) ; les coupures se font aux espaces ordinaires, JAMAIS à une
 * espace insécable (« 80 000 FCFA » reste d'un bloc) ; un mot plus long qu'une ligne est coupé par
 * caractères. Toujours au moins une ligne. Aucun texte n'est supprimé ni tronqué.
 */
export function wrapText(
  text: string,
  maxWidthMm: number,
  sizePt: number,
  style: FontStyle,
  measurer: TextMeasurer,
): string[] {
  const limit = Math.max(maxWidthMm, 1)
  const lines: string[] = []
  for (const paragraph of text.split('\n')) {
    let line = ''
    for (const word of paragraph.split(' ')) {
      if (word === '') continue
      const candidate = line === '' ? word : `${line} ${word}`
      if (measurer.width(candidate, sizePt, style) <= limit + EPSILON) {
        line = candidate
        continue
      }
      if (line !== '') {
        lines.push(line)
        line = ''
      }
      if (measurer.width(word, sizePt, style) <= limit + EPSILON) {
        line = word
        continue
      }
      const pieces = splitLongWord(word, limit, sizePt, style, measurer)
      for (const piece of pieces.slice(0, -1)) lines.push(piece)
      line = pieces[pieces.length - 1] ?? ''
    }
    lines.push(line)
  }
  return lines.length === 0 ? [''] : lines
}

/** Largeur de la plus large des lignes. */
export function maxLineWidth(
  lines: readonly string[],
  sizePt: number,
  style: FontStyle,
  measurer: TextMeasurer,
): number {
  let widest = 0
  for (const line of lines) widest = Math.max(widest, measurer.width(line, sizePt, style))
  return widest
}
