import { describe, expect, it } from 'vitest'

import {
  buildDecision,
  buildRequestApprovalPayload,
  creditNoteSubject,
  describeSubject,
  draftLineRightsSubject,
  examFeeWaiverSubject,
  isDecidable,
  KIND_LABELS,
  kindLabel,
  paymentReversalSubject,
  refundSubject,
  STATUS_LABELS,
  statusLabel,
  validateRequestReason,
} from '@/domain/approvals/approvals'
import { RuleViolation } from '@/domain/errors'
import { examFeeMarker, reasonHint } from '@/domain/patients/visits'

// Approbations exactes (B06) et marqueur du paiement de l'examen (P03) : règles pures. Aucun texte ne dit
// « payé », « soldé » ni « gratuit » ; une dispense n'est qu'une autorisation de démarrer l'examen.
// Données FICTIVES.
const FORBIDDEN = /pay[ée]|sold[ée]|gratuit/i
const VISIT = '01J9ZXK4M2Q8R7S6T5V4W30021'
const PATIENT = '01J9ZXK4M2Q8R7S6T5V4W30011'
const INVOICE = '01J9ZXK4M2Q8R7S6T5V4W30026'
const LINE = '01J9ZXK4M2Q8R7S6T5V4W30027'

describe('contenu exact des opérations à approuver', () => {
  it('une dispense porte la visite et le patient, rien d’autre', () => {
    expect(examFeeWaiverSubject(VISIT, PATIENT)).toEqual({
      kind: 'EXAM_FEE_WAIVER',
      visit_id: VISIT,
      patient_id: PATIENT,
    })
  })

  it('les lignes d’un brouillon sont reprises sans leurs identifiants', () => {
    const subject = draftLineRightsSubject(INVOICE, PATIENT, [
      {
        line_id: '01J9ZXK4M2Q8R7S6T5V4W30040',
        position: 1,
        catalog_item_id: null,
        description: 'Ligne libre fictive',
        quantity: '1',
        unit_price_gross: 15000,
        discount_gross: 3000,
        prescription_id: null,
      },
    ])
    expect(subject.lines[0]).toEqual({
      position: 1,
      catalog_item_id: null,
      description: 'Ligne libre fictive',
      quantity: '1',
      unit_price_gross: 15000,
      discount_gross: 3000,
      prescription_id: null,
    })
    expect(() => draftLineRightsSubject(INVOICE, PATIENT, [])).toThrow(RuleViolation)
  })

  it('un avoir porte la somme exacte de ses lignes', () => {
    const subject = creditNoteSubject(INVOICE, [
      { invoice_line_id: LINE, quantity: null, amount_gross: 10000 },
      { invoice_line_id: '01J9ZXK4M2Q8R7S6T5V4W30028', quantity: '1', amount_gross: 5000 },
    ])
    expect(subject.amount_gross).toBe(15000)
    expect(() => creditNoteSubject(INVOICE, [])).toThrow(RuleViolation)
  })

  it('la charge de la demande ne porte ni empreinte ni cible fournies par l’écran', () => {
    const payload = buildRequestApprovalPayload(
      '01J9ZXK4M2Q8R7S6T5V4W30036',
      paymentReversalSubject(INVOICE, LINE, 30000),
      'Paiement saisi deux fois (fictif)',
    )
    expect(Object.keys(payload).sort()).toEqual(['approval_id', 'expires_at', 'reason', 'subject'])
    expect(payload.expires_at).toBeNull()
  })
})

