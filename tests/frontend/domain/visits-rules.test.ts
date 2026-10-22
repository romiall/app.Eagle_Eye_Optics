// @vitest-environment node
// Règles pures des visites et de la file du jour (P03 ; fichier 02 « Patients et accueil » ; AT05) :
// motifs, statuts, marqueur du contrôle du paiement de l'examen (inexistant à ce jalon), instant
// d'arrivée, validation de la saisie et charge RegisterVisit validée contre le contrat F06. Données
// FICTIVES.
import { describe, expect, it } from 'vitest'

import { commandContract } from '@/app/services/commandIndex'
import { directBody } from '@/app/services/mutations'
import type { Visit, VisitsStatus } from '@/domain/contracts/generated'
import {
  arrivalFieldFromServer,
  buildRegisterVisitPayload,
  currentInstant,
  examFeeMarker,
  existingVisits,
  isExamReason,
  isVisitReason,
  NOTE_MAX_LENGTH,
  reasonHint,
  reasonLabel,
  shiftDay,
  STATUS_LABELS,
  statusLabel,
  statusSymbol,
  validateArrival,
  VISIT_REASONS,
} from '@/domain/patients/visits'
import { Instant } from '@/domain/values/instant'

import { CONTRACTS_BASE_URI, contractValidator } from '../support/contracts'

const PATIENT = '01J9ZXK4M2Q8R7S6T5V4W30011'
const VISIT = '01J9ZXK4M2Q8R7S6T5V4W30021'
const KEY = '01J9ZXK4M2Q8R7S6T5V4W3K001'
/** Mots qui diraient ce que le contrôle inexistant ne peut pas prouver. */
const FORBIDDEN_WORDS = /pay[ée]|solde|soldé|gratuit/i

describe('motifs de visite', () => {
  it('quatre motifs administratifs, dans l’ordre de la liste, avec leurs libellés exacts', () => {
    expect(VISIT_REASONS).toEqual(['EXAM', 'DIRECT_PURCHASE', 'PICKUP', 'FOLLOW_UP'])
    expect(VISIT_REASONS.map(reasonLabel)).toEqual([
      'Examen de vue',
      'Achat direct',
      'Retrait de commande',
      'Contrôle de suivi',
    ])
  })

  it('reconnaît un motif du contrat et refuse tout autre texte', () => {
    expect(VISIT_REASONS.every(isVisitReason)).toBe(true)
    expect(isVisitReason('exam')).toBe(false)
    expect(isVisitReason('')).toBe(false)
    expect(isVisitReason('__proto__')).toBe(false)
  })

  it('un achat ou un retrait n’impose aucun examen', () => {
    expect(isExamReason('EXAM')).toBe(true)
    expect(isExamReason('FOLLOW_UP')).toBe(true)
    expect(isExamReason('DIRECT_PURCHASE')).toBe(false)
    expect(isExamReason('PICKUP')).toBe(false)
    expect(reasonHint('DIRECT_PURCHASE')).toBe(
      'Aucun examen ni consultation n’est créé pour cette visite.',
    )
    expect(reasonHint('PICKUP')).toBe(reasonHint('DIRECT_PURCHASE'))
  })

  it('l’explication d’un examen dit que le contrôle du paiement n’est pas actif, sans rien affirmer', () => {
    for (const reason of ['EXAM', 'FOLLOW_UP'] as const) {
      expect(reasonHint(reason)).toContain('n’est pas encore actif')
      expect(reasonHint(reason)).toContain('n’est pas bloqué faute de règlement')
      expect(reasonHint(reason)).not.toMatch(FORBIDDEN_WORDS)
    }
  })
})

