import type { DocumentState, LocalSyncState, RecordOrigin } from './syncStates'

/**
 * Lecture consolidée commune (contrat 23 : « la projection tient compte des IDs ACK pour ne pas
 * compter deux fois le même fait »). Les entités locales et consolidées portent les mêmes
 * identifiants (ULID connus avant tout commit local) : la fusion se fait par identifiant, jamais en
 * additionnant un total serveur et un total local.
 *
 * Règles :
 * - un identifiant présent dans l'instantané serveur est consolidé : la version du serveur fait foi,
 *   la copie locale n'est plus comptée (son état local reste lisible pour l'affichage) ;
 * - un enregistrement local absent de l'instantané est repris avec son état local : `acknowledged`
 *   (accepté après la date de l'instantané), `local`, `pending`, `waiting_dependency`, `conflict`
 *   ou `rejected` ; c'est à la projection métier de décider ce qu'elle compte ;
 * - l'ordre est celui de l'instantané, puis des enregistrements locaux dans leur ordre d'origine.
 */
export interface Identified {
  id: string
}

export interface LocalRecord<T extends Identified> {
  value: T
  state: LocalSyncState
  /** Pièce PDF locale (reçu, facture), si l'enregistrement en a une. */
  document?: DocumentState
}

/** Instantané du serveur : ce qu'il a consolidé jusqu'à `asOf`. */
export interface ServerSnapshot<T extends Identified> {
  items: readonly T[]
  /** Instant du serveur (`server_time`) auquel l'instantané a été lu. */
  asOf: string
  /** Faux si la liste est tronquée ou partielle (pagination, compartiment, totaux distants incomplets). */
  complete: boolean
}

export interface MergedRecord<T extends Identified> {
  id: string
  value: T
  origin: RecordOrigin
  /** `consolidated` pour une valeur du serveur ; sinon l'état local. */
  state: LocalSyncState | 'consolidated'
  /** État local connu pour cet identifiant, même quand la valeur vient du serveur. */
  localState: LocalSyncState | null
  document: DocumentState
}

/** Fraîcheur des données affichées (fichier 04 : fraîcheur distante, AT45 : totaux incomplets). */
export interface Freshness {
  /** Instant de l'instantané serveur utilisé, ou null : données de ce poste seulement. */
  serverAsOf: string | null
  /** Faux si une partie distante manque : les totaux affichés sont incomplets. */
  complete: boolean
}

export interface MergedView<T extends Identified> {
  records: MergedRecord<T>[]
  freshness: Freshness
}

export function mergeById<T extends Identified>(
  snapshot: ServerSnapshot<T> | null,
  local: readonly LocalRecord<T>[],
): MergedView<T> {
  const localById = new Map<string, LocalRecord<T>>()
  for (const record of local) {
    if (localById.has(record.value.id)) {
      throw new Error(`Enregistrement local en double : ${record.value.id}.`)
    }
    localById.set(record.value.id, record)
  }

  const records: MergedRecord<T>[] = []
  const seen = new Set<string>()
  for (const item of snapshot?.items ?? []) {
    if (seen.has(item.id)) throw new Error(`Instantané serveur en double : ${item.id}.`)
    seen.add(item.id)
    const mine = localById.get(item.id)
    records.push({
      id: item.id,
      value: item,
      origin: 'server',
      state: 'consolidated',
      localState: mine?.state ?? null,
      document: mine?.document ?? 'none',
    })
  }
  for (const record of local) {
    if (seen.has(record.value.id)) continue
    records.push({
      id: record.value.id,
      value: record.value,
      origin: 'local',
      state: record.state,
      localState: record.state,
      document: record.document ?? 'none',
    })
  }

  return {
    records,
    freshness: {
      serverAsOf: snapshot?.asOf ?? null,
      complete: snapshot?.complete ?? false,
    },
  }
}
