import { describe, expect, it } from 'vitest'

import {
  buildCreditNotePayload,
  buildRefundPayload,
  buildReversalPayload,
  checkCorrectionReason,
  creditableTotal,
  creditNoteSubjectLines,
  refundableAmount,
  reversibleAmount,
  validateCreditNote,
  validateRefund,
  validateReversal,
} from '@/domain/billing/corrections'

import { INVOICE_ID, issuedInvoice, PAYMENT_ID } from './support'

// Règles pures des corrections (B07, B08) : contre-écriture au plus la part encore contre-passable, avoir au plus
// la ligne d'origine et la part créditable de la facture, remboursement au plus la part de l'avoir non remboursée
// ET le crédit disponible, motif obligatoire, montants entiers. Le serveur refait tout sous verrou. Données FICTIVES.

const LINE_ID = '01J9ZXK4M2Q8R7S6T5V4W3001W'

function payment(amount: number, reversed: number, status: 'CONFIRMED' | 'PENDING' = 'CONFIRMED') {
  return { amount, reversed_amount: reversed, status }
}

describe('contre-écriture', () => {
  it('la part contre-passable est le montant moins les contre-écritures, nulle hors confirmé', () => {
    expect(reversibleAmount(payment(30000, 0))).toBe(30000)
    expect(reversibleAmount(payment(30000, 10000))).toBe(20000)
    expect(reversibleAmount(payment(30000, 30000))).toBe(0)
    expect(reversibleAmount(payment(30000, 0, 'PENDING'))).toBe(0)
  })

  it('montant entier positif, au plus la part contre-passable, et motif obligatoire', () => {
    expect(validateReversal({ amount: 20000, reason: 'Saisi deux fois' }, 20000)).toEqual({
      ok: true,
      amount: 20000,
      reason: 'Saisi deux fois',
    })
    const over = validateReversal({ amount: 20001, reason: 'Saisi deux fois' }, 20000)
    expect(over.ok).toBe(false)
    if (!over.ok) expect(over.errors.amount).toContain('dépasse')
    const noReason = validateReversal({ amount: 1000, reason: '  ' }, 20000)
    expect(noReason.ok).toBe(false)
    if (!noReason.ok) expect(noReason.errors.reason).toContain('motif')
    const none = validateReversal({ amount: 1000, reason: 'Saisi deux fois' }, 0)
    expect(none.ok).toBe(false)
  })

  it('la charge porte la facture, le paiement, l’identifiant de l’effet et l’autorisation', () => {
    expect(
      buildReversalPayload({
        invoiceId: INVOICE_ID,
        paymentId: PAYMENT_ID,
        reversalId: '01J9ZXK4M2Q8R7S6T5V4W3003X',
        amount: 5000,
        reason: 'Saisi deux fois',
        approvalId: '01J9ZXK4M2Q8R7S6T5V4W3003Y',
      }),
    ).toEqual({
      invoice_id: INVOICE_ID,
      payment_id: PAYMENT_ID,
      reversal_id: '01J9ZXK4M2Q8R7S6T5V4W3003X',
      amount: 5000,
      reason: 'Saisi deux fois',
      approval_id: '01J9ZXK4M2Q8R7S6T5V4W3003Y',
    })
  })
})

describe('avoir', () => {
  it('part créditable = total moins avoirs émis (projection du serveur)', () => {
    expect(creditableTotal({ total_gross: 80000, credited: 30000 })).toBe(50000)
    expect(creditableTotal({ total_gross: 80000, credited: 80000 })).toBe(0)
  })

  it('au moins une ligne, au plus la ligne d’origine et la part créditable, quantité exacte', () => {
    const invoice = issuedInvoice(80000)
    expect(
      validateCreditNote(
        {
          lines: [{ invoiceLineId: LINE_ID, amount: null, quantity: null }],
          reason: 'Geste commercial',
        },
        invoice,
      ),
    ).toMatchObject({ ok: false, errors: { lines: expect.stringContaining('au moins une ligne') } })

    const over = validateCreditNote(
      {
        lines: [{ invoiceLineId: LINE_ID, amount: 80001, quantity: null }],
        reason: 'Geste commercial',
      },
      invoice,
    )
    expect(over.ok).toBe(false)
    if (!over.ok) expect(over.errors.byLine?.[LINE_ID]).toContain('ligne d’origine')

    const badQuantity = validateCreditNote(
      {
        lines: [{ invoiceLineId: LINE_ID, amount: 1000, quantity: '0' }],
        reason: 'Geste commercial',
      },
      invoice,
    )
    expect(badQuantity.ok).toBe(false)

    expect(
      validateCreditNote(
        {
          lines: [{ invoiceLineId: LINE_ID, amount: 20000, quantity: '1' }],
          reason: ' Geste commercial ',
        },
        invoice,
      ),
    ).toEqual({
      ok: true,
      lines: [{ invoiceLineId: LINE_ID, amount: 20000, quantity: '1' }],
      total: 20000,
      reason: 'Geste commercial',
    })
  })

  it('le total ne dépasse jamais la part encore créditable de la facture', () => {
    const invoice = issuedInvoice(80000, {
      balance: {
        total_gross: 80000,
        paid_net: 80000,
        credited: 70000,
        refunded: 0,
        balance_due: -70000,
        credit_available: 70000,
        settlement: 'IN_CREDIT',
      },
    })
    const checked = validateCreditNote(
      {
        lines: [{ invoiceLineId: LINE_ID, amount: 20000, quantity: null }],
        reason: 'Geste commercial',
      },
      invoice,
    )
    expect(checked.ok).toBe(false)
    if (!checked.ok) expect(checked.errors.lines).toContain('part encore créditable')
  })

  it('la charge somme les lignes ; la demande d’autorisation porte exactement les mêmes lignes', () => {
    const lines = [{ invoiceLineId: LINE_ID, amount: 20000, quantity: null }]
    const payload = buildCreditNotePayload({
      invoiceId: INVOICE_ID,
      creditNoteId: '01J9ZXK4M2Q8R7S6T5V4W3003A',
      numberUsageId: '01J9ZXK4M2Q8R7S6T5V4W3003B',
      documentId: '01J9ZXK4M2Q8R7S6T5V4W3003C',
      approvalId: '01J9ZXK4M2Q8R7S6T5V4W3003D',
      reason: 'Geste commercial',
      lines: lines.map((line) => ({ ...line, lineId: '01J9ZXK4M2Q8R7S6T5V4W3003E' })),
    })
    expect(payload.amount_gross).toBe(20000)
    expect(payload).not.toHaveProperty('number')
    expect(payload.lines).toEqual([
      {
        credit_note_line_id: '01J9ZXK4M2Q8R7S6T5V4W3003E',
        invoice_line_id: LINE_ID,
        quantity: null,
        amount_gross: 20000,
        reason: null,
      },
    ])
    expect(creditNoteSubjectLines(lines)).toEqual([
      { invoice_line_id: LINE_ID, quantity: null, amount_gross: 20000 },
    ])
  })
})

