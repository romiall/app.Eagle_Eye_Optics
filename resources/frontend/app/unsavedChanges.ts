import { onBeforeUnmount, onMounted, toValue, type MaybeRefOrGetter } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'

export const UNSAVED_CHANGES_MESSAGE =
  'Des saisies ne sont pas encore enregistrées. Quitter cet écran sans les enregistrer ?'

/**
 * « Quitter une page avec données non sauvegardées demande confirmation » (fichier 04) : à utiliser
 * dans l'écran d'une saisie. `dirty` est vrai tant que la saisie n'a pas été enregistrée (commit
 * local réussi ou réponse du serveur). Navigation interne : confirmation native du navigateur
 * (bloquante, accessible au clavier et aux lecteurs d'écran). Fermeture ou rechargement de l'onglet :
 * avertissement standard du navigateur. Rien n'est conservé ailleurs : aucune saisie dans
 * localStorage (le coffre local chiffré est le seul stockage des données, O01).
 */
export function useUnsavedChangesGuard(
  dirty: MaybeRefOrGetter<boolean>,
  confirmLeave: (message: string) => boolean = (message) => window.confirm(message),
): void {
  onBeforeRouteLeave(() => !toValue(dirty) || confirmLeave(UNSAVED_CHANGES_MESSAGE))

  function onBeforeUnload(event: BeforeUnloadEvent): void {
    if (!toValue(dirty)) return
    event.preventDefault()
    // Navigateurs anciens (Chrome/Edge < 119) : returnValue doit aussi être posé (texte non affiché).
    event.returnValue = true
  }

  onMounted(() => window.addEventListener('beforeunload', onBeforeUnload))
  onBeforeUnmount(() => window.removeEventListener('beforeunload', onBeforeUnload))
}
