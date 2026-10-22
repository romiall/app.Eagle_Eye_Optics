import { describe, expect, it } from 'vitest'

import { CONTROL_IDS } from '@/domain/clinical/controls'
import {
  buildSaveStepPayload,
  buildStartConsultationPayload,
  consultationFromResult,
  currentInstant,
  isConsultation,
} from '@/domain/clinical/payload'
import { dataPathOf, serverErrorTarget, serverFieldErrors } from '@/domain/clinical/serverErrors'
import {
  anamnesisDraft,
  blankAnamnesis,
  blankConclusion,
  blankDrafts,
  blankHistories,
  buildStepData,
  cleanText,
  conclusionDraft,
  draftFromData,
  glassesAnswersIgnored,
  historiesDraft,
  isHistoryGroupFilled,
  isStepDirty,
  referralAnswersIgnored,
  setOtherText,
  sortRows,
  TEXT_MAX_LENGTH,
  toggleTerm,
  validateStep,
  type AnamnesisData,
  type ConclusionData,
  type HistoriesData,
  type TermRow,
} from '@/domain/clinical/steps'
import { consultationOfVisit, examActionOf } from '@/domain/clinical/visitExam'
import type { Consultation, Visit } from '@/domain/contracts/generated'

// Rubriques narratives du dossier DP (C01) : brouillons, données envoyées, règles identiques au
// serveur, comparaison à ce qui est enregistré, erreurs du serveur rattachées aux champs. Données
// FICTIVES.
let counter = 0
const nextId = (): string => `01J9ZXK4M2Q8R7S6T5V4W3${String(++counter).padStart(4, '0')}`

function rows(group: Parameters<typeof toggleTerm>[0], ...codes: string[]): TermRow[] {
  return codes.reduce<TermRow[]>((all, code) => toggleTerm(group, all, code, true, nextId), [])
}

describe('lignes de termes', () => {
  it('cocher range les termes dans l’ordre du référentiel, quel que soit l’ordre des clics', () => {
    const list = rows('COMPLAINT', 'PAIN', 'BLURRED_VISION', 'OTHER', 'VISION_DECLINE')
    expect(list.map((row) => row.code)).toEqual([
      'VISION_DECLINE',
      'BLURRED_VISION',
      'PAIN',
      'OTHER',
    ])
  })

  it('cocher une case déjà cochée garde sa ligne (identifiant et précision) ; décocher la retire', () => {
    const list = setOtherText(rows('COMPLAINT', 'OTHER'), 'OTHER', 'Gêne au soleil')
    const again = toggleTerm('COMPLAINT', list, 'OTHER', true, nextId)
    expect(again).toEqual(list)
    expect(toggleTerm('COMPLAINT', list, 'OTHER', false, nextId)).toEqual([])
  })

  it('un code inconnu du référentiel reste après les autres, par ordre alphabétique', () => {
    const sorted = sortRows('COMPLAINT', [
      { id: '1', code: 'ZETA', otherText: '' },
      { id: '2', code: 'OTHER', otherText: '' },
      { id: '3', code: 'ALPHA', otherText: '' },
      { id: '4', code: 'PAIN', otherText: '' },
    ])
    expect(sorted.map((row) => row.code)).toEqual(['PAIN', 'OTHER', 'ALPHA', 'ZETA'])
  })
})

describe('texte', () => {
  it('retire les blancs de bord, uniformise les fins de ligne et rend null un texte vide', () => {
    expect(cleanText('  gêne \r\nà la lecture  ')).toBe('gêne \nà la lecture')
    expect(cleanText('   \n ')).toBeNull()
    expect(cleanText('')).toBeNull()
  })
})

