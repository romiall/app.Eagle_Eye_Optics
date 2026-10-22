import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Invoice } from '@/domain/contracts/generated'

import {
  apiError,
  buttonByName,
  callsTo,
  CASHIER,
  click,
  forbiddenWordsIn,
  INVOICE_ID,
  issuedInvoice,
  json,
  openApp,
  openSession,
  PAYMENT_ID,
  paymentResult,
  receipt,
  RECEIPT_ID,
  resetSales,
  type SalesApi,
  selectInto,
  SESSION_ID,
  settle,
  stubSalesApi,
  textOf,
  typeInto,
} from './support'

// Encaissement (B04 ; AT10, AT11, AT12), EN LIGNE : acompte puis solde, deux reçus distincts, clé
// d'idempotence liée à la tentative (double clic, réponse perdue : jamais deux encaissements), reste
// actualisé par le serveur, session de caisse pour les espèces, aucun mot « payé » ni « soldé ».
// Données FICTIVES.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-15T09:00:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  resetSales()
})

const PAGE = `/ventes/factures/${INVOICE_ID}`

function confirmed(
  invoice: Invoice,
  payments: { id: string; amount: number; receiptId: string; number: string }[],
): Invoice {
  return {
    ...invoice,
    ledger_version: 1 + payments.length,
    payments: payments.map((payment) => ({
      id: payment.id,
      amount: payment.amount,
      method: 'CASH' as const,
      reference: null,
      effective_at: '2026-10-15T09:00:00.000Z',
      confirmed_at: '2026-10-15T09:00:00.000Z',
      status: 'CONFIRMED' as const,
      receipt_id: payment.receiptId,
      receipt_number: payment.number,
      reversed_amount: 0,
      received_by: '01J9ZXK4M2Q8R7S6T5V4W30002',
      received_by_name: 'Caisse (démonstration)',
    })),
  }
}

/** Serveur d'une facture émise de 80 000 et d'une caisse ouverte ; les encaissements s'y ajoutent. */
function server(): {
  api: SalesApi
  state: { paid: { id: string; amount: number; receiptId: string; number: string }[] }
} {
  const api = stubSalesApi({ permissions: CASHIER })
  const state = { paid: [] as { id: string; amount: number; receiptId: string; number: string }[] }
  const current = (): Invoice => {
    const total = state.paid.reduce((sum, payment) => sum + payment.amount, 0)
    return confirmed(issuedInvoice(total), state.paid)
  }
  api.on('GET', /^\/api\/v1\/invoices\/([^/]+)$/, () => json(200, { data: current() }))
  api.on('GET', /^\/api\/v1\/cash-sessions\/current$/, () => json(200, { data: openSession() }))
  api.on('GET', /^\/api\/v1\/receipts\/([^/]+)$/, (_request, match) => {
    const index = state.paid.findIndex((payment) => payment.receiptId === match[1])
    const payment = state.paid[index]
    if (payment === undefined) return apiError(404, 'RESOURCE_NOT_FOUND', 'Reçu introuvable.')
    const before = state.paid.slice(0, index).reduce((sum, item) => sum + item.amount, 0)
    return json(200, {
      data: receipt({
        id: payment.receiptId,
        number: payment.number,
        amount: payment.amount,
        before,
      }),
    })
  })
  api.on('POST', /^\/api\/v1\/invoices\/([^/]+)\/payments$/, (request) => {
    const body = request.body ?? {}
    const invoice = current()
    if (body['expected_version'] !== invoice.ledger_version) {
      return json(409, {
        error: {
          code: 'VERSION_CONFLICT',
          message: 'La facture a changé depuis votre affichage.',
          details: {
            current_version: invoice.ledger_version,
            amount_due: invoice.balance.balance_due,
          },
        },
        correlation_id: 'corr-test-0500',
      })
    }
    const amount = Number(body['amount'])
    if (amount > invoice.balance.balance_due) {
      return json(409, {
        error: {
          code: 'PAYMENT_EXCEEDS_BALANCE',
          message: 'Le montant dépasse le reste à payer de cette facture.',
          details: {
            amount_due: invoice.balance.balance_due,
            current_version: invoice.ledger_version,
          },
        },
        correlation_id: 'corr-test-0501',
      })
    }
    const number = `TEST-REC-2026-00000${state.paid.length + 1}`
    state.paid.push({
      id: String(body['payment_id']),
      amount,
      receiptId: String(body['receipt_id']),
      number,
    })
    const after = current()
    return json(201, {
      data: paymentResult({
        payment_id: String(body['payment_id']),
        receipt_id: String(body['receipt_id']),
        receipt_number: number,
        amount,
        ledger_version: after.ledger_version ?? 1,
        balance: after.balance,
      }),
    })
  })
  return { api, state }
}

async function submitPayment(root: ParentNode): Promise<void> {
  const form = root.querySelector('form[aria-labelledby="titre-encaisser"]')
  if (form === null) throw new Error('Formulaire d’encaissement introuvable.')
  form.dispatchEvent(new Event('submit', { cancelable: true }))
  await settle()
}

