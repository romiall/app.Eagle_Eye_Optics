import { ref } from 'vue'

/**
 * Message de succès passé d'un écran au suivant (« Patient enregistré : dossier EE-2026-0001. »), en
 * mémoire seulement : jamais dans l'URL ni dans un stockage. Lu une seule fois. Il ne contient
 * aucune donnée personnelle (le numéro de dossier n'est pas un nom).
 */
const pending = ref<string | null>(null)

export function setFlash(message: string): void {
  pending.value = message
}

export function takeFlash(): string | null {
  const message = pending.value
  pending.value = null
  return message
}
