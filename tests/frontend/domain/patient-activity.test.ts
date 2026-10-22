// @vitest-environment node
import { describe, expect, it } from 'vitest'

import {
  ACTIVITY_SECTIONS,
  isActivitySection,
  itemDetails,
  itemKey,
  itemTitle,
  methodLabel,
  sectionOf,
  SECTION_EMPTY,
  settlementText,
} from '@/domain/patients/activity'

import {
  consultationItem,
  invoiceItem,
  medicationItem,
  prescriptionItem,
  receiptItem,
  visitItem,
} from '../patients/activity-support'

// Activité d'un patient : libellés purs. Aucune valeur clinique n'est disponible ni inventée ; une facture dit
// son solde projeté, jamais « payée ». Données FICTIVES.
const text = (lines: string[]): string => lines.join(' | ').replace(/\s+/g, ' ')

describe('activité du patient : règles de présentation', () => {
  it('range chaque type dans sa section et donne une clé distincte à l’examen et à son ordonnance', () => {
    expect(ACTIVITY_SECTIONS).toEqual(['VISITS', 'EXAMS', 'PRESCRIPTIONS', 'FINANCIAL'])
    expect(sectionOf(visitItem())).toBe('VISITS')
    expect(sectionOf(consultationItem())).toBe('EXAMS')
    expect(sectionOf(prescriptionItem())).toBe('PRESCRIPTIONS')
    expect(sectionOf(medicationItem())).toBe('PRESCRIPTIONS')
    expect(sectionOf(invoiceItem())).toBe('FINANCIAL')
    expect(sectionOf(receiptItem())).toBe('FINANCIAL')
    expect(itemKey(consultationItem())).not.toBe(itemKey(medicationItem()))
    expect(isActivitySection('VISITS')).toBe(true)
    expect(isActivitySection('CLINICAL')).toBe(false)
    expect(isActivitySection(null)).toBe(false)
    expect(SECTION_EMPTY.VISITS).toBe('Aucune visite enregistrée')
  })

  it('titre et détails : date, état, professionnel, type et version, jamais une valeur clinique', () => {
    expect(itemTitle(visitItem({ reason: 'EXAM' }))).toBe('Visite : Examen de vue')
    expect(text(itemDetails(visitItem({ status: 'IN_CONSULTATION' })))).toBe(
      'Statut : En consultation',
    )
    expect(itemTitle(consultationItem())).toBe('Examen de vue')
    expect(itemDetails(consultationItem({ status: 'IN_PROGRESS' }))).toEqual([
      'État : En cours',
      'Professionnel : Professionnel Fictif',
    ])
    expect(itemTitle(prescriptionItem())).toBe('Prescription optique, version 2')
    expect(itemDetails(prescriptionItem({ status: 'DRAFT' }))).toEqual(['État : Brouillon'])
    const external = prescriptionItem({
      origin: 'EXTERNAL',
      version_no: 1,
      consultation_id: null,
      external_prescribed_on: '2026-09-30',
    })
    expect(itemTitle(external)).toBe('Ordonnance externe transcrite, version 1')
    expect(itemDetails(external)).toEqual([
      'État : Validée',
      'Prescrite à l’extérieur le 30/09/2026',
    ])
    expect(itemTitle(medicationItem())).toBe('Ordonnance de médicaments')
    expect(itemDetails(medicationItem({ consultation_status: 'DRAFT' }))).toEqual([
      'Consultation : Brouillon',
    ])
  })

  it('une facture dit son solde projeté, jamais « payée »', () => {
    const partial = invoiceItem()
    expect(itemTitle(partial)).toBe('Facture TEST-FAC-000001')
    expect(text(itemDetails(partial))).toBe(
      'Montant : 50 000 FCFA | Reste dû : 30 000 FCFA (encaissé : 20 000 FCFA)',
    )
    expect(text([settlementText(invoiceItem({ settlement: 'UNPAID', paid_net: 0 }))])).toBe(
      'Reste dû : 30 000 FCFA (aucun encaissement net)',
    )
    expect(text([settlementText(invoiceItem({ settlement: 'SETTLED', balance_due: 0 }))])).toBe(
      'Solde nul : reste dû 0 FCFA',
    )
    expect(
      text([
        settlementText(
          invoiceItem({ settlement: 'IN_CREDIT', balance_due: -3000, credit_available: 3000 }),
        ),
      ]),
    ).toBe('Crédit à rembourser : 3 000 FCFA')
    expect(text(itemDetails(invoiceItem({ origin: 'LEGACY_BALANCE' })))).toContain(
      'Créance reprise du papier',
    )
    for (const settlement of ['UNPAID', 'PARTIALLY_PAID', 'SETTLED', 'IN_CREDIT'] as const) {
      expect(settlementText(invoiceItem({ settlement }))).not.toMatch(/pay[ée]|soldée|gratuit/i)
    }
  })

  it('un reçu dit son encaissement, son mode et une contre-passation éventuelle', () => {
    expect(itemTitle(receiptItem())).toBe('Reçu TEST-REC-000001')
    expect(text(itemDetails(receiptItem()))).toBe(
      'Encaissement : 20 000 FCFA (MTN Mobile Money) | Facture TEST-FAC-000001',
    )
    expect(text(itemDetails(receiptItem({ reversed_amount: 20000, invoice_number: null })))).toBe(
      'Encaissement : 20 000 FCFA (MTN Mobile Money) | Facture sans numéro | Contre-passé : 20 000 FCFA (le reçu reste conservé)',
    )
    expect(methodLabel('CASH')).toBe('Espèces')
    expect(methodLabel('CRYPTO')).toBe('Mode inconnu (CRYPTO)')
  })
})
