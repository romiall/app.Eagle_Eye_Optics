// @vitest-environment node
import { describe, expect, it } from 'vitest'

import {
  demoAccountStatement,
  demoCreditNote,
  demoRefund,
} from '@/documents/demo/fixtures'
import { NBSP } from '@/documents/engine/charset'
import { DocumentDataError } from '@/documents/engine/errors'
import { buildAccountStatement } from '@/documents/templates/credits/accountStatement'
import { buildCreditNote } from '@/documents/templates/credits/creditNote'
import { buildRefund } from '@/documents/templates/credits/refund'

import { FORBIDDEN_WORDS, layout, outOfBounds, printedText } from './support'

const FCFA = (amount: string): string => `${amount.replace(/ /g, NBSP)}${NBSP}FCFA`

function failure(action: () => unknown): DocumentDataError {
  try {
    action()
  } catch (error) {
    if (error instanceof DocumentDataError) return error
    throw error
  }
  throw new Error('Une DocumentDataError était attendue.')
}

describe('avoir (fichier 11)', () => {
  const model = buildCreditNote(demoCreditNote())
  const plan = layout(model)
  const text = printedText(plan)

  it('imprime numéro, facture source, date, lignes et montants corrigés, motif, auteur', () => {
    expect(text).toContain('AVOIR')
    expect(text).toContain('Numéro | TEST-AV-000001')
    expect(text).toContain('Date d’émission | 20/10/2026')
    expect(text).toContain('Facture d’origine | TEST-FAC-000001')
    expect(text).toContain('Avoir établi pour | PATIENT FICTIF Élodie Marie')
    expect(text).toContain('Émise le | 14/10/2026')
    expect(text).toContain(`Total de la facture | ${FCFA('80 000')}`)
    expect(text).toContain('Motif | Geste commercial de démonstration (motif fictif).')
    expect(text).toContain('Désignation corrigée | Quantité | Montant crédité')
    expect(text).toContain(
      `Verres progressifs 1.60 (fictifs) : correction commerciale | \u{2014} | 10${NBSP}000`,
    )
    expect(text).toContain(`Montant de l’avoir | ${FCFA('10 000')}`)
    expect(text).toContain('Établi par | Gérante (démonstration)')
  })

  it('ne cite de l’autorisation de la gérante qu’une référence courte, jamais la décision', () => {
    expect(text).toContain('Autorisation de la gérante enregistrée (réf. V4W3002Z)')
    expect(text).not.toContain('01J9ZXK4M2Q8R7S6T5V4W3002Z')
  })

  it('dit qu’un avoir n’est pas un remboursement et reste un document de test marqué', () => {
    expect(text).toContain('Cet avoir réduit la créance de la facture indiquée')
    expect(text).toContain('DOCUMENT DE TEST')
    expect(model.watermark).toBe('DONNÉES DE TEST')
    expect(text).toContain('Régime de taxe non configuré')
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern)
    expect(outOfBounds(plan)).toEqual([])
  })

  it('refuse un avoir dont le montant diffère de la somme des lignes, nul, sans ligne ou sans motif', () => {
    const note = demoCreditNote()
    expect(failure(() => buildCreditNote({ ...note, amountGross: 9000 })).code).toBe(
      'credit_note.total_mismatch',
    )
    expect(
      failure(() => buildCreditNote({ ...note, amountGross: 0, lines: [] })).code,
    ).toBe('amount.not_positive')
    expect(failure(() => buildCreditNote({ ...note, lines: [] })).code).toBe('credit_note.no_lines')
    expect(failure(() => buildCreditNote({ ...note, reason: '  ' })).code).toBe('text.required')
  })

  it('une réimpression est un duplicata marqué, avec le même numéro', () => {
    const copy = buildCreditNote(demoCreditNote(), {
      copy: { kind: 'DUPLICATA', reprintNumber: 1, reprintedAt: '2026-10-22T08:30:00.000Z' },
    })

    expect(copy.watermark).toBe('DUPLICATA')
    expect(copy.meta.documentNumber).toBe('TEST-AV-000001')
  })
})

describe('reçu de remboursement (fichier 11)', () => {
  const plan = layout(buildRefund(demoRefund()))
  const text = printedText(plan)

  it('imprime numéro, avoir et facture, bénéficiaire, montant, mode et auteur', () => {
    expect(text).toContain('REÇU DE REMBOURSEMENT')
    expect(text).toContain('Numéro | TEST-RMB-000001')
    expect(text).toContain('Date | 20/10/2026 à 11h30')
    expect(text).toContain('Bénéficiaire | PATIENT FICTIF Élodie Marie')
    expect(text).toContain('Avoir n° | TEST-AV-000001')
    expect(text).toContain('Facture n° | TEST-FAC-000001')
    expect(text).toContain(`Montant remboursé | ${FCFA('10 000')}`)
    expect(text).toContain('Mode de remboursement | Espèces')
    expect(text).toContain('Remboursement enregistré par | Gérante (démonstration)')
    expect(text).not.toContain('Référence |')
  })

  it('porte deux cadres de signature et précise que la décision d’approbation n’y figure pas', () => {
    expect(text).toContain('Signature du bénéficiaire')
    expect(text).toContain('Signature ou cachet du cabinet')
    expect(text).toContain('la décision d’approbation n’y figure pas')
    expect(outOfBounds(plan)).toEqual([])
  })

  it('imprime la référence d’un remboursement par mobile money', () => {
    const rendered = printedText(
      layout(buildRefund({ ...demoRefund(), method: 'MTN_MOMO', reference: 'REFERENCE_FICTIVE' })),
    )

    expect(rendered).toContain('Mode de remboursement | Mobile money MTN')
    expect(rendered).toContain('Référence | REFERENCE_FICTIVE')
  })

  it('refuse un montant nul et un numéro vide', () => {
    expect(failure(() => buildRefund({ ...demoRefund(), amount: 0 })).code).toBe(
      'amount.not_positive',
    )
    expect(failure(() => buildRefund({ ...demoRefund(), number: '' })).code).toBe('text.required')
  })
})