describe('remboursement', () => {
  it('au plus la part de l’avoir non remboursée ET le crédit disponible', () => {
    expect(
      refundableAmount(
        { amount_gross: 30000, refunded_amount: 5000, status: 'ISSUED' },
        { credit_available: 40000 },
      ),
    ).toBe(25000)
    expect(
      refundableAmount(
        { amount_gross: 30000, refunded_amount: 0, status: 'ISSUED' },
        { credit_available: 10000 },
      ),
    ).toBe(10000)
    expect(
      refundableAmount(
        { amount_gross: 30000, refunded_amount: 0, status: 'DRAFT' },
        { credit_available: 30000 },
      ),
    ).toBe(0)
  })

  it('espèces sans session refusées ; référence exigée pour mobile money', () => {
    const cash = validateRefund(
      { amount: 1000, method: 'CASH', reference: '' },
      { refundable: 5000, cashSessionId: null },
    )
    expect(cash.ok).toBe(false)
    if (!cash.ok) expect(cash.errors.cashSession).toContain('session de caisse')

    const momo = validateRefund(
      { amount: 1000, method: 'MTN_MOMO', reference: '' },
      { refundable: 5000, cashSessionId: null },
    )
    expect(momo.ok).toBe(false)
    if (!momo.ok) expect(momo.errors.reference).toContain('obligatoire')

    expect(
      validateRefund(
        { amount: 5000, method: 'CASH', reference: '' },
        { refundable: 5000, cashSessionId: '01J9ZXK4M2Q8R7S6T5V4W3002Y' },
      ),
    ).toEqual({ ok: true, amount: 5000, method: 'CASH', reference: null })
  })

  it('la session de caisse n’accompagne qu’un remboursement en espèces', () => {
    const base = {
      invoiceId: INVOICE_ID,
      refundId: '01J9ZXK4M2Q8R7S6T5V4W3003E',
      creditNoteId: '01J9ZXK4M2Q8R7S6T5V4W3003A',
      numberUsageId: '01J9ZXK4M2Q8R7S6T5V4W3003F',
      documentId: '01J9ZXK4M2Q8R7S6T5V4W3003G',
      approvalId: '01J9ZXK4M2Q8R7S6T5V4W3003H',
      amount: 5000,
      reference: 'REFERENCE_FICTIVE',
      effectiveAt: '2026-10-15T09:00:00.000Z',
      cashSessionId: '01J9ZXK4M2Q8R7S6T5V4W3002Y',
    }
    expect(buildRefundPayload({ ...base, method: 'MTN_MOMO' }).cash_session_id).toBeNull()
    expect(buildRefundPayload({ ...base, method: 'CASH' }).cash_session_id).toBe(
      '01J9ZXK4M2Q8R7S6T5V4W3002Y',
    )
    expect(buildRefundPayload({ ...base, method: 'CASH' })).not.toHaveProperty('number')
  })
})

describe('motif', () => {
  it('3 à 1000 caractères, blancs de bord retirés', () => {
    expect(checkCorrectionReason('  ab ')).toMatchObject({ ok: false })
    expect(checkCorrectionReason(' Erreur de saisie ')).toEqual({
      ok: true,
      reason: 'Erreur de saisie',
    })
    expect(checkCorrectionReason('x'.repeat(1001))).toMatchObject({ ok: false })
  })
})