describe('encaisser un acompte puis le solde', () => {
  it('deux encaissements, deux reçus distincts, reste actualisé par le serveur', async () => {
    const { api } = server()
    const { root, unmount } = await openApp(PAGE)

    expect(textOf(root)).toContain('Facture TEST-FAC-2026-000001')
    expect(textOf(root)).toContain('Reste à encaisser : 80 000 FCFA')
    expect(textOf(root)).toContain('Total encaissé')

    await typeInto(root, 'encaissement-montant', '30000')
    await selectInto(root, 'encaissement-mode', 'CASH')
    await submitPayment(root)

    const [first] = callsTo(api, 'POST', /payments$/)
    expect(first?.body).toMatchObject({
      expected_version: 1,
      invoice_id: INVOICE_ID,
      amount: 30000,
      currency: 'XAF',
      method: 'CASH',
      cash_session_id: SESSION_ID,
      receipt_number: null,
      effective_at: '2026-10-15T09:00:00.000Z',
    })
    expect(first?.headers['Idempotency-Key']).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/)
    await vi.waitFor(() => expect(textOf(root)).toContain('Reçu TEST-REC-2026-000001'))
    expect(textOf(root)).toContain('Encaissement enregistré sur le serveur')
    await vi.waitFor(() => expect(textOf(root)).toContain('Acompte'))
    await vi.waitFor(() =>
      expect(textOf(root)).toContain('Reste à encaisser maintenant : 50 000 FCFA'),
    )

    await click(buttonByName(root, 'Nouvel encaissement'))
    await click(buttonByName(root, /^Encaisser le reste/))
    await selectInto(root, 'encaissement-mode', 'CASH')
    await submitPayment(root)

    const payments = callsTo(api, 'POST', /payments$/)
    expect(payments).toHaveLength(2)
    const second = payments[1]
    expect(second?.body).toMatchObject({ expected_version: 2, amount: 50000 })
    // Nouvelle intention : nouvelle clé, nouveaux identifiants de paiement et de reçu.
    expect(second?.headers['Idempotency-Key']).not.toBe(first?.headers['Idempotency-Key'])
    expect(second?.body?.['payment_id']).not.toBe(first?.body?.['payment_id'])
    expect(second?.body?.['receipt_id']).not.toBe(first?.body?.['receipt_id'])

    await vi.waitFor(() => expect(textOf(root)).toContain('Reçu TEST-REC-2026-000002'))
    await vi.waitFor(() => expect(textOf(root)).toContain('Reçu TEST-REC-2026-000001'))
    await vi.waitFor(() => expect(textOf(root)).toContain('Règlement du solde'))
    await vi.waitFor(() => expect(textOf(root)).toContain('Reste à encaisser maintenant : 0 FCFA'))
    // Reste nul : plus de formulaire d'encaissement, et jamais « payé » ni « soldé ».
    expect(root.querySelector('form[aria-labelledby="titre-encaisser"]')).toBeNull()
    expect(forbiddenWordsIn(root)).toEqual([])

    unmount()
  })

  it('double clic : un seul encaissement envoyé', async () => {
    const { api } = server()
    let release = (): void => undefined
    const hold = new Promise<void>((resolve) => {
      release = resolve
    })
    api.on('POST', /payments$/, async (request) => {
      await hold
      const body = request.body ?? {}
      return json(201, {
        data: paymentResult({
          payment_id: String(body['payment_id']),
          receipt_id: String(body['receipt_id']),
        }),
      })
    })
    const { root, unmount } = await openApp(PAGE)

    await typeInto(root, 'encaissement-montant', '30000')
    await selectInto(root, 'encaissement-mode', 'CASH')
    const form = root.querySelector('form[aria-labelledby="titre-encaisser"]')
    form?.dispatchEvent(new Event('submit', { cancelable: true }))
    form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await settle()

    expect(callsTo(api, 'POST', /payments$/)).toHaveLength(1)
    release()
    await settle()
    expect(callsTo(api, 'POST', /payments$/)).toHaveLength(1)

    unmount()
  })

  it('réponse perdue : champs verrouillés, « Réessayer » rejoue la même commande (même clé, mêmes identifiants)', async () => {
    const { api } = server()
    let attempts = 0
    api.on('POST', /payments$/, (request) => {
      attempts += 1
      if (attempts === 1) return 'network'
      const body = request.body ?? {}
      return json(201, {
        data: paymentResult({
          payment_id: String(body['payment_id']),
          receipt_id: String(body['receipt_id']),
        }),
      })
    })
    const { root, unmount } = await openApp(PAGE)

    await typeInto(root, 'encaissement-montant', '30000')
    await selectInto(root, 'encaissement-mode', 'CASH')
    await submitPayment(root)

    expect(textOf(root)).toContain('Enregistrement non confirmé')
    expect(textOf(root)).toContain('Ne recommencez pas l’encaissement')
    expect(root.querySelector<HTMLInputElement>('[id="encaissement-montant"]')?.disabled).toBe(true)

    await click(buttonByName(root, 'Réessayer l’encaissement'))

    const sent = callsTo(api, 'POST', /payments$/)
    expect(sent).toHaveLength(2)
    expect(sent[1]?.headers['Idempotency-Key']).toBe(sent[0]?.headers['Idempotency-Key'])
    expect(sent[1]?.body).toStrictEqual(sent[0]?.body)
    expect(sent[0]?.body?.['payment_id']).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/)

    unmount()
  })

  it('une relecture qui montre le paiement de la tentative le constate sans renvoi', async () => {
    const { api, state } = server()
    api.on('POST', /payments$/, (request) => {
      // Le serveur a enregistré, mais la réponse s'est perdue.
      state.paid.push({
        id: String(request.body?.['payment_id']),
        amount: 30000,
        receiptId: RECEIPT_ID,
        number: 'TEST-REC-2026-000001',
      })
      return 'network'
    })
    const { root, unmount } = await openApp(PAGE)

    await typeInto(root, 'encaissement-montant', '30000')
    await selectInto(root, 'encaissement-mode', 'CASH')
    await submitPayment(root)
    await click(buttonByName(root, 'Relire la facture'))

    await vi.waitFor(() => expect(textOf(root)).toContain('Encaissement enregistré sur le serveur'))
    expect(callsTo(api, 'POST', /payments$/)).toHaveLength(1)
    expect(textOf(root)).toContain('Reçu TEST-REC-2026-000001')

    unmount()
  })
})

