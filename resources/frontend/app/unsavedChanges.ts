import { onBeforeUnmount, onMounted, toValue, type MaybeRefOrGetter } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'

export const UNSAVED_CHANGES_MESSAGE =
  'Des saisies ne sont pas encore enregistrées. Quitter cet écran sans les enregistrer ?'

/**
 * « Quitter une page avec données non sauvegardées demande confirmation » (fichier 04) : à utiliser
 * dans l'écran d'une saisie. `dirty` est vrai tant que la saisie n'a pas été enregistrée (commit
 * local réussi ou réponse du serveur). Navigation interne, y compris vers le même écran avec un autre
 * paramètre (`/dossier/1` → `/dossier/2`, composant réutilisé) : confirmation native du navigateur
 * (bloquante, accessible au clavier et aux lecteurs d'écran). Fermeture ou rechargement de l'onglet :
 * avertissement standard du navigateur. Rien n'est conservé ailleurs : aucune saisie dans
 * localStorage (le coffre local chiffré est le seul stockage des données, O01).
 */
export function useUnsavedChangesGuard(
  dirty: MaybeRefOrGetter<boolean>,
  confirmLeave: (message: string) => boolean = (message) => window.confirm(message),
): void {
  const guard = (): boolean => !toValue(dirty) || confirmLeave(UNSAVED_CHANGES_MESSAGE)
  onBeforeRouteLeave(guard)
  onBeforeRouteUpdate(guard)

  function onBeforeUnload(event: BeforeUnloadEvent): void {
    if (!toValue(dirty)) return
    event.preventDefault()
    // Navigateurs anciens (Chrome/Edge < 119) : returnValue doit aussi être posé (texte non affiché).
    event.returnValue = true
  }

  onMounted(() => window.addEventListener('beforeunload', onBeforeUnload))
  onBeforeUnmount(() => window.removeEventListener('beforeunload', onBeforeUnload))
}
