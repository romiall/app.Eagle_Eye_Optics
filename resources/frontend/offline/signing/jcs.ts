/**
 * Sérialisation JSON canonique JCS (RFC 8785) des contenus signés du hors-ligne, jumelle d'`App\Offline\Jcs` côté
 * serveur (mêmes vecteurs : `contracts/vectors/signatures/jcs.json`). Domaine du contrat (fichier 23) : null,
 * booléens, ENTIERS sûrs, chaînes, listes, objets. Un nombre non entier ou hors de la plage sûre est refusé (les
 * décimaux voyagent en chaînes), de même qu'une chaîne contenant une demi-paire de substitution (texte Unicode
 * invalide, sans forme UTF-8).
 *
 * Clés triées par unités de code UTF-16 : c'est l'ordre du tri par défaut des chaînes en JavaScript. Chaînes
 * écrites par `JSON.stringify` (échappement minimal exigé par RFC 8785).
 */
export class JcsError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'JcsError'
  }
}

function assertWellFormed(text: string): void {
  for (let index = 0; index < text.length; index += 1) {
    const code = text.charCodeAt(index)
    if (code >= 0xd800 && code <= 0xdbff) {
      const next = text.charCodeAt(index + 1)
      if (!(next >= 0xdc00 && next <= 0xdfff)) throw new JcsError('Texte Unicode invalide.')
      index += 1
    } else if (code >= 0xdc00 && code <= 0xdfff) {
      throw new JcsError('Texte Unicode invalide.')
    }
  }
}

export function canonicalize(value: unknown): string {
  if (value === null) return 'null'
  if (value === true) return 'true'
  if (value === false) return 'false'
  if (typeof value === 'number') {
    if (!Number.isSafeInteger(value)) {
      throw new JcsError('Nombre sans forme admise dans un contenu signé : chaîne décimale exigée.')
    }
    return Object.is(value, -0) ? '0' : String(value)
  }
  if (typeof value === 'string') {
    assertWellFormed(value)
    return JSON.stringify(value)
  }
  if (Array.isArray(value)) return `[${value.map((item) => canonicalize(item)).join(',')}]`
  if (typeof value === 'object') {
    const record = value as Record<string, unknown>
    const keys = Object.keys(record).sort()
    return `{${keys
      .map((key) => {
        assertWellFormed(key)
        return `${JSON.stringify(key)}:${canonicalize(record[key])}`
      })
      .join(',')}}`
  }
  throw new JcsError('Type de valeur sans forme JCS.')
}
