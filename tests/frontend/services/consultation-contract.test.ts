// @vitest-environment node
import { describe, expect, it } from 'vitest'

import { commandContract, commandPath } from '@/app/services/commandIndex'
import { directBody } from '@/app/services/mutations'
import { buildSaveStepPayload, buildStartConsultationPayload } from '@/domain/clinical/payload'
import {
  blankAnamnesis,
  blankConclusion,
  blankHistories,
  setOtherText,
  type StepDrafts,
  toggleTerm,
  type TermRow,
  validateStep,
} from '@/domain/clinical/steps'
import { newUlid } from '@/domain/values/ulid'

import { CONTRACTS_BASE_URI, contractValidator } from '../support/contracts'

/**
 * Les corps que l'écran envoie à `StartConsultation` et `SaveClinicalStep` sont validés contre le
 * `DirectRequest` du contrat F06 (Ajv, mode strict, `additionalProperties: false` partout) : une clé
 * de trop ou manquante, un type faux ou une énumération inconnue ferait un 422 au poste de travail.
 * Données FICTIVES.
 */
const ajv = contractValidator()

function validator(name: string) {
  const validate = ajv.getSchema(
    `${CONTRACTS_BASE_URI}commands/${name}.v1.json#/$defs/DirectRequest`,
  )
  expect(validate, `DirectRequest de ${name}`).toBeTypeOf('function')
  return validate as NonNullable<typeof validate>
}

function rows(group: Parameters<typeof toggleTerm>[0], ...codes: string[]): TermRow[] {
  return codes.reduce<TermRow[]>((all, code) => toggleTerm(group, all, code, true), [])
}

const CONSULTATION = newUlid()

function saveBody(
  step: 'ANAMNESIS' | 'HISTORIES' | 'CONCLUSION',
  data: object,
): Record<string, unknown> {
  return directBody(commandContract('SaveClinicalStep'), {
    name: 'SaveClinicalStep',
    path: { id: CONSULTATION, step },
    payload: buildSaveStepPayload(CONSULTATION, step, data as never),
    expectedVersion: 3,
    idempotencyKey: newUlid(),
  })
}

describe('contrat des commandes de consultation', () => {
  it('routes, droits et version attendue de StartConsultation et SaveClinicalStep', () => {
    const start = commandContract('StartConsultation')
    expect([start.method, start.path, start.permission, start.expectedVersion]).toEqual([
      'POST',
      '/api/v1/visits/{id}/consultations',
      'clinical.write',
      'none',
    ])
    const save = commandContract('SaveClinicalStep')
    expect([save.method, save.path, save.permission, save.expectedVersion]).toEqual([
      'PATCH',
      '/api/v1/consultations/{id}/steps/{step}',
      'clinical.write',
      'entity',
    ])
    expect(commandPath(save, { id: CONSULTATION, step: 'HISTORIES' })).toBe(
      `/api/v1/consultations/${CONSULTATION}/steps/HISTORIES`,
    )
  })
})

describe('StartConsultation : corps du bouton « Démarrer l’examen »', () => {
  it('est accepté par le DirectRequest (expected_version null, aucune preuve de paiement)', () => {
    const payload = buildStartConsultationPayload(
      { id: newUlid(), patient_id: newUlid() },
      newUlid(),
      '2026-10-22T09:42:00.000Z',
    )
    const body = directBody(commandContract('StartConsultation'), {
      name: 'StartConsultation',
      path: { id: payload.visit_id },
      payload,
      idempotencyKey: newUlid(),
    })
    const validate = validator('StartConsultation')

    expect(body['expected_version']).toBeNull()
    expect(validate(body), JSON.stringify(validate.errors)).toBe(true)
  })

  it('un instant mal formé ou une clé en trop serait refusé (le validateur est bien appliqué)', () => {
    const payload = buildStartConsultationPayload(
      { id: newUlid(), patient_id: newUlid() },
      newUlid(),
      '2026-10-22T09:42:00.000Z',
    )
    const validate = validator('StartConsultation')
    const base = { ...payload, expected_version: null }
    expect(validate({ ...base, effective_at: '22/10/2026' })).toBe(false)
    expect(validate({ ...base, clinician_id: newUlid() })).toBe(false)
  })
})

describe('SaveClinicalStep : corps de chaque étape livrée', () => {
  const accepted: [string, 'ANAMNESIS' | 'HISTORIES' | 'CONCLUSION', object][] = []

  function add<S extends 'ANAMNESIS' | 'HISTORIES' | 'CONCLUSION'>(
    name: string,
    step: S,
    draft: StepDrafts[S],
  ): void {
    const result = validateStep(step, draft)
    expect(result.ok, name).toBe(true)
    if (result.ok) accepted.push([name, step, result.data])
  }

  add('anamnèse vierge', 'ANAMNESIS', blankAnamnesis())
  add('anamnèse complète', 'ANAMNESIS', {
    complaints: setOtherText(rows('COMPLAINT', 'PAIN', 'OTHER', 'BLURRED_VISION'), 'OTHER', 'Gêne'),
    symptoms: 'Vision floue le soir',
    wearsGlasses: true,
    glassesDuration: '2 ans',
    previousCorrectionAvailable: false,
  })
  add('anamnèse sans lunettes', 'ANAMNESIS', {
    ...blankAnamnesis(),
    wearsGlasses: false,
    glassesDuration: 'texte caché',
    previousCorrectionAvailable: true,
  })
  add('antécédents vierges', 'HISTORIES', blankHistories())
  add(
    'antécédents : Aucun connu et valeurs',
    'HISTORIES',
    (() => {
      const draft = blankHistories()
      draft.OCULAR_HISTORY = { ...draft.OCULAR_HISTORY, knownNone: true }
      draft.MEDICAL_HISTORY = {
        ...draft.MEDICAL_HISTORY,
        terms: setOtherText(rows('MEDICAL_HISTORY', 'DIABETES', 'OTHER'), 'OTHER', 'Asthme'),
        comments: 'Suivi',
      }
      draft.FAMILY_HISTORY = {
        ...draft.FAMILY_HISTORY,
        terms: rows('FAMILY_HISTORY', 'HIGH_MYOPIA'),
      }
      return draft
    })(),
  )
  add('conclusion vierge', 'CONCLUSION', blankConclusion())
  add('conclusion avec orientation', 'CONCLUSION', {
    conclusion: 'À orienter',
    referral: true,
    urgency: 'EMERGENCY',
    reasons: setOtherText(rows('REFERRAL_REASON', 'SUSPECTED_GLAUCOMA', 'OTHER'), 'OTHER', 'Autre'),
  })
  add('conclusion sans orientation', 'CONCLUSION', {
    ...blankConclusion(),
    referral: false,
    urgency: 'RAPID',
    reasons: rows('REFERRAL_REASON', 'TRAUMA'),
  })

  it.each(accepted)('%s : corps accepté par le DirectRequest', (_name, step, data) => {
    const body = saveBody(step, data)
    const validate = validator('SaveClinicalStep')

    expect(body['expected_version']).toBe(3)
    expect(Object.keys(body).sort()).toEqual(['consultation_id', 'expected_version', 'step'])
    expect(validate(body), JSON.stringify(validate.errors)).toBe(true)
  })

  it('une clé de trop dans la donnée de l’étape serait refusée (le validateur est bien appliqué)', () => {
    const data = { ...(accepted[0]?.[2] ?? {}), invente: true }
    expect(validator('SaveClinicalStep')(saveBody('ANAMNESIS', data))).toBe(false)
  })
})