describe('ANAMNESIS : motif et lunettes', () => {
  it('un brouillon vierge envoie uniquement des valeurs « non renseignées » (null), jamais un faux « Non »', () => {
    const data = buildStepData('ANAMNESIS', blankAnamnesis())
    expect(data).toEqual({
      symptoms: null,
      wears_glasses: null,
      glasses_duration: null,
      previous_correction_available: null,
      complaints: [],
    })
    expect(validateStep('ANAMNESIS', blankAnamnesis())).toEqual({ ok: true, data })
  })

  it('envoie les motifs avec leur identifiant de ligne, « Autre » avec sa précision', () => {
    const draft = {
      ...blankAnamnesis(),
      complaints: setOtherText(
        rows('COMPLAINT', 'OTHER', 'HEADACHE'),
        'OTHER',
        '  Gêne au soleil ',
      ),
      symptoms: ' Maux de tête le soir ',
    }
    const validation = validateStep('ANAMNESIS', draft)
    expect(validation.ok).toBe(true)
    if (!validation.ok) return
    expect(validation.data.symptoms).toBe('Maux de tête le soir')
    expect(validation.data.complaints).toEqual([
      { id: draft.complaints[0]?.id, term_code: 'HEADACHE', other_text: null },
      { id: draft.complaints[1]?.id, term_code: 'OTHER', other_text: 'Gêne au soleil' },
    ])
  })

  it('« Autre » sans précision est refusé : message, chemin du serveur et contrôle à atteindre', () => {
    const draft = { ...blankAnamnesis(), complaints: rows('COMPLAINT', 'PAIN', 'OTHER') }
    const result = validateStep('ANAMNESIS', draft)
    expect(result.ok).toBe(false)
    if (result.ok) return
    expect(result.errors).toEqual([
      {
        path: 'complaints.1.other_text',
        controlId: CONTROL_IDS.complaintOther,
        message: 'Motif : précisez la valeur « Autre ».',
      },
    ])
    // Une précision faite seulement de blancs ne compte pas.
    const blanks = setOtherText(draft.complaints, 'OTHER', '   ')
    expect(validateStep('ANAMNESIS', { ...draft, complaints: blanks }).ok).toBe(false)
  })

  it('la durée et l’ancienne correction ne partent que si la personne porte des lunettes', () => {
    const base = {
      ...blankAnamnesis(),
      glassesDuration: ' 2 ans ',
      previousCorrectionAvailable: true,
    }
    for (const wearsGlasses of [false, null]) {
      const draft = { ...base, wearsGlasses }
      const data = buildStepData('ANAMNESIS', draft)
      expect(data.wears_glasses).toBe(wearsGlasses)
      expect(data.glasses_duration).toBeNull()
      expect(data.previous_correction_available).toBeNull()
      expect(glassesAnswersIgnored(draft)).toBe(true)
    }
    const yes = buildStepData('ANAMNESIS', { ...base, wearsGlasses: true })
    expect(yes.glasses_duration).toBe('2 ans')
    expect(yes.previous_correction_available).toBe(true)
    expect(glassesAnswersIgnored({ ...base, wearsGlasses: true })).toBe(false)
    // « Non » à l'ancienne correction (false) est une réponse, distincte de « non renseigné » (null).
    expect(
      buildStepData('ANAMNESIS', {
        ...base,
        wearsGlasses: true,
        previousCorrectionAvailable: false,
      }).previous_correction_available,
    ).toBe(false)
  })

  it('limite les textes à 4000 caractères et refuse un caractère isolé invalide', () => {
    const long = 'é'.repeat(TEXT_MAX_LENGTH + 1)
    const tooLong = validateStep('ANAMNESIS', { ...blankAnamnesis(), symptoms: long })
    expect(tooLong.ok).toBe(false)
    if (!tooLong.ok) {
      expect(tooLong.errors[0]?.path).toBe('symptoms')
      expect(tooLong.errors[0]?.message).toBe(
        'Les plaintes et symptômes : le texte ne peut pas dépasser 4000 caractères.',
      )
    }
    expect(
      validateStep('ANAMNESIS', { ...blankAnamnesis(), symptoms: 'é'.repeat(TEXT_MAX_LENGTH) }).ok,
    ).toBe(true)
    const surrogate = validateStep('ANAMNESIS', { ...blankAnamnesis(), symptoms: 'ab\uD800' })
    expect(surrogate.ok).toBe(false)
  })

  it('la durée n’est contrôlée que si elle est envoyée', () => {
    const long = 'x'.repeat(TEXT_MAX_LENGTH + 1)
    const hidden = { ...blankAnamnesis(), wearsGlasses: false, glassesDuration: long }
    expect(validateStep('ANAMNESIS', hidden).ok).toBe(true)
    const shown = validateStep('ANAMNESIS', { ...hidden, wearsGlasses: true })
    expect(shown.ok).toBe(false)
    if (!shown.ok) expect(shown.errors[0]?.controlId).toBe(CONTROL_IDS.glassesDuration)
  })

  it('relit un enregistrement du serveur : lignes triées, identifiants gardés, doublons et « NONE » écartés', () => {
    const server: AnamnesisData = {
      symptoms: 'Vision floue le soir',
      wears_glasses: true,
      glasses_duration: '3 ans',
      previous_correction_available: false,
      complaints: [
        { id: 'B', term_code: 'PAIN', other_text: null },
        { id: 'A', term_code: 'VISION_DECLINE', other_text: null },
        { id: 'C', term_code: 'PAIN', other_text: null },
        { id: 'D', term_code: 'NONE', other_text: null },
      ],
    }
    const draft = anamnesisDraft(server)
    expect(draft.complaints).toEqual([
      { id: 'A', code: 'VISION_DECLINE', otherText: '' },
      { id: 'B', code: 'PAIN', otherText: '' },
    ])
    expect(draft.wearsGlasses).toBe(true)
    expect(draft.previousCorrectionAvailable).toBe(false)
    expect(draft.glassesDuration).toBe('3 ans')
  })
})