describe('statuts de visite', () => {
  it('chaque statut du contrat a son libellé exact', () => {
    const labels: Record<VisitsStatus, string> = {
      ARRIVED: 'Arrivé',
      AWAITING_EXAM_FEE: 'En attente du paiement de l’examen',
      READY_FOR_EXAM: 'Prêt pour l’examen',
      IN_CONSULTATION: 'En consultation',
      COMPLETED: 'Terminé',
      CANCELLED: 'Annulé',
    }
    expect(STATUS_LABELS).toEqual(labels)
    for (const [status, label] of Object.entries(labels)) {
      expect(statusLabel(status)).toBe(label)
      expect(statusSymbol(status), status).not.toBe('?')
    }
  })

  it('un statut inconnu de cette version est dit tel quel, jamais deviné ni dit « prêt »', () => {
    expect(statusLabel('ON_HOLD')).toBe('Statut inconnu (ON_HOLD)')
    expect(statusLabel('constructor')).toBe('Statut inconnu (constructor)')
    expect(statusSymbol('ON_HOLD')).toBe('?')
  })

  it('aucun libellé de statut ne dit payé, soldé ou gratuit', () => {
    for (const label of Object.values(STATUS_LABELS)) expect(label).not.toMatch(FORBIDDEN_WORDS)
  })
})

describe('marqueur du contrôle du paiement de l’examen', () => {
  it('examen ou suivi : « Paiement de l’examen non contrôlé », avec un symbole, jamais un état de règlement', () => {
    const marker = examFeeMarker('NOT_CONTROLLED')
    expect(marker).toEqual({
      symbol: '⚠',
      text: 'Paiement de l’examen non contrôlé',
      notControlled: true,
    })
    expect(marker.text).not.toMatch(FORBIDDEN_WORDS)
  })

  it('achat ou retrait : pas d’examen, rien à contrôler', () => {
    const marker = examFeeMarker('NOT_APPLICABLE')
    expect(marker.text).toBe('Pas d’examen')
    expect(marker.notControlled).toBe(false)
    expect(marker.text).not.toMatch(FORBIDDEN_WORDS)
  })
})

describe('instant d’arrivée', () => {
  it('est au format du contrat, en UTC, à la milliseconde', () => {
    expect(currentInstant(Date.UTC(2026, 9, 15, 9, 42, 0, 123))).toBe('2026-10-15T09:42:00.123Z')
    expect(currentInstant(Date.UTC(2026, 11, 31, 23, 59, 59, 999))).toBe('2026-12-31T23:59:59.999Z')
    expect(Instant.parse(currentInstant())?.toString()).toMatch(
      /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/,
    )
  })

  it('sans horloge fournie, c’est l’instant courant : jamais dans le futur', () => {
    const before = Date.now()
    const instant = Instant.parse(currentInstant())
    expect(instant?.epochMilliseconds ?? 0).toBeGreaterThanOrEqual(before)
    expect(instant?.epochMilliseconds ?? Number.MAX_SAFE_INTEGER).toBeLessThanOrEqual(Date.now())
  })
})

describe('jour voisin', () => {
  it('passe au jour précédent et suivant, mois, année et 29 février compris', () => {
    expect(shiftDay('2026-10-15', 1)).toBe('2026-10-16')
    expect(shiftDay('2026-10-15', -1)).toBe('2026-10-14')
    expect(shiftDay('2026-10-31', 1)).toBe('2026-11-01')
    expect(shiftDay('2026-01-01', -1)).toBe('2025-12-31')
    expect(shiftDay('2028-02-28', 1)).toBe('2028-02-29')
    expect(shiftDay('2026-02-28', 1)).toBe('2026-03-01')
  })

  it('aux bornes du calendrier admis, ne change pas de jour au lieu d’inventer une date', () => {
    expect(shiftDay('9999-12-31', 1)).toBeNull()
    expect(shiftDay('1000-01-01', -1)).toBeNull()
  })
})

describe('autres visites du même patient', () => {
  const visits = [
    { id: 'a', patient_id: PATIENT, status: 'ARRIVED' },
    { id: 'b', patient_id: PATIENT, status: 'CANCELLED' },
    { id: 'c', patient_id: 'autre', status: 'ARRIVED' },
    { id: 'd', patient_id: PATIENT, status: 'IN_CONSULTATION' },
  ] as unknown as Visit[]

  it('compte les visites du patient, hors visites annulées', () => {
    expect(existingVisits(visits, PATIENT).map((visit) => visit.id)).toEqual(['a', 'd'])
    expect(existingVisits(visits, 'inconnu')).toEqual([])
    expect(existingVisits([], PATIENT)).toEqual([])
  })
})

