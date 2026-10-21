/**
 * Projections de lecture exposées aux écrans : un écran n'importe jamais `@/offline/*` lui-même
 * (règle ESLint) ; il passe par ce service d'application.
 *
 * - `mergeFacts` : faits en ajout seul (paiements, avoirs, mouvements) ; la version du serveur fait foi.
 * - `mergeVersioned` : entités modifiables (identité, consultation, rendez-vous) ; une proposition du
 *   poste non finalisée n'est jamais masquée par la version du serveur.
 * - `projectLedger` / `assertCollectable` : solde d'une facture et contrôle d'un nouvel encaissement.
 */
export {
  type Freshness,
  type Identified,
  type LocalRecord,
  mergeFacts,
  type MergedRecord,
  type MergedView,
  mergeVersioned,
  type ServerSnapshot,
  type Versioned,
} from '@/offline/projections/merge'
export {
  assertCollectable,
  type LedgerFact,
  type LedgerFactKind,
  type LedgerProjection,
  type LedgerProjectionInput,
  projectLedger,
  type ReviewedFact,
  type ReviewReason,
} from '@/offline/projections/ledger'