describe('HISTORIES : antécédents', () => {
  it('un groupe jamais ouvert n’est PAS envoyé : « non renseigné » n’est pas « Aucun connu »', () => {
    const draft = blankHistories(nextId)
    expect(buildStepData('HISTORIES', draft)).toEqual({ histories: [] })
    for (const group of ['OCULAR_HISTORY', 'MEDICAL_HISTORY', 'FAMILY_HISTORY'] as const) {
      expect(isHistoryGroupFilled(draft[group])).toBe(false)
    }
  })

  it('« Aucun connu » est envoyé sans terme, uniquement pour le groupe concerné', () => {
    const draft = blankHistories(nextId)
    draft.MEDICAL_HISTORY = { ...draft.MEDICAL_HISTORY, knownNone: true }
    const data = buildStepData('HISTORIES', draft)
    expect(data.histories).toEqual([
      {
        id: draft.MEDICAL_HISTORY.id,
        history_group: 'MEDICAL_HISTORY',
        known_none: true,
        comments: null,
        terms: [],
      },
    ])
  })

  it('un groupe avec valeurs et commentaire est envoyé ; les groupes suivent l’ordre oculaire, médical, familial', () => {
    const draft = blankHistories(nextId)
    draft.FAMILY_HISTORY = {
      ...draft.FAMILY_HISTORY,
      terms: setOtherText(rows('FAMILY_HISTORY', 'OTHER', 'GLAUCOMA'), 'OTHER', 'Œil unique'),
      comments: ' Père et oncle ',
    }
    draft.OCULAR_HISTORY = { ...draft.OCULAR_HISTORY, comments: 'Port de lentilles occasionnel' }
    const data = buildStepData('HISTORIES', draft)
    expect(data.histories.map((history) => history.history_group)).toEqual([
      'OCULAR_HISTORY',
      'FAMILY_HISTORY',
    ])
    expect(data.histories[1]).toMatchObject({
      known_none: false,
      comments: 'Père et oncle',
      terms: [
        { term_code: 'GLAUCOMA', other_text: null },
        { term_code: 'OTHER', other_text: 'Œil unique' },
      ],
    })
    expect(data.histories[0]).toMatchObject({ known_none: false, terms: [] })
  })

  it('« Aucun connu » avec une autre valeur est refusé sur le bon groupe', () => {
    const draft = blankHistories(nextId)
    draft.OCULAR_HISTORY = {
      ...draft.OCULAR_HISTORY,
      knownNone: true,
      terms: rows('OCULAR_HISTORY', 'TRAUMA'),
    }
    const result = validateStep('HISTORIES', draft)
    expect(result.ok).toBe(false)
    if (result.ok) return
    expect(result.errors).toEqual([
      {
        path: 'histories.0.known_none',
        controlId: CONTROL_IDS.historyNone('OCULAR_HISTORY'),
        message:
          'Antécédents oculaires : « Aucun connu » ne se combine avec aucune autre valeur du groupe.',
      },
    ])
  })

  it('« Autre » sans précision : le chemin suit la position du groupe parmi les groupes ENVOYÉS', () => {
    const draft = blankHistories(nextId)
    draft.MEDICAL_HISTORY = { ...draft.MEDICAL_HISTORY, terms: rows('MEDICAL_HISTORY', 'OTHER') }
    draft.FAMILY_HISTORY = { ...draft.FAMILY_HISTORY, terms: rows('FAMILY_HISTORY', 'OTHER') }
    const result = validateStep('HISTORIES', draft)
    expect(result.ok).toBe(false)
    if (result.ok) return
    expect(result.errors.map((error) => [error.path, error.controlId])).toEqual([
      ['histories.0.terms.0.other_text', CONTROL_IDS.historyOther('MEDICAL_HISTORY')],
      ['histories.1.terms.0.other_text', CONTROL_IDS.historyOther('FAMILY_HISTORY')],
    ])
  })

  it('relit le serveur : un groupe absent reste non renseigné, un groupe enregistré garde son identifiant', () => {
    const server: HistoriesData = {
      histories: [
        {
          id: 'GROUP-F',
          history_group: 'FAMILY_HISTORY',
          known_none: true,
          comments: null,
          terms: [],
        },
      ],
    }
    const draft = historiesDraft(server, nextId)
    expect(draft.FAMILY_HISTORY).toEqual({
      id: 'GROUP-F',
      knownNone: true,
      terms: [],
      comments: '',
    })
    expect(isHistoryGroupFilled(draft.OCULAR_HISTORY)).toBe(false)
    expect(buildStepData('HISTORIES', draft)).toEqual(server)
  })
})