describe('validation de la saisie d’une arrivée', () => {
  it('exige un patient et un motif, et signale les deux ensemble', () => {
    const result = validateArrival({ patientId: null, reason: null, note: '' })
    expect(result).toEqual({
      ok: false,
      errors: {
        patient: 'Choisissez le patient dans la liste des résultats.',
        reason: 'Choisissez le motif de la visite.',
      },
    })
  })

  it('une saisie complète est acceptée ; la note vide ou blanche devient null, jamais une chaîne vide', () => {
    for (const note of ['', '   ', '\n\t ']) {
      const result = validateArrival({ patientId: PATIENT, reason: 'DIRECT_PURCHASE', note })
      expect(result).toEqual({
        ok: true,
        values: { patient_id: PATIENT, reason: 'DIRECT_PURCHASE', administrative_note: null },
      })
    }
  })

  it('la note est conservée sans ses blancs de bord', () => {
    const result = validateArrival({
      patientId: PATIENT,
      reason: 'EXAM',
      note: '  accompagné d’un parent \n',
    })
    expect(result.ok && result.values.administrative_note).toBe('accompagné d’un parent')
  })

  it('refuse une note trop longue avec un message, sans la tronquer', () => {
    const result = validateArrival({
      patientId: PATIENT,
      reason: 'EXAM',
      note: 'a'.repeat(NOTE_MAX_LENGTH + 1),
    })
    expect(result).toEqual({
      ok: false,
      errors: { note: `La note ne peut pas dépasser ${NOTE_MAX_LENGTH} caractères.` },
    })
    expect(
      validateArrival({ patientId: PATIENT, reason: 'EXAM', note: 'a'.repeat(NOTE_MAX_LENGTH) }).ok,
    ).toBe(true)
  })
})

describe('erreurs du serveur par champ', () => {
  it('rattache chaque clé de error.fields au champ de l’écran', () => {
    expect(arrivalFieldFromServer('patient_id')).toBe('patient')
    expect(arrivalFieldFromServer('reason')).toBe('reason')
    expect(arrivalFieldFromServer('administrative_note')).toBe('note')
    for (const key of ['visit_id', 'occurred_at', 'appointment_id', 'expected_version', 'autre']) {
      expect(arrivalFieldFromServer(key), key).toBeNull()
    }
  })
})

describe('charge RegisterVisit : conforme au contrat F06', () => {
  const ajv = contractValidator()
  const values = { patient_id: PATIENT, reason: 'EXAM', administrative_note: null } as const

  it('porte les six champs du contrat, aucun rendez-vous lié, note null', () => {
    const payload = buildRegisterVisitPayload(
      values,
      { visitId: VISIT },
      '2026-10-15T09:42:00.123Z',
    )
    expect(payload).toEqual({
      visit_id: VISIT,
      patient_id: PATIENT,
      reason: 'EXAM',
      occurred_at: '2026-10-15T09:42:00.123Z',
      appointment_id: null,
      administrative_note: null,
    })
  })

  it('le corps de la route directe est accepté par le DirectRequest, expected_version à null', () => {
    const validate = ajv.getSchema(
      `${CONTRACTS_BASE_URI}commands/RegisterVisit.v1.json#/$defs/DirectRequest`,
    )
    if (validate === undefined) throw new Error('DirectRequest de RegisterVisit absent')
    for (const reason of VISIT_REASONS) {
      for (const note of [null, 'Accompagné d’un parent']) {
        const payload = buildRegisterVisitPayload(
          { patient_id: PATIENT, reason, administrative_note: note },
          { visitId: VISIT },
          '2026-10-15T09:42:00.123Z',
        )
        const body = directBody(commandContract('RegisterVisit'), {
          name: 'RegisterVisit',
          payload,
          idempotencyKey: KEY,
        })
        expect(validate(body), JSON.stringify(validate.errors ?? [])).toBe(true)
        expect(body['expected_version']).toBeNull()
      }
    }
  })

  it('une note vide envoyée comme chaîne vide serait refusée : la charge n’en produit jamais', () => {
    const checked = validateArrival({ patientId: PATIENT, reason: 'PICKUP', note: '  ' })
    expect(checked.ok && checked.values.administrative_note).toBeNull()
  })
})
