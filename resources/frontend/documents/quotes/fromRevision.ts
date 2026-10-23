import type { QuoteRevisionSnapshot } from '@/domain/contracts/generated'

import { clinicFromSnapshot } from '../data/clinic'
import { type QuoteDocumentData, type QuoteRecipientData, taxFromSnapshot } from '../data/financial'
import { invalid } from '../engine/errors'
import type { DocumentLogo } from '../engine/model'

/**
 * Données du document d'une RÉVISION de devis (L1D07), depuis son snapshot figé par le serveur
 * (`quote_revisions.snapshot`, `contracts/snapshots/quote-revision.json`) : numéro, révision, validité,
 * destinataire, lignes, taxe comprise, cabinet et auteur tels qu'ils étaient. Jamais l'identité, les prix
 * ni le cabinet actuels : une ancienne révision se réimprime à l'identique. Fonction pure.
 */
export function quoteFromRevision(
  snapshot: QuoteRevisionSnapshot,
  logo: DocumentLogo | null = null,
): QuoteDocumentData {
  const revised = snapshot.revision_no > 1
  return {
    templateVersion: snapshot.template_version,
    quoteId: snapshot.quote_id,
    number: snapshot.number,
    revisionNo: snapshot.revision_no,
    issuedAt: snapshot.issued_at,
    revisedAt: revised ? snapshot.recorded_at : null,
    revisionReason: revised ? snapshot.reason : null,
    validUntil: snapshot.valid_until,
    clinic: clinicFromSnapshot(snapshot.clinic, logo),
    recipient: recipientOf(snapshot.recipient),
    tax: taxFromSnapshot(snapshot.tax),
    lines: snapshot.lines.map((line) => ({
      position: line.position,
      reference: line.reference,
      description: line.description,
      unitCode: line.unit_code,
      quantity: line.quantity,
      unitPriceGross: line.unit_price_gross,
      discountGross: line.discount_gross,
      lineTotalGross: line.line_total_gross,
      taxCode: line.tax_code,
      taxRatePercent: line.tax_rate_percent,
      taxAmount: line.tax_amount,
    })),
    totalGross: snapshot.total_gross,
    createdByName: snapshot.authored_by.display_name,
  }
}

function recipientOf(recipient: QuoteRevisionSnapshot['recipient']): QuoteRecipientData {
  if (recipient.kind === 'PROSPECT') {
    return { kind: 'PROSPECT', name: recipient.last_name, phone: recipient.phone }
  }
  if (recipient.patient_id === null || recipient.record_number === null) {
    throw invalid(
      'quote.recipient_missing',
      'recipient',
      'Le dossier du patient destinataire manque dans la révision figée du devis.',
    )
  }
  return {
    kind: 'PATIENT',
    patient: {
      patientId: recipient.patient_id,
      recordNumber: recipient.record_number,
      lastName: recipient.last_name,
      firstNames: recipient.first_names,
    },
  }
}