describe('CONCLUSION : conclusion et orientation', () => {
  it('un brouillon vierge envoie « orientation non renseignée » sans niveau ni motif', () => {
    expect(buildStepData('CONCLUSION', blankConclusion())).toEqual({
      conclusion: null,
      referral: null,
      referral_urgency: null,
      referral_reasons: [],
    })
  })

  it('orientation « Oui » sans niveau d’urgence : refusé (le niveau est obligatoire)', () => {
    const result = validateStep('CONCLUSION', { ...blankConclusion(), referral: true })
    expect(result.ok).toBe(false)
    if (result.ok) return
    expect(result.errors).toEqual([
      {
        path: 'referral_urgency',
        controlId: CONTROL_IDS.urgency,
        message: 'Le niveau d’urgence est obligatoire quand une orientation est proposée.',
      },
    ])
  })

  it('orientation « Oui » : niveau et motifs partent, « Autre » exige une précision', () => {
    const draft = {
      ...blankConclusion(),
      conclusion: ' Orienter ',
      referral: true,
      urgency: 'RAPID' as const,
      reasons: rows('REFERRAL_REASON', 'OTHER', 'CATARACT'),
    }
    const missing = validateStep('CONCLUSION', draft)
    expect(missing.ok).toBe(false)
    if (!missing.ok) {
      expect(missing.errors[0]).toMatchObject({
        path: 'referral_reasons.1.other_text',
        controlId: CONTROL_IDS.reasonOther,
      })
    }
    const fixed = validateStep('CONCLUSION', {
      ...draft,
      reasons: setOtherText(draft.reasons, 'OTHER', 'Douleur persistante'),
    })
    expect(fixed.ok).toBe(true)
    if (!fixed.ok) return
    expect(fixed.data).toMatchObject({
      conclusion: 'Orienter',
      referral: true,
      referral_urgency: 'RAPID',
    })
    expect(fixed.data.referral_reasons.map((reason) => reason.term_code)).toEqual([
      'CATARACT',
      'OTHER',
    ])
  })

  it('orientation « Non » ou non renseignée : niveau et motifs saisis ne sont jamais envoyés', () => {
    for (const referral of [false, null]) {
      const draft = {
        ...blankConclusion(),
        referral,
        urgency: 'EMERGENCY' as const,
        reasons: rows('REFERRAL_REASON', 'TRAUMA'),
      }
      expect(referralAnswersIgnored(draft)).toBe(true)
      const data = buildStepData('CONCLUSION', draft)
      expect(data.referral).toBe(referral)
      expect(data.referral_urgency).toBeNull()
      expect(data.referral_reasons).toEqual([])
      // Rien à corriger : un niveau manquant ne bloque pas une orientation « Non ».
      expect(validateStep('CONCLUSION', { ...draft, urgency: null }).ok).toBe(true)
    }
  })

  it('relit le serveur : un niveau inconnu n’est pas deviné', () => {
    const server = {
      conclusion: 'RAS',
      referral: true,
      referral_urgency: 'AUTRE' as unknown as ConclusionData['referral_urgency'],
      referral_reasons: [],
    }
    expect(conclusionDraft(server).urgency).toBeNull()
  })
})