describe('refus du serveur', () => {
  it('au-delà du reste dû (AT12) : le reste actualisé est dit et la facture relue', async () => {
    const { api, state } = server()
    const { root, unmount } = await openApp(PAGE)
    // Une autre caissière encaisse 40 000 pendant l'affichage (la page montre encore 80 000)…
    state.paid.push({
      id: PAYMENT_ID,
      amount: 40000,
      receiptId: RECEIPT_ID,
      number: 'TEST-REC-2026-000001',
    })
    // … mais la version affichée est encore la première : le serveur répondra d'abord sur la version.
    await typeInto(root, 'encaissement-montant', '40000')
    await selectInto(root, 'encaissement-mode', 'CASH')
    await submitPayment(root)

    expect(textOf(root)).toContain('La facture a changé depuis l’affichage')
    await vi.waitFor(() => expect(textOf(root)).toContain('Reste à encaisser : 40 000 FCFA'))

    // Au-delà des 40 000 restants : refusé avant tout envoi, le reste dû est dit.
    await typeInto(root, 'encaissement-montant', '40001')
    await submitPayment(root)
    expect(textOf(root)).toContain('dépasse le reste à encaisser')
    expect(callsTo(api, 'POST', /payments$/).length).toBeGreaterThanOrEqual(1)

    unmount()
  })

  it('espèces sans caisse ouverte : refus avant envoi et lien vers la caisse', async () => {
    const { api } = server()
    api.on('GET', /^\/api\/v1\/cash-sessions\/current$/, () => json(200, { data: null }))
    const { root, unmount } = await openApp(PAGE)

    await typeInto(root, 'encaissement-montant', '30000')
    await selectInto(root, 'encaissement-mode', 'CASH')
    expect(textOf(root)).toContain('Caisse non ouverte')
    expect(root.querySelector('a[href="/ventes/caisse"]')).not.toBeNull()
    await submitPayment(root)

    expect(callsTo(api, 'POST', /payments$/)).toHaveLength(0)

    unmount()
  })

  it('mobile money : la référence est obligatoire', async () => {
    const { api } = server()
    const { root, unmount } = await openApp(PAGE)

    await typeInto(root, 'encaissement-montant', '30000')
    await selectInto(root, 'encaissement-mode', 'MTN_MOMO')
    await submitPayment(root)

    expect(textOf(root)).toContain('La référence est obligatoire pour ce mode de règlement')
    expect(callsTo(api, 'POST', /payments$/)).toHaveLength(0)

    unmount()
  })

  it('sans droit d’encaisser : la facture se lit, aucun formulaire d’encaissement', async () => {
    const api = stubSalesApi({ permissions: ['invoice.read'] })
    api.on('GET', /^\/api\/v1\/invoices\/([^/]+)$/, () => json(200, { data: issuedInvoice() }))
    const { root, unmount } = await openApp(PAGE)

    expect(textOf(root)).toContain('Facture TEST-FAC-2026-000001')
    expect(root.querySelector('form[aria-labelledby="titre-encaisser"]')).toBeNull()

    unmount()
  })

  it('serveur injoignable : la facture est indisponible, jamais une ancienne copie', async () => {
    const api = stubSalesApi({ permissions: CASHIER })
    api.on('GET', /^\/api\/v1\/invoices\/([^/]+)$/, () => 'network')
    const { root, unmount } = await openApp(PAGE)

    expect(textOf(root)).toContain('Serveur indisponible')
    expect(textOf(root)).not.toContain('Reste à encaisser')

    unmount()
  })
})
