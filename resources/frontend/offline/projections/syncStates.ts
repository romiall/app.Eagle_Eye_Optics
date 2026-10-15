import type { Ack } from '@/domain/contracts/generated'

/**
 * États d'un enregistrement du poste (fichiers 19 et 23, contrat `sync/ack.json`), distincts de
 * l'état du réseau :
 * - `local` : commit local durable, pas encore envoyé ;
 * - `pending` : envoyé, sans ACK final ;
 * - `waiting_dependency` : le serveur attend une pièce liée (reprise dans l'ordre) ;
 * - `conflict` : CONFLICT_REQUIRES_REVIEW, originaux conservés, décision attendue ;
 * - `acknowledged` : ACCEPTED ou ALREADY_ACCEPTED enregistré localement (consolidé) ;
 * - `rejected` : REJECTED après revue, jamais un fait accepté.
 * Seuls `acknowledged` et `rejected` sont finaux : avant eux, rien n'est retiré de la file.
 */
export type LocalSyncState =
  'local' | 'pending' | 'waiting_dependency' | 'conflict' | 'acknowledged' | 'rejected'

/** État de la pièce PDF locale, distinct du statut central du document (contrat 23). */
export type DocumentState = 'none' | 'pending' | 'ready' | 'failed'

/** Origine d'une valeur lue : version consolidée du serveur, ou enregistrement du poste. */
export type RecordOrigin = 'server' | 'local'

export function syncStateFromAck(status: Ack['status']): LocalSyncState {
  switch (status) {
    case 'ACCEPTED':
    case 'ALREADY_ACCEPTED':
      return 'acknowledged'
    case 'WAITING_DEPENDENCY':
      return 'waiting_dependency'
    case 'CONFLICT_REQUIRES_REVIEW':
      return 'conflict'
    case 'REJECTED':
      return 'rejected'
  }
}

/** ACK final : la commande peut quitter la file active (elle reste archivée). */
export function isFinalState(state: LocalSyncState): boolean {
  return state === 'acknowledged' || state === 'rejected'
}

/** Pas encore consolidé par le serveur, mais fait enregistré sur ce poste (compte localement). */
export function isUnconfirmedState(state: LocalSyncState): boolean {
  return state === 'local' || state === 'pending' || state === 'waiting_dependency'
}

export const SYNC_STATE_LABELS: Readonly<Record<LocalSyncState | 'consolidated', string>> = {
  local: 'Enregistré sur ce poste',
  pending: 'En attente de synchronisation',
  waiting_dependency: 'En attente d’une pièce liée',
  conflict: 'Conflit à revoir',
  acknowledged: 'Consolidé sur le serveur',
  rejected: 'Rejeté après revue',
  consolidated: 'Consolidé sur le serveur',
}

export const DOCUMENT_STATE_LABELS: Readonly<Record<Exclude<DocumentState, 'none'>, string>> = {
  pending: 'PDF en préparation',
  ready: 'PDF prêt',
  failed: 'PDF à régénérer',
}
