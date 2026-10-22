import type { Invoice, IssueInvoicePayload } from '../contracts/generated'

/**
 * Émission d'une facture par la route directe (B03), EN LIGNE : la charge de `IssueInvoice` sans le
 * numéro (le répartiteur met `number: null`, le serveur numérote sous verrou), sans propriétaire de poste
 * et sans réserve (le serveur choisit positions et lots). `total_gross` est le total que l'écran a
 * affiché : le serveur le recalcule et refuse l'écart (422, `expected_total`). Le numéro définitif n'est
 * connu qu'après la réponse du serveur ; l'écran ne l'invente jamais.
 */
export type IssueRequestPayload = Omit<IssueInvoicePayload, 'number'>

export interface IssueInput {
  invoiceId: string
  numberUsageId: string
  documentId: string
  issuedAt: string
  totalGross: number
  /** Empreinte SHA-256 de ce que l'écran a montré (lignes, prix) : consignée dans l'audit du serveur. */
  catalogSnapshotSha256: string
}

export function buildIssuePayload(input: IssueInput): IssueRequestPayload {
  return {
    invoice_id: input.invoiceId,
    number_usage_id: input.numberUsageId,
    issued_at: input.issuedAt,
    total_gross: input.totalGross,
    write_owner_id: null,
    catalog_snapshot_sha256: input.catalogSnapshotSha256,
    reservations: [],
    document_id: input.documentId,
  }
}

/**
 * Contenu canonique des lignes affichées au moment de l'émission (ordre, article, désignation, quantité,
 * prix, remise, total). Son empreinte part avec la commande : elle dit ce que l'opératrice a vu, le
 * serveur garde la sienne (catalogue actif).
 */
export function displayedLinesFingerprint(invoice: Invoice): string {
  const lines = [...invoice.lines]
    .sort((left, right) => left.position - right.position)
    .map((line) => [
      line.position,
      line.catalog_item_id,
      line.reference_snapshot,
      line.description_snapshot,
      line.quantity,
      line.unit_price_gross,
      line.discount_gross,
      line.line_total_gross,
    ])
  return JSON.stringify({ invoice_id: invoice.id, draft_version: invoice.draft_version, lines })
}

/** Facture qu'un poste préparé détient : la route directe refuse d'y écrire (WRITE_OWNER_REQUIRED). */
export function heldByDevice(invoice: Invoice): boolean {
  return (
    invoice.write_owner !== null &&
    (invoice.write_owner.status === 'ACTIVE' || invoice.write_owner.status === 'CLOSING')
  )
}

/** Raisons, connues de l'écran, de ne pas proposer l'émission (le serveur revérifie tout). */
export function issueBlockers(invoice: Invoice, displayedTotal: number | null): string[] {
  const blockers: string[] = []
  if (invoice.status !== 'DRAFT') blockers.push('Seul un brouillon peut être émis.')
  if (invoice.lines.length === 0) {
    blockers.push('Une facture sans ligne ne s’émet pas : ajoutez au moins une ligne.')
  }
  if (displayedTotal === null) blockers.push('Le total n’est pas calculable : corrigez les lignes.')
  if (heldByDevice(invoice)) {
    blockers.push(
      'Cette facture est détenue par un poste préparé : elle ne peut pas être émise depuis cet écran.',
    )
  }
  return blockers
}
