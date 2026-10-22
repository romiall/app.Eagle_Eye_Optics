import { reactive } from 'vue'

import { knownAccountId } from '@/app/session'

/**
 * Recherche en cours, conservée pendant la navigation de la session (fichier 04 : « filtres
 * conservés pendant la navigation […] sans conserver de dossier patient dans l'URL publique »). En
 * mémoire de l'écran seulement : ni URL, ni localStorage, ni sessionStorage. Elle appartient au
 * compte qui l'a saisie : un autre compte sur le même poste repart d'une recherche vide, il ne voit
 * jamais les noms cherchés par la personne précédente.
 */
interface SearchMemory {
  owner: string | null
  /** Texte dans le champ de recherche. */
  text: string
  /** Recherche envoyée (vide : aucune). */
  query: string
  page: number
  /** Incrémenté à chaque envoi : relancer la même recherche la relit. */
  run: number
}

const memory = reactive<SearchMemory>({ owner: null, text: '', query: '', page: 1, run: 0 })

export function searchMemory(): SearchMemory {
  const account = knownAccountId()
  if (memory.owner !== account) {
    memory.owner = account
    memory.text = ''
    memory.query = ''
    memory.page = 1
    memory.run = 0
  }
  return memory
}

/** Réservé aux tests : état initial. */
export function resetSearchMemoryForTests(): void {
  memory.owner = null
  memory.text = ''
  memory.query = ''
  memory.page = 1
  memory.run = 0
}
