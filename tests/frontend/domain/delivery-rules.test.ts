import { describe, expect, it } from 'vitest'

import {
  creditDeliverySubject,
  describeSubject,
  KIND_LABELS,
  stockMovementSubject,
} from '@/domain/approvals/approvals'
import type { InvoiceDeliveries } from '@/domain/contracts/generated'
import {
  buildDeliveryPayload,
  buildMovementPayload,
  buildReturnPayload,
  CONDITION_LABELS,
  creditDeliveryRequired,
  deliveryCeiling,
  deliveryLineFieldFromServer,
  fullyDelivered,
  MOVEMENT_LABELS,
  pendingLines,
  quantityLabel,
  validateDelivery,
  validateMovement,
  validateReturn,
} from '@/domain/inventory/delivery'
import { Quantity } from '@/domain/values/decimal'

// Remise au patient (I01), retour physique, casse et ajustement (I02) : règles d'écran pures, jumelles de
// `DeliveryRules.php`. Le serveur rejuge tout ; aucun texte ne dit « payé » ni « soldé ». Données FICTIVES.
const FORBIDDEN = /pay[ée]|sold[ée]|gratuit/i
const INVOICE = '01J9ZXK4M2Q8R7S6T5V4W30026'
const FRAME_LINE = '01J9ZXK4M2Q8R7S6T5V4W3001Y'
const FRAME = '01J9ZXK4M2Q8R7S6T5V4W3001Z'
const DROPS_LINE = '01J9ZXK4M2Q8R7S6T5V4W30050'
const DROPS = '01J9ZXK4M2Q8R7S6T5V4W30051'
const EXAM_LINE = '01J9ZXK4M2Q8R7S6T5V4W30052'
const POSITION = '01J9ZXK4M2Q8R7S6T5V4W3002H'

function view(overrides: Partial<InvoiceDeliveries> = {}): InvoiceDeliveries {
  return {
    invoice_id: INVOICE,
    invoice_number: 'TEST-FAC-2026-000001',
    invoice_status: 'ISSUED',
    amount_due: 0,
    lines: [
      {
        invoice_line_id: FRAME_LINE,
        position: 1,
        item_id: FRAME,
        description: 'Monture fictive',
        stock_mode: 'STOCKED',
        quantity_integer_only: true,
        quantity: '5.000',
        delivered: '4.000',
        returned: '0.000',
        remaining: '1.000',
        reserved_open: '1.000',
        deliverable: true,
      },
      {
        invoice_line_id: DROPS_LINE,
        position: 2,
        item_id: DROPS,
        description: 'Collyre fictif',
        stock_mode: 'STOCKED',
        quantity_integer_only: true,
        quantity: '2.000',
        delivered: '0.000',
        returned: '0.000',
        remaining: '2.000',
        reserved_open: '0.000',
        deliverable: false,
      },
      {
        invoice_line_id: EXAM_LINE,
        position: 3,
        item_id: null,
        description: 'Ligne libre fictive',
        stock_mode: null,
        quantity_integer_only: true,
        quantity: '1.000',
        delivered: '0.000',
        returned: '0.000',
        remaining: '1.000',
        reserved_open: '0.000',
        deliverable: false,
      },
    ],
    deliveries: [],
    returns: [],
    ...overrides,
  }
}

