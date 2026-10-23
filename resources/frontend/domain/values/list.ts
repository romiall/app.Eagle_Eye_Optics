/**
 * Liste non vide exigée par un contrat (`minItems: 1` : au moins une ligne). Une liste vide est une erreur de
 * programmation de l'écran, qui la refuse avant l'envoi : elle n'est jamais envoyée au serveur.
 */
export function nonEmpty<T>(items: readonly T[], what = 'élément'): [T, ...T[]] {
  const [first, ...rest] = items
  if (first === undefined) throw new RangeError(`Au moins un ${what} est attendu.`)
  return [first, ...rest]
}
