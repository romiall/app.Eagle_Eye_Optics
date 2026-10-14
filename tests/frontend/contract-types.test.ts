// @vitest-environment node
// Les types générés depuis les contrats (resources/frontend/domain/contracts/generated.ts) décrivent les
// fixtures acceptées : vérification à la compilation (vue-tsc, TypeScript strict) et à l'exécution.
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, expectTypeOf, it } from 'vitest'

import type {
  CommandCore,
  CommandName,
  EventEnvelope,
  InvoiceResponse,
  ReceiptSnapshot,
  RegisterPaymentDirectRequest,
  RegisterPaymentPayload,
} from '@/domain/contracts/generated'

const FIXTURES = join(import.meta.dirname, '..', '..', 'contracts', 'fixtures')
const fixture = <T>(path: string): T =>
  (JSON.parse(readFileSync(join(FIXTURES, path), 'utf8')) as { data: T }).data

describe('types TypeScript générés depuis les contrats', () => {
  it("typent une charge d'encaissement et sa requête directe", () => {
    const payload = fixture<RegisterPaymentPayload>(
      'commands/register-payment.mobile-money.valid.json',
    )
    const direct = fixture<RegisterPaymentDirectRequest>(
      'commands/register-payment.direct.valid.json',
    )

    expect(payload.amount).toBe(30000)
    expect(direct.receipt_number).toBeNull()
    expectTypeOf(payload.amount).toEqualTypeOf<number>()
    expectTypeOf(direct.receipt_number).toEqualTypeOf<null>()
    expectTypeOf(direct.expected_version).toEqualTypeOf<number>()
  })

  it("typent le cœur signé, la facture, le reçu et l'événement", () => {
    const core = fixture<{ commands: { core: CommandCore }[] }>('sync/push-request.valid.json')
      .commands[0]?.core
    const invoice = fixture<InvoiceResponse>('resources/invoice.issued.valid.json')
    const receipt = fixture<ReceiptSnapshot>('snapshots/receipt.deposit.valid.json')
    const event = fixture<EventEnvelope>('events/payment-confirmed.valid.json')

    expect(core?.command_name).toBe('CreatePatient')
    expect(invoice.data.ledger_version).toBe(2)
    expect(receipt.kind).toBe('DEPOSIT')
    expect(event.event_type).toBe('PaymentConfirmed')
    expectTypeOf<CommandCore['command_name']>().toEqualTypeOf<CommandName>()
    expectTypeOf<CommandCore['device_sequence']>().toEqualTypeOf<string>()
    expectTypeOf<InvoiceResponse['data']['draft_version']>().toEqualTypeOf<number>()
  })
})
