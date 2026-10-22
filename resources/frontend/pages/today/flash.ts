import { ref } from 'vue'

import { knownAccountId } from '@/app/session'

/**
 * Message de succès passé de l'arrivée enregistrée à la file du jour (« Arrivée enregistrée : NGONO
 * Marie »), en mémoire seulement : jamais dans l'URL ni dans un stockage. Lu une seule fois. Il porte
 * le nom du patient : il appartient au compte qui l'a posé et n'est jamais montré à un autre compte
 * connecté sur le même poste.
 */
const pending = ref<{ owner: string | null; message: string } | null>(null)

export function setArrivalFlash(message: string): void {
  pending.value = { owner: knownAccountId(), message }
}

export function takeArrivalFlash(): string | null {
  const current = pending.value
  pending.value = null
  return current !== null && current.owner === knownAccountId() ? current.message : null
}