describe('motif et décision', () => {
  it('le motif de la demande est obligatoire et borné', () => {
    expect(validateRequestReason('  ')).toEqual({
      ok: false,
      error: 'Indiquez le motif de la demande.',
    })
    expect(validateRequestReason('ab').ok).toBe(false)
    expect(validateRequestReason('x'.repeat(1001)).ok).toBe(false)
    expect(validateRequestReason('  Motif fictif  ')).toEqual({ ok: true, reason: 'Motif fictif' })
  })

  it('un refus exige un motif, un accord l’admet facultatif', () => {
    expect(buildDecision(VISIT, 'REFUSED', ' ').ok).toBe(false)
    expect(buildDecision(VISIT, 'APPROVED', '')).toEqual({
      ok: true,
      payload: { approval_id: VISIT, decision: 'APPROVED', reason: null },
    })
    expect(buildDecision(VISIT, 'REFUSED', 'Non justifié (fictif)')).toEqual({
      ok: true,
      payload: { approval_id: VISIT, decision: 'REFUSED', reason: 'Non justifié (fictif)' },
    })
  })

  it('seule une demande en attente au statut effectif se décide', () => {
    expect(isDecidable({ effective_status: 'REQUESTED' })).toBe(true)
    for (const status of ['APPROVED', 'REFUSED', 'EXPIRED', 'CONSUMED'] as const) {
      expect(isDecidable({ effective_status: status })).toBe(false)
    }
  })

  it('aucun libellé ne dit payé, soldé ou gratuit, et un statut inconnu est dit tel quel', () => {
    for (const label of [...Object.values(STATUS_LABELS), ...Object.values(KIND_LABELS)]) {
      expect(label).not.toMatch(FORBIDDEN)
    }
    expect(statusLabel('LOST')).toBe('Statut inconnu (LOST)')
    expect(kindLabel(null)).toBe('Autorisation directe de la gérante')
  })

  it('le contenu non vérifiable n’est jamais présenté comme exact', () => {
    const money = (amount: number) => `${amount} FCFA`
    expect(describeSubject(null, money, (method) => method)[0]?.value).toContain('non vérifiable')
    expect(
      describeSubject(refundSubject(INVOICE, LINE, 5000, 'MTN_MOMO'), money, () => 'MTN MoMo'),
    ).toEqual([
      { label: 'Montant remboursé', value: '5000 FCFA' },
      { label: 'Mode', value: 'MTN MoMo' },
    ])
  })
})

describe('marqueur du paiement de l’examen, contrôle actif', () => {
  it('à encaisser, dispense demandée, encaissés avec le reçu réel, dispense approuvée', () => {
    expect(examFeeMarker('UNPAID')).toMatchObject({
      text: 'Frais d’examen à encaisser',
      pending: true,
    })
    expect(examFeeMarker('WAIVER_REQUESTED').text).toContain('en attente de la gérante')
    expect(examFeeMarker('PAID', 'TEST-REC-2026-000004').text).toBe(
      'Frais d’examen encaissés : reçu n° TEST-REC-2026-000004',
    )
    expect(examFeeMarker('PAID').text).toBe('Frais d’examen encaissés')
    expect(examFeeMarker('WAIVED').text).toContain('Dispense approuvée')
    for (const control of ['UNPAID', 'WAIVER_REQUESTED', 'PAID', 'WAIVED'] as const) {
      const marker = examFeeMarker(control, 'TEST-REC-2026-000004')
      expect(marker.text).not.toMatch(FORBIDDEN)
      expect(marker.notControlled).toBe(false)
      expect(marker.symbol).not.toBe('')
    }
  })

  it('contrôle inactif : marqueur et explication inchangés', () => {
    expect(examFeeMarker('NOT_CONTROLLED')).toEqual({
      symbol: '⚠',
      text: 'Paiement de l’examen non contrôlé',
      notControlled: true,
    })
    expect(reasonHint('EXAM')).toContain('n’est pas encore actif')
    expect(reasonHint('EXAM', true)).toContain('à encaisser avant le démarrage de l’examen')
    expect(reasonHint('EXAM', true)).not.toMatch(FORBIDDEN)
    expect(reasonHint('DIRECT_PURCHASE', true)).toBe(reasonHint('DIRECT_PURCHASE'))
  })
})
