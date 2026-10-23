/**
 * Présentation d'une visite de la file du jour (charte du logo, docs/implementation/shell.md) :
 * teinte et icône de la puce de statut, filet coloré de la carte, icône du motif. Présentation
 * seule : le statut et le motif viennent du serveur et leurs libellés exacts de
 * `@/domain/patients/visits` ; une teinte ou une icône accompagne toujours ce libellé, jamais
 * seule (fichier 04). Un statut ou un motif que cette version ne connaît pas reste neutre : son
 * libellé le dit (« Statut inconnu (…) »), rien n'est deviné.
 */
import type { ChipTone } from '@/components/layout/StatusChip.vue'
import type { VisitsReason, VisitsStatus } from '@/domain/contracts/generated'

export interface VisitStatusView {
  /** Teinte de la puce d'état (`StatusChip`). */
  tone: ChipTone
  /** Nom d'une icône d'`AppIcon`, décorative. */
  icon: string
  /** Filet gauche de la carte de visite (couleur de bordure, élément non textuel). */
  accent: string
  /** Visite sortie de la file (annulée) : carte en retrait, sur le fond crème. */
  quiet: boolean
}

const STATUS_VIEWS: Readonly<Record<VisitsStatus, VisitStatusView>> = {
  ARRIVED: { tone: 'gold', icon: 'arrival', accent: 'border-l-gold', quiet: false },
  AWAITING_EXAM_FEE: {
    tone: 'warning',
    icon: 'wallet',
    accent: 'border-l-warning',
    quiet: false,
  },
  READY_FOR_EXAM: { tone: 'gold', icon: 'check', accent: 'border-l-gold', quiet: false },
  IN_CONSULTATION: {
    tone: 'info',
    icon: 'stethoscope',
    accent: 'border-l-primary',
    quiet: false,
  },
  COMPLETED: { tone: 'success', icon: 'check-circle', accent: 'border-l-accent', quiet: false },
  CANCELLED: { tone: 'neutral', icon: 'x-circle', accent: 'border-l-line', quiet: true },
}

const UNKNOWN_STATUS: VisitStatusView = {
  tone: 'neutral',
  icon: 'help',
  accent: 'border-l-line',
  quiet: false,
}

export function visitStatusView(status: string): VisitStatusView {
  return Object.prototype.hasOwnProperty.call(STATUS_VIEWS, status)
    ? STATUS_VIEWS[status as VisitsStatus]
    : UNKNOWN_STATUS
}

const REASON_ICONS: Readonly<Record<VisitsReason, string>> = {
  EXAM: 'eye',
  DIRECT_PURCHASE: 'tag',
  PICKUP: 'glasses',
  FOLLOW_UP: 'history',
}

/** Icône décorative du motif de la visite, à côté de son libellé. */
export function reasonIcon(reason: string): string {
  return Object.prototype.hasOwnProperty.call(REASON_ICONS, reason)
    ? REASON_ICONS[reason as VisitsReason]
    : 'clipboard'
}
