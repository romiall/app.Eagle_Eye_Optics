/**
 * Erreurs du moteur de documents. Un document n'est jamais produit à partir de données incohérentes :
 * le modèle refuse (code stable, champ, message en français sans donnée personnelle) plutôt que
 * d'imprimer une pièce fausse. Le message ne reprend jamais une valeur du document (nom, montant).
 */
export class DocumentDataError extends Error {
  readonly code: string
  readonly field: string

  constructor(code: string, field: string, message: string) {
    super(message)
    this.name = 'DocumentDataError'
    this.code = code
    this.field = field
  }
}

export function invalid(code: string, field: string, message: string): DocumentDataError {
  return new DocumentDataError(code, field, message)
}