describe('comparaison avec ce qui est enregistré', () => {
  const server: AnamnesisData = {
    symptoms: 'Fatigue visuelle',
    wears_glasses: false,
    glasses_duration: null,
    previous_correction_available: null,
    complaints: [{ id: 'A', term_code: 'VISUAL_FATIGUE', other_text: null }],
  }

  it('un brouillon relu du serveur n’est pas modifié, même ressaisi avec d’autres blancs', () => {
    const draft = draftFromData('ANAMNESIS', server, nextId)
    expect(isStepDirty('ANAMNESIS', draft, server)).toBe(false)
    expect(isStepDirty('ANAMNESIS', { ...draft, symptoms: '  Fatigue visuelle \n' }, server)).toBe(
      false,
    )
  })

  it('une valeur changée, une case cochée ou décochée, une réponse « Non » → « Non renseigné » est une modification', () => {
    const draft = draftFromData('ANAMNESIS', server, nextId)
    expect(isStepDirty('ANAMNESIS', { ...draft, symptoms: 'Autre' }, server)).toBe(true)
    expect(isStepDirty('ANAMNESIS', { ...draft, complaints: [] }, server)).toBe(true)
    expect(isStepDirty('ANAMNESIS', { ...draft, wearsGlasses: null }, server)).toBe(true)
  })

  it('une réponse cachée (durée d’un non-porteur) n’est pas une modification : elle n’est pas envoyée', () => {
    const draft = draftFromData('ANAMNESIS', server, nextId)
    expect(isStepDirty('ANAMNESIS', { ...draft, glassesDuration: 'texte caché' }, server)).toBe(
      false,
    )
  })

  it('un groupe d’antécédents rempli puis vidé redevient « non renseigné » : modification si le serveur l’avait', () => {
    const saved: HistoriesData = {
      histories: [
        { id: 'G', history_group: 'OCULAR_HISTORY', known_none: true, comments: null, terms: [] },
      ],
    }
    const draft = draftFromData('HISTORIES', saved, nextId)
    expect(isStepDirty('HISTORIES', draft, saved)).toBe(false)
    expect(
      isStepDirty(
        'HISTORIES',
        { ...draft, OCULAR_HISTORY: { ...draft.OCULAR_HISTORY, knownNone: false } },
        saved,
      ),
    ).toBe(true)
    const none = draftFromData('HISTORIES', { histories: [] }, nextId)
    expect(isStepDirty('HISTORIES', none, { histories: [] })).toBe(false)
  })

  it('blankDrafts crée les sept rubriques vierges, dans l’ordre du dossier', () => {
    const drafts = blankDrafts(nextId)
    expect(Object.keys(drafts)).toEqual([
      'ANAMNESIS',
      'HISTORIES',
      'VISUAL_ACUITY',
      'REFRACTION',
      'CENTERING',
      'MEDICATIONS',
      'CONCLUSION',
    ])
    expect(
      isStepDirty('CONCLUSION', drafts.CONCLUSION, buildStepData('CONCLUSION', blankConclusion())),
    ).toBe(false)
  })
})

