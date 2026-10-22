/**
 * Jeu de caractères imprimable des PDF (D01, fichier 11 : « police lisible avec accents »).
 *
 * Les PDF utilisent les polices standard du format PDF (Helvetica, encodage WinAnsi) : elles ne
 * dépendent d'aucune ressource distante, tous les lecteurs PDF les connaissent et elles couvrent
 * toutes les lettres accentuées du français (é è ê ë à â î ï ô ö ù û ü ç œ Œ …), les guillemets
 * « », l'apostrophe typographique, le tiret demi-cadratin et l'espace insécable. Les caractères
 * hors de ce jeu (certaines lettres de langues locales, symboles, émojis) ne peuvent PAS être
 * dessinés : ils sont remplacés et SIGNALÉS (jamais supprimés en silence). Le remède durable est
 * d'embarquer une police Unicode sous licence libre (voir `documents/fonts/README.md`).
 *
 * Les caractères acceptés par jsPDF pour l'encodage WinAnsi sont ceux de sa table
 * Unicode → WinAnsi (jspdf.es.js, `encodingBlock`) : U+0020–U+007E, U+00A0–U+00FF et les 27 points
 * de code listés ci-dessous.
 */
const WIN_ANSI_EXTRA: ReadonlySet<number> = new Set([
  0x0152, 0x0153, 0x0160, 0x0161, 0x0178, 0x017d, 0x017e, 0x0192, 0x02c6, 0x02dc, 0x2013, 0x2014,
  0x2018, 0x2019, 0x201a, 0x201c, 0x201d, 0x201e, 0x2020, 0x2021, 0x2022, 0x2026, 0x2030, 0x2039,
  0x203a, 0x20ac, 0x2122,
])

export const NBSP = '\u{00A0}'

/** Remplacement sans perte d'information : espaces fines et tirets typographiques. */
const BENIGN: Readonly<Record<number, string>> = {
  0x202f: NBSP,
  0x2007: NBSP,
  0x2002: ' ',
  0x2003: ' ',
  0x2004: ' ',
  0x2005: ' ',
  0x2006: ' ',
  0x2008: ' ',
  0x2009: ' ',
  0x200a: ' ',
  0x205f: ' ',
  0x3000: ' ',
  0x2010: '-',
  0x2011: '-',
  0x2012: '-',
  0x2015: '-',
  0x2212: '-',
  0x00ad: '',
  0x200b: '',
  0x200c: '',
  0x200d: '',
  0x200e: '',
  0x200f: '',
  0x2060: '',
  0xfeff: '',
  0x2192: '->',
  0x2190: '<-',
  0x2264: '<=',
  0x2265: '>=',
}

export function isPdfSafeCodePoint(codePoint: number): boolean {
  return (
    (codePoint >= 0x20 && codePoint <= 0x7e) ||
    (codePoint >= 0xa0 && codePoint <= 0xff) ||
    WIN_ANSI_EXTRA.has(codePoint)
  )
}

export interface PdfText {
  /** Texte imprimable (retours à la ligne « \n » conservés). */
  text: string
  /** Caractères d'origine remplacés avec perte (lettre sans accent, ou « ? »). */
  lossy: string[]
}

function mapCharacter(character: string, lossy: Set<string>): string {
  const codePoint = character.codePointAt(0) ?? 0x3f
  if (character === '\n') return '\n'
  if (codePoint === 0x09) return ' '
  if (codePoint < 0x20 || (codePoint >= 0x7f && codePoint < 0xa0)) return ''
  const benign = BENIGN[codePoint]
  if (benign !== undefined) return benign
  if (isPdfSafeCodePoint(codePoint)) return character

  // Lettre composée absente du jeu (ex. « ǹ ») : la lettre de base si elle est imprimable.
  const base = [...character.normalize('NFD')].filter((part) => !/\p{Mn}/u.test(part)).join('')
  lossy.add(character)
  if (base !== '' && [...base].every((part) => isPdfSafeCodePoint(part.codePointAt(0) ?? 0))) {
    return base
  }
  return '?'
}

/**
 * Texte imprimable : normalisé en NFC (une lettre et son accent combinant deviennent « é »), espaces
 * et tirets typographiques ramenés au jeu WinAnsi, caractères de contrôle retirés. Ne lève jamais :
 * ce qui ne peut pas être dessiné est remplacé et listé dans `lossy`.
 */
export function toPdfText(input: string): PdfText {
  const lossy = new Set<string>()
  let text = ''
  for (const character of input.replace(/\r\n?/g, '\n').normalize('NFC')) {
    text += mapCharacter(character, lossy)
  }
  return { text, lossy: [...lossy] }
}

/** Le texte est déjà imprimable tel quel (aucune substitution, ni bénigne ni avec perte). */
export function isPdfSafe(input: string): boolean {
  return [...input].every(
    (character) =>
      character === '\n' ||
      (BENIGN[character.codePointAt(0) ?? 0] === undefined &&
        isPdfSafeCodePoint(character.codePointAt(0) ?? 0)),
  )
}
