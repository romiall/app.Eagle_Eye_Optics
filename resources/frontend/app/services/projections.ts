/**
 * Projections de lecture exposées aux écrans : un écran n'importe jamais `@/offline/*` lui-même
 * (règle ESLint) ; il passe par ce service d'application.
 */
export {
  type Freshness,
  type Identified,
  type LocalRecord,
  mergeById,
  type MergedRecord,
  type MergedView,
  type ServerSnapshot,
} from '@/offline/projections/merge'
export {
  type LedgerFact,
  type LedgerFactKind,
  type LedgerProjection,
  type LedgerProjectionInput,
  projectLedger,
} from '@/offline/projections/ledger'