describe('charges des commandes', () => {
  it('SaveClinicalStep : identifiant de consultation et étape complète, données telles que validées', () => {
    const data = buildStepData('CONCLUSION', { ...blankConclusion(), conclusion: 'RAS' })
    expect(buildSaveStepPayload('01J9ZXK4M2Q8R7S6T5V4W30500', 'CONCLUSION', data)).toEqual({
      consultation_id: '01J9ZXK4M2Q8R7S6T5V4W30500',
      step: { step: 'CONCLUSION', data },
    })
  })

  it('StartConsultation : pas de motif de changement de date ni de preuve de paiement (contrôle inactif)', () => {
    expect(
      buildStartConsultationPayload(
        { id: '01J9ZXK4M2Q8R7S6T5V4W30021', patient_id: '01J9ZXK4M2Q8R7S6T5V4W30011' },
        '01J9ZXK4M2Q8R7S6T5V4W30501',
        '2026-10-22T09:42:00.000Z',
      ),
    ).toEqual({
      consultation_id: '01J9ZXK4M2Q8R7S6T5V4W30501',
      visit_id: '01J9ZXK4M2Q8R7S6T5V4W30021',
      patient_id: '01J9ZXK4M2Q8R7S6T5V4W30011',
      effective_at: '2026-10-22T09:42:00.000Z',
      date_change_reason: null,
      exam_invoice_id: null,
      exam_waiver_approval_id: null,
    })
  })

  it('currentInstant : UTC à la milliseconde au format du contrat', () => {
    expect(currentInstant(Date.UTC(2026, 9, 22, 9, 42, 5, 7))).toBe('2026-10-22T09:42:05.007Z')
  })

  it('reconnaît une consultation exploitable, jamais une réponse incomplète', () => {
    const steps = {
      ANAMNESIS: blankAnamnesisData(),
      HISTORIES: { histories: [] },
      CONCLUSION: {
        conclusion: null,
        referral: null,
        referral_urgency: null,
        referral_reasons: [],
      },
    }
    const consultation = {
      id: 'C',
      patient_id: 'P',
      version: 2,
      status: 'DRAFT',
      steps,
    } as unknown as Consultation
    expect(isConsultation(consultation)).toBe(true)
    expect(consultationFromResult({ data: consultation })).toBe(consultation)
    for (const broken of [
      null,
      {},
      { data: null },
      { data: { ...consultation, version: '2' } },
      { data: { ...consultation, steps: { ANAMNESIS: {} } } },
      { data: { ...consultation, steps: null } },
      { data: { ...consultation, id: 7 } },
    ]) {
      expect(consultationFromResult(broken)).toBeNull()
    }
  })
})

function blankAnamnesisData(): AnamnesisData {
  return buildStepData('ANAMNESIS', blankAnamnesis())
}