describe('état de compte (fichier 11)', () => {
  const model = buildAccountStatement(demoAccountStatement())
  const plan = layout(model)
  const text = printedText(plan)

  it('est daté : date de calcul, établi par, mention d’état non permanent', () => {
    expect(text).toContain('ÉTAT DE COMPTE')
    expect(text).toContain('Arrêté au 22/10/2026 à 09h15')
    expect(text).toContain('Calculé le | 22/10/2026 à 09h15')
    expect(text).toContain('Établi par | Caisse (démonstration)')
    expect(text).toContain('État daté : il reflète les pièces enregistrées à la date de calcul')
    expect(model.meta.documentNumber).toBeNull()
  })

  it('liste les factures avec « reste à payer » et « crédit à rembourser » en colonnes séparées', () => {
    expect(text).toContain('Reste à payer')
    expect(text).toContain('Crédit à rembourser')
    expect(text).toContain(
      `TEST-FAC-000001 | 14/10/2026 | 80${NBSP}000 | 0 | 30${NBSP}000 | 0 | 50${NBSP}000 | 0`,
    )
    expect(text).toContain(
      `TEST-FAC-000003 | 18/10/2026 | 40${NBSP}000 | 10${NBSP}000 | 40${NBSP}000 | 0 | 0 | 10${NBSP}000`,
    )
  })

  it('chaque encaissement garde la date de son reçu (jamais « reçu aujourd’hui »)', () => {
    expect(text).toContain(
      `TEST-REC-000001 | 14/10/2026 à 10h30 | TEST-FAC-000001 | Mobile money MTN | 30${NBSP}000`,
    )
    expect(text).toContain(
      `TEST-REC-000003 | 18/10/2026 à 12h05 | TEST-FAC-000003 | Espèces | 40${NBSP}000`,
    )
  })

  it('présente la créance d’ouverture papier à part', () => {
    expect(text).toContain('TEST-OUV-000001')
    expect(text).toContain('(créance')
    expect(text).toContain('Une créance d’ouverture est reprise d’un carnet papier')
  })

  it('totalise exactement : reste à payer et crédit à rembourser ne se compensent pas', () => {
    expect(text).toContain(`Total facturé | ${FCFA('240 000')}`)
    expect(text).toContain(`Avoirs émis | ${FCFA('10 000')}`)
    expect(text).toContain(`Encaissé net | ${FCFA('70 000')}`)
    expect(text).toContain(`Remboursé | ${FCFA('0')}`)
    expect(text).toContain(`Reste à payer | ${FCFA('170 000')}`)
    expect(text).toContain(`Crédit à rembourser | ${FCFA('10 000')}`)
  })

  it('liste les avoirs émis et n’affiche pas de section vide', () => {
    expect(text).toContain('Avoirs')
    expect(text).toContain(`TEST-AV-000002 | 19/10/2026 | TEST-FAC-000003 | 10${NBSP}000`)
    expect(text).not.toContain('Remboursements')
  })

  it('refuse un solde qui ne suit pas total − avoirs − encaissé + remboursé', () => {
    const statement = demoAccountStatement()
    const invoices = statement.invoices.map((invoice, index) =>
      index === 0 ? { ...invoice, balance: 49000 } : invoice,
    )

    expect(failure(() => buildAccountStatement({ ...statement, invoices })).code).toBe(
      'statement.balance_mismatch',
    )
  })

  it('reste en A4 et tient dans les marges', () => {
    expect(buildAccountStatement(demoAccountStatement(), { format: 'TICKET_80' }).format).toBe('A4')
    expect(outOfBounds(plan)).toEqual([])
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern)
  })

  it('un état de compte vide reste un document valide (aucune section inventée)', () => {
    const empty = buildAccountStatement({
      ...demoAccountStatement(),
      invoices: [],
      payments: [],
      creditNotes: [],
      refunds: [],
    })
    const emptyPlan = layout(empty)
    const rendered = printedText(emptyPlan)

    expect(rendered).toContain(`Total facturé | ${FCFA('0')}`)
    expect(rendered).toContain(`Reste à payer | ${FCFA('0')}`)
    expect(emptyPlan.warnings.join('\n')).toContain('ne contient aucune ligne')
  })
})
