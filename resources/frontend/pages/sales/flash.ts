import { ref } from 'vue'

/**
 * Message de succès passé d'un écran de vente au suivant (« Brouillon enregistré. »), en mémoire
 * seulement : jamais dans l'URL ni dans un stockage, lu une seule fois. Il ne contient ni nom ni
 * montant : au plus un numéro de pièce.
 */
const pending = ref<string | null>(null)

export function setSalesFlash(message: string): void {
  pending.value = message
}

export function takeSalesFlash(): string | null {
  const message = pending.value
  pending.value = null
  return message
}