describe('erreurs du serveur rattachées aux champs', () => {
  const anamnesis = buildStepData('ANAMNESIS', {
    ...blankAnamnesis(),
    complaints: rows('COMPLAINT', 'PAIN', 'OTHER'),
  })

  it('retire le préfixe du serveur (step.data.) et retrouve le code de la ligne par son indice', () => {
    expect(dataPathOf('step.data.complaints.1.other_text')).toBe('complaints.1.other_text')
    expect(dataPathOf('data.symptoms')).toBe('symptoms')
    expect(dataPathOf('symptoms')).toBe('symptoms')
    expect(serverErrorTarget('ANAMNESIS', anamnesis, 'step.data.complaints.1.other_text')).toBe(
      CONTROL_IDS.complaintOther,
    )
    expect(serverErrorTarget('ANAMNESIS', anamnesis, 'step.data.complaints.0.term_code')).toBe(
      CONTROL_IDS.complaint('PAIN'),
    )
    expect(serverErrorTarget('ANAMNESIS', anamnesis, 'step.data.complaints')).toBe(
      CONTROL_IDS.complaints,
    )
    expect(serverErrorTarget('ANAMNESIS', anamnesis, 'step.data.glasses_duration')).toBe(
      CONTROL_IDS.glassesDuration,
    )
  })

  it('une clé inconnue ou un indice hors liste mène au titre de l’étape : le message n’est jamais perdu', () => {
    expect(serverErrorTarget('ANAMNESIS', anamnesis, 'step.data.autre_chose')).toBe(
      CONTROL_IDS.step,
    )
    expect(serverErrorTarget('ANAMNESIS', anamnesis, 'step')).toBe(CONTROL_IDS.step)
    expect(serverErrorTarget('ANAMNESIS', anamnesis, 'step.data.complaints.9.other_text')).toBe(
      CONTROL_IDS.complaints,
    )
  })

  it('antécédents : groupe, valeur, précision et commentaire retrouvés par la position du groupe envoyé', () => {
    const draft = blankHistories(nextId)
    draft.MEDICAL_HISTORY = {
      ...draft.MEDICAL_HISTORY,
      terms: rows('MEDICAL_HISTORY', 'DIABETES', 'OTHER'),
    }
    draft.FAMILY_HISTORY = { ...draft.FAMILY_HISTORY, comments: 'x' }
    const data = buildStepData('HISTORIES', draft)
    expect(serverErrorTarget('HISTORIES', data, 'step.data.histories.0.terms.1.other_text')).toBe(
      CONTROL_IDS.historyOther('MEDICAL_HISTORY'),
    )
    expect(serverErrorTarget('HISTORIES', data, 'step.data.histories.0.terms.0.term_code')).toBe(
      CONTROL_IDS.historyTerm('MEDICAL_HISTORY', 'DIABETES'),
    )
    expect(serverErrorTarget('HISTORIES', data, 'step.data.histories.1.comments')).toBe(
      CONTROL_IDS.historyComments('FAMILY_HISTORY'),
    )
    expect(serverErrorTarget('HISTORIES', data, 'step.data.histories.0.known_none')).toBe(
      CONTROL_IDS.historyNone('MEDICAL_HISTORY'),
    )
    expect(serverErrorTarget('HISTORIES', data, 'step.data.histories.5.comments')).toBe(
      CONTROL_IDS.step,
    )
  })

  it('conclusion : conclusion, orientation, niveau et motifs', () => {
    const data = buildStepData('CONCLUSION', {
      ...blankConclusion(),
      referral: true,
      urgency: 'RAPID',
      reasons: rows('REFERRAL_REASON', 'TRAUMA', 'OTHER'),
    })
    expect(serverErrorTarget('CONCLUSION', data, 'step.data.referral_urgency')).toBe(
      CONTROL_IDS.urgency,
    )
    expect(serverErrorTarget('CONCLUSION', data, 'step.data.referral')).toBe(CONTROL_IDS.referral)
    expect(serverErrorTarget('CONCLUSION', data, 'step.data.conclusion')).toBe(
      CONTROL_IDS.conclusion,
    )
    expect(serverErrorTarget('CONCLUSION', data, 'step.data.referral_reasons.1.other_text')).toBe(
      CONTROL_IDS.reasonOther,
    )
    expect(serverErrorTarget('CONCLUSION', data, 'step.data.referral_reasons.0.id')).toBe(
      CONTROL_IDS.reason('TRAUMA'),
    )
  })

  it('rend tous les messages, dans l’ordre reçu', () => {
    expect(
      serverFieldErrors('ANAMNESIS', anamnesis, {
        'step.data.symptoms': ['Trop long.', 'Caractère invalide.'],
        'step.data.complaints.1.other_text': ['Précision requise.'],
      }),
    ).toEqual([
      { controlId: CONTROL_IDS.symptoms, message: 'Trop long.' },
      { controlId: CONTROL_IDS.symptoms, message: 'Caractère invalide.' },
      { controlId: CONTROL_IDS.complaintOther, message: 'Précision requise.' },
    ])
  })
})

