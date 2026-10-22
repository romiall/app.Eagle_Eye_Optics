/**
 * Message de succès affiché UNE fois par l'écran suivant (création d'un compte, paramètres enregistrés) :
 * gardé en mémoire de la page seulement, jamais dans le stockage du navigateur. Aucun mot de passe n'y
 * passe : le mot de passe initial d'un compte est affiché sur l'écran de création lui-même.
 */
let pending: string | null = null

export function setSettingsFlash(message: string): void {
  pending = message
}

export function takeSettingsFlash(): string | null {
  const message = pending
  pending = null
  return message
}