describe('remise au patient', () => {
  it('le vecteur 5/3/1 laisse un article à remettre, plafonné par la réserve ouverte', () => {
    const current = view()
    const frame = current.lines[0]
    if (frame === undefined) throw new Error('ligne absente')

    expect(deliveryCeiling(frame).canonical()).toBe('1.000')
    expect(pendingLines(current).map((line) => line.invoice_line_id)).toEqual([
      FRAME_LINE,
      DROPS_LINE,
    ])
    expect(fullyDelivered(current)).toBe(false)

    expect(validateDelivery(current, { quantities: { [FRAME_LINE]: '1' } })).toEqual({
      ok: true,
      lines: [{ invoiceLineId: FRAME_LINE, itemId: FRAME, quantity: '1.000' }],
    })
    const over = validateDelivery(current, { quantities: { [FRAME_LINE]: '2' } })
    expect(over.ok).toBe(false)
    if (!over.ok) expect(over.errors[FRAME_LINE]).toContain('Au plus 1')
  })

  it('refuse une ligne sans réserve, une quantité fractionnaire et une remise vide', () => {
    const current = view()

    const noReserve = validateDelivery(current, { quantities: { [DROPS_LINE]: '1' } })
    expect(noReserve.ok).toBe(false)
    if (!noReserve.ok) expect(noReserve.errors[DROPS_LINE]).toContain('Aucune réserve ouverte')

    const fraction = validateDelivery(current, { quantities: { [FRAME_LINE]: '0,5' } })
    expect(fraction.ok).toBe(false)

    const empty = validateDelivery(current, { quantities: {} })
    expect(empty).toEqual({
      ok: false,
      errors: {},
      general: 'Indiquez la quantité remise d’au moins une ligne.',
    })
  })

  it('construit la charge de la route directe : le serveur choisit réserve, lot et mouvement', () => {
    const payload = buildDeliveryPayload({
      invoiceId: INVOICE,
      deliveryId: '01J9ZXK4M2Q8R7S6T5V4W30042',
      deliveredAt: '2026-10-22T09:30:00.000Z',
      creditApprovalId: null,
      lines: [
        {
          invoiceLineId: FRAME_LINE,
          itemId: FRAME,
          quantity: '1.000',
          deliveryLineId: '01J9ZXK4M2Q8R7S6T5V4W30043',
        },
      ],
    })

    expect(payload.lines[0]).toEqual({
      delivery_line_id: '01J9ZXK4M2Q8R7S6T5V4W30043',
      invoice_line_id: FRAME_LINE,
      item_id: FRAME,
      quantity: '1.000',
      stock_position_id: null,
      stock_lot_id: null,
      reservation_id: null,
      stock_movement_id: null,
    })
    expect(payload.credit_delivery_approval_id).toBeNull()
    expect(deliveryLineFieldFromServer('lines.0.quantity', [{ invoiceLineId: FRAME_LINE }])).toBe(
      FRAME_LINE,
    )
    expect(deliveryLineFieldFromServer('delivered_at', [{ invoiceLineId: FRAME_LINE }])).toBeNull()
  })

  it('une facture avec un reste à encaisser exige l’approbation, au contenu normalisé comme le serveur', () => {
    expect(creditDeliveryRequired(view({ amount_due: 20000 }))).toBe(true)
    expect(creditDeliveryRequired(view({ amount_due: 0 }))).toBe(false)

    const subject = creditDeliverySubject(INVOICE, 20000, [
      { invoice_line_id: FRAME_LINE, quantity: '1' },
      { invoice_line_id: DROPS_LINE, quantity: '0.5' },
      { invoice_line_id: DROPS_LINE, quantity: '1,5' },
    ])
    expect(subject).toEqual({
      kind: 'CREDIT_DELIVERY',
      invoice_id: INVOICE,
      amount_due: 20000,
      // Triées par identifiant de ligne (« …3001Y » avant « …30050 »), comme `ksort` du serveur.
      lines: [
        { invoice_line_id: FRAME_LINE, quantity: '1.000' },
        { invoice_line_id: DROPS_LINE, quantity: '2.000' },
      ],
    })
    expect(() => creditDeliverySubject(INVOICE, 20000, [])).toThrow()
  })
})

describe('retour physique', () => {
  const line = {
    returnable: '1.000',
    stock_position_id: POSITION,
  }

  it('plafonne par la quantité remise non retournée et exige état et motif', () => {
    expect(
      validateReturn(
        line,
        {
          quantity: '1',
          condition: 'SELLABLE',
          restock: true,
          reason: 'Intact',
          creditNoteId: null,
        },
        true,
      ),
    ).toEqual({
      ok: true,
      quantity: '1.000',
      condition: 'SELLABLE',
      restock: true,
      reason: 'Intact',
    })

    const over = validateReturn(
      line,
      { quantity: '2', condition: null, restock: false, reason: '', creditNoteId: null },
      true,
    )
    expect(over.ok).toBe(false)
    if (!over.ok) {
      expect(over.errors.quantity).toContain('Au plus 1')
      expect(over.errors.condition).toBeDefined()
      expect(over.errors.reason).toBeDefined()
    }
  })

  it('ne remet en stock qu’un article vendable géré en stock', () => {
    const damaged = validateReturn(
      line,
      {
        quantity: '1',
        condition: 'DAMAGED',
        restock: true,
        reason: 'Verre rayé',
        creditNoteId: null,
      },
      true,
    )
    expect(damaged.ok).toBe(false)
    const unstocked = validateReturn(
      { returnable: '1.000', stock_position_id: null },
      { quantity: '1', condition: 'SELLABLE', restock: true, reason: 'Intact', creditNoteId: null },
      true,
    )
    expect(unstocked.ok).toBe(false)

    const notRestocked = buildReturnPayload({
      returnId: '01J9ZXK4M2Q8R7S6T5V4W30058',
      deliveryLineId: '01J9ZXK4M2Q8R7S6T5V4W30043',
      itemId: FRAME,
      quantity: '1.000',
      condition: 'DAMAGED',
      restock: false,
      approvedBy: '01J9ZXK4M2Q8R7S6T5V4W30054',
      occurredAt: '2026-10-22T10:00:00.000Z',
      reason: 'Verre rayé',
      creditNoteId: null,
      movementId: '01J9ZXK4M2Q8R7S6T5V4W30059',
    })
    expect(notRestocked.stock_movement_id).toBeNull()
    expect(notRestocked.accepted_for_restock).toBe(false)
  })
})