describe('action d’examen d’une visite', () => {
  function visit(overrides: Partial<Visit>): Visit {
    return {
      id: '01J9ZXK4M2Q8R7S6T5V4W30021',
      patient_id: '01J9ZXK4M2Q8R7S6T5V4W30011',
      reason: 'EXAM',
      occurred_at: '2026-10-22T08:00:00.000Z',
      status: 'ARRIVED',
      appointment_id: null,
      exam_invoice_id: null,
      exam_waiver_approval_id: null,
      arrival_at: null,
      closed_at: null,
      administrative_note: null,
      version: 1,
      patient: {
        id: '01J9ZXK4M2Q8R7S6T5V4W30011',
        record_number: 'EE-2026-0001',
        last_name: 'PATIENT FICTIF',
        first_names: null,
        sex: 'F',
        birth_date: null,
      },
      exam_fee_control: 'NOT_CONTROLLED',
      consultation_id: null,
      consultation_status: null,
      ...overrides,
    }
  }

  const ALL = { canRead: true, canWrite: true, moduleOpen: true }

  it('démarrer : examen ou suivi arrivé (ou prêt), sans consultation, avec clinical.write ET clinical.read, module ouvert', () => {
    expect(examActionOf(visit({}), ALL)).toBe('start')
    expect(examActionOf(visit({ reason: 'FOLLOW_UP' }), ALL)).toBe('start')
    // Le serveur accepte aussi une visite « prête pour l'examen » (écrite plus tard par B04 et B06).
    expect(examActionOf(visit({ status: 'READY_FOR_EXAM' }), ALL)).toBe('start')
  })

  it('ni achat ni retrait, ni autre statut, ni sans droit d’écriture ou de lecture, ni module fermé', () => {
    expect(examActionOf(visit({ reason: 'DIRECT_PURCHASE' }), ALL)).toBeNull()
    expect(examActionOf(visit({ reason: 'PICKUP' }), ALL)).toBeNull()
    for (const status of [
      'AWAITING_EXAM_FEE',
      'IN_CONSULTATION',
      'COMPLETED',
      'CANCELLED',
    ] as const) {
      expect(examActionOf(visit({ status }), ALL), status).toBeNull()
    }
    expect(examActionOf(visit({}), { ...ALL, canWrite: false })).toBeNull()
    // Le serveur répond par la consultation : démarrer exige aussi clinical.read.
    expect(examActionOf(visit({}), { ...ALL, canRead: false })).toBeNull()
    expect(examActionOf(visit({}), { ...ALL, moduleOpen: false })).toBeNull()
  })

  it('reprendre : une consultation existe et le compte peut la lire ; consulter si elle est terminée', () => {
    const started = visit({
      status: 'IN_CONSULTATION',
      consultation_id: '01J9ZXK4M2Q8R7S6T5V4W30501',
      consultation_status: 'IN_PROGRESS',
    })
    expect(examActionOf(started, ALL)).toBe('resume')
    expect(examActionOf({ ...started, consultation_status: 'DRAFT' }, ALL)).toBe('resume')
    expect(examActionOf({ ...started, consultation_status: 'COMPLETED' }, ALL)).toBe('view')
    expect(examActionOf(started, { ...ALL, canRead: false })).toBeNull()
    expect(examActionOf(started, { ...ALL, moduleOpen: false })).toBeNull()
    // Une visite qui a déjà une consultation ne propose jamais de la démarrer.
    expect(examActionOf({ ...started, status: 'ARRIVED' }, ALL)).toBe('resume')
  })

  it('un champ absent de la réponse (serveur plus ancien) vaut « aucune consultation connue »', () => {
    const legacy = visit({}) as Partial<Visit>
    delete legacy.consultation_id
    delete legacy.consultation_status
    expect(consultationOfVisit(legacy as Visit)).toEqual({ id: null, status: null })
  })
})