describe('casse et ajustement', () => {
  const context = { available: '2.000', integerOnly: true, lotRequired: false, lotChosen: false }

  it('une casse sort du stock, jamais au-delà du disponible', () => {
    const breakage = validateMovement(
      { kind: 'BREAKAGE', quantity: '1', direction: 'in', reason: 'Monture tombée' },
      context,
    )
    expect(breakage.ok).toBe(true)
    if (breakage.ok) expect(breakage.signedQuantity.canonical()).toBe('-1.000')

    const over = validateMovement(
      { kind: 'BREAKAGE', quantity: '3', direction: 'out', reason: 'Monture tombée' },
      context,
    )
    expect(over.ok).toBe(false)
    if (!over.ok) expect(over.errors.quantity).toContain('Au plus 2')

    const reserved = validateMovement(
      { kind: 'ADJUSTMENT', quantity: '1', direction: 'out', reason: 'Écart' },
      { ...context, available: '-1.000' },
    )
    expect(reserved.ok).toBe(false)
  })

  it('un ajustement entre sans plafond ; le lot est obligatoire pour un article suivi par lots', () => {
    const entry = validateMovement(
      { kind: 'ADJUSTMENT', quantity: '5', direction: 'in', reason: 'Écart de comptage' },
      context,
    )
    expect(entry.ok).toBe(true)
    if (entry.ok) expect(entry.signedQuantity.canonical()).toBe('5.000')

    const noLot = validateMovement(
      { kind: 'ADJUSTMENT', quantity: '1', direction: 'in', reason: 'Écart' },
      { ...context, lotRequired: true },
    )
    expect(noLot.ok).toBe(false)
    if (!noLot.ok) expect(noLot.errors.lot).toBeDefined()
  })

  it('construit la charge et le contenu exact de l’approbation', () => {
    const signed = Quantity.of('-1.000')
    const payload = buildMovementPayload({
      movementId: '01J9ZXK4M2Q8R7S6T5V4W3005A',
      itemId: FRAME,
      positionId: POSITION,
      lotId: null,
      kind: 'BREAKAGE',
      signedQuantity: signed,
      approvalId: '01J9ZXK4M2Q8R7S6T5V4W3005B',
      reason: 'Monture tombée',
      occurredAt: '2026-10-22T09:30:00.000Z',
    })
    expect(payload).toMatchObject({ kind: 'BREAKAGE', signed_quantity: '-1.000', unit_cost: null })
    expect(() =>
      buildMovementPayload({
        movementId: '01J9ZXK4M2Q8R7S6T5V4W3005C',
        itemId: FRAME,
        positionId: POSITION,
        lotId: null,
        kind: 'BREAKAGE',
        signedQuantity: Quantity.of('1'),
        approvalId: '01J9ZXK4M2Q8R7S6T5V4W3005D',
        reason: 'Monture tombée',
        occurredAt: '2026-10-22T09:30:00.000Z',
      }),
    ).toThrow()
    expect(stockMovementSubject(POSITION, FRAME, null, 'BREAKAGE', signed)).toEqual({
      kind: 'STOCK_MOVEMENT',
      position_id: POSITION,
      item_id: FRAME,
      lot_id: null,
      movement_kind: 'BREAKAGE',
      signed_quantity: '-1.000',
    })
  })
})

describe('libellés', () => {
  it('ne disent jamais « payé » ni « soldé »', () => {
    const texts = [
      ...Object.values(CONDITION_LABELS),
      ...Object.values(MOVEMENT_LABELS),
      KIND_LABELS.CREDIT_DELIVERY,
      KIND_LABELS.STOCK_MOVEMENT,
      ...describeSubject(
        creditDeliverySubject(INVOICE, 20000, [{ invoice_line_id: FRAME_LINE, quantity: '1' }]),
        (amount) => `${amount} FCFA`,
        (method) => method,
      ).map((line) => `${line.label} ${line.value}`),
    ]
    for (const text of texts) expect(text).not.toMatch(FORBIDDEN)
    expect(quantityLabel('1.500')).toBe('1,5')
    expect(quantityLabel('2.000')).toBe('2')
  })
})
