import { pdTextsMismatch } from './centering'
import {
  axisData,
  axisError,
  axisText,
  axisUnparsable,
  cleanText,
  measureData,
  measureError,
  measureText,
  measureUnits,
  measureUnparsable,
  type NewId,
  type StepError,
  textError,
} from './fields'
import type { MeasurementData, RefractionStage } from './refraction'
import { rowsFromData, termErrors, type TermData, type TermRow, termsData } from './termRows'
import { newUlid } from '../values/ulid'
import { CivilDate } from '../values/civilDate'
import type { Prescription } from '../contracts/generated'

/**
 * Prescription optique (C04 ; fichier 02, « Prescription optique » ; DP §8 ; EE009, EE014) : distincte des mesures
 * objectives et subjectives, brouillon éditable puis version VALIDÉE immuable ; une correction est une NOUVELLE
 * version de la série.
 *
 * - Copier une réfraction est un geste EXPLICITE (`copyRefraction`, après confirmation de l'écran) ; éditer ensuite
 *   la prescription ne modifie jamais l'examen source. Rien n'est copié d'office ni d'un œil à l'autre.
 * - Les valeurs sont exactes (deux décimales, jamais arrondies), zéro est une valeur distincte de « non saisi » ; un
 *   cylindre positif n'est jamais transposé ; la DP monoculaire n'est jamais fabriquée à partir du total.
 * - À la validation : pour chaque œil, des valeurs saisies OU « aucune correction » ; un cylindre non nul exige un axe.
 * - Aucun diagnostic ni aucune posologie n'est inféré. Vente et stock ne sont pas créés par la prescription.
 * Les règles de `prescriptionErrors` sont celles du serveur (`OpticalPrescriptionRules`). Règles pures : ni Vue, ni
 * réseau, ni stockage.
 */
export type PrescriptionOrigin = 'INTERNAL' | 'EXTERNAL'
export type PrescriptionEye = 'OD' | 'OG'

/** Indices proposés par le fichier 02 ; « autre » est un indice libre avec précision. */
export const LENS_INDEXES = ['1.50', '1.56', '1.59', '1.60', '1.61', '1.67', '1.74'] as const
export const LENS_INDEX_OTHER = 'OTHER'
export const TREATMENTS_MAX = 20
export const EYES: readonly PrescriptionEye[] = ['OD', 'OG']

/** Identifiants des contrôles de la prescription (cibles du résumé d'erreurs). */
export const RX_IDS = {
  form: 'rx-formulaire',
  externalPrescriber: 'rx-prescripteur',
  externalPrescribedOn: 'rx-date-ordonnance',
  usage: 'rx-usage',
  usageOther: 'rx-usage-precision',
  lensType: 'rx-type-verre',
  lensTypeOther: 'rx-type-verre-precision',
  lensIndex: 'rx-indice',
  lensIndexOther: 'rx-indice-precision',
  pdTotal: 'rx-dp-totale',
  pdOd: 'rx-dp-od',
  pdOg: 'rx-dp-og',
  pdReason: 'rx-dp-justification',
  notes: 'rx-observations',
  treatments: 'rx-traitements',
  treatmentOther: 'rx-traitements-OTHER-precision',
  eye: (eye: PrescriptionEye, field: string): string => `rx-${eye}-${field}`,
} as const

// --- Forme du contrat -------------------------------------------------------------------------------

export interface PrescriptionEyeData {
  id: string
  eye: PrescriptionEye
  no_correction: boolean
  sphere: string | null
  cylinder: string | null
  axis: number | null
  addition: string | null
  fitting_height: string | null
}

/** Contenu d'une prescription : les champs de `CreatePrescription` qui ne sont ni des identifiants ni la source. */
export interface PrescriptionContent {
  external_prescriber: string | null
  external_prescribed_on: string | null
  external_file_id: string | null
  usage_code: string | null
  usage_other: string | null
  lens_type_code: string | null
  lens_type_other: string | null
  lens_index: string | null
  lens_index_other: string | null
  pd_total: string | null
  pd_od: string | null
  pd_og: string | null
  pd_inconsistency_reason: string | null
  notes: string | null
  eyes: PrescriptionEyeData[]
  treatments: TermData[]
}

// --- Brouillon -------------------------------------------------------------------------------------

export interface PrescriptionEyeDraft {
  id: string
  noCorrection: boolean
  sphere: string
  cylinder: string
  axis: string
  addition: string
  fittingHeight: string
}

export interface PrescriptionDraft {
  origin: PrescriptionOrigin
  externalPrescriber: string
  /** Date ISO « 2026-10-15 » ou null (vide). */
  externalPrescribedOn: string | null
  externalFileId: string | null
  usageCode: string | null
  usageOther: string
  lensTypeCode: string | null
  lensTypeOther: string
  /** Indice de la liste, `OTHER` pour un autre indice (avec précision), null : non précisé. */
  lensIndex: string | null
  lensIndexOther: string
  pdTotal: string
  pdOd: string
  pdOg: string
  pdReason: string
  notes: string
  eyes: Record<PrescriptionEye, PrescriptionEyeDraft>
  treatments: TermRow[]
}

function blankEye(newId: NewId): PrescriptionEyeDraft {
  return { id: newId(), noCorrection: false, sphere: '', cylinder: '', axis: '', addition: '', fittingHeight: '' }
}

export function blankPrescription(origin: PrescriptionOrigin, newId: NewId = newUlid): PrescriptionDraft {
  return {
    origin,
    externalPrescriber: '',
    externalPrescribedOn: null,
    externalFileId: null,
    usageCode: null,
    usageOther: '',
    lensTypeCode: null,
    lensTypeOther: '',
    lensIndex: null,
    lensIndexOther: '',
    pdTotal: '',
    pdOd: '',
    pdOg: '',
    pdReason: '',
    notes: '',
    eyes: { OD: blankEye(newId), OG: blankEye(newId) },
    treatments: [],
  }
}

function isListedIndex(value: string | null): boolean {
  return value !== null && (LENS_INDEXES as readonly string[]).includes(value)
}

/** Brouillon d'édition à partir d'une prescription enregistrée (brouillon ou version validée, pour la copier en version suivante). */
export function prescriptionDraft(prescription: Prescription, newId: NewId = newUlid): PrescriptionDraft {
  const draft = blankPrescription(prescription.origin, newId)
  for (const eye of prescription.eyes) {
    draft.eyes[eye.eye] = {
      id: eye.id,
      noCorrection: eye.no_correction,
      sphere: measureText(eye.sphere),
      cylinder: measureText(eye.cylinder),
      axis: axisText(eye.axis),
      addition: measureText(eye.addition),
      fittingHeight: measureText(eye.fitting_height),
    }
  }
  const listed = isListedIndex(prescription.lens_index)
  return {
    ...draft,
    externalPrescriber: prescription.external_prescriber ?? '',
    externalPrescribedOn: prescription.external_prescribed_on,
    externalFileId: prescription.external_file_id,
    usageCode: prescription.usage_code,
    usageOther: prescription.usage_other ?? '',
    lensTypeCode: prescription.lens_type_code,
    lensTypeOther: prescription.lens_type_other ?? '',
    lensIndex: listed ? prescription.lens_index : prescription.lens_index_other !== null ? LENS_INDEX_OTHER : null,
    lensIndexOther: prescription.lens_index_other ?? '',
    pdTotal: measureText(prescription.pd_total),
    pdOd: measureText(prescription.pd_od),
    pdOg: measureText(prescription.pd_og),
    pdReason: prescription.pd_inconsistency_reason ?? '',
    notes: prescription.notes ?? '',
    treatments: rowsFromData('LENS_TREATMENT', prescription.treatments),
  }
}

function eyeData(eye: PrescriptionEye, draft: PrescriptionEyeDraft): PrescriptionEyeData {
  return {
    id: draft.id,
    eye,
    no_correction: draft.noCorrection,
    sphere: measureData(draft.sphere),
    cylinder: measureData(draft.cylinder),
    axis: axisData(draft.axis),
    addition: measureData(draft.addition),
    fitting_height: measureData(draft.fittingHeight),
  }
}

/** Contenu envoyé (forme exacte du contrat) ; champs vides à null. */
export function prescriptionContent(draft: PrescriptionDraft): PrescriptionContent {
  const external = draft.origin === 'EXTERNAL'
  const usageOther = draft.usageCode === 'OTHER' ? cleanText(draft.usageOther) : null
  const typeOther = draft.lensTypeCode === 'OTHER' ? cleanText(draft.lensTypeOther) : null
  const indexOther = draft.lensIndex === LENS_INDEX_OTHER ? cleanText(draft.lensIndexOther) : null
  return {
    external_prescriber: external ? cleanText(draft.externalPrescriber) : null,
    external_prescribed_on: external ? draft.externalPrescribedOn : null,
    external_file_id: external ? draft.externalFileId : null,
    usage_code: draft.usageCode,
    usage_other: usageOther,
    lens_type_code: draft.lensTypeCode,
    lens_type_other: typeOther,
    lens_index: isListedIndex(draft.lensIndex) ? draft.lensIndex : null,
    lens_index_other: indexOther,
    pd_total: measureData(draft.pdTotal),
    pd_od: measureData(draft.pdOd),
    pd_og: measureData(draft.pdOg),
    pd_inconsistency_reason: cleanText(draft.pdReason),
    notes: cleanText(draft.notes),
    eyes: EYES.map((eye) => eyeData(eye, draft.eyes[eye])),
    treatments: termsData(draft.treatments),
  }
}

/** Vrai si une saisie ne se convertit pas en donnée (elle compte alors comme une modification). */
export function prescriptionUnparsable(draft: PrescriptionDraft): boolean {
  return (
    measureUnparsable(draft.pdTotal) ||
    measureUnparsable(draft.pdOd) ||
    measureUnparsable(draft.pdOg) ||
    EYES.some((eye) => {
      const value = draft.eyes[eye]
      return (
        measureUnparsable(value.sphere) ||
        measureUnparsable(value.cylinder) ||
        measureUnparsable(value.addition) ||
        measureUnparsable(value.fittingHeight) ||
        axisUnparsable(value.axis)
      )
    })
  )
}

// --- Copie explicite de la réfraction ---------------------------------------------------------------

export interface RefractionCopy {
  draft: PrescriptionDraft
  /** Yeux dont les valeurs ont été copiées (un œil sans mesure dans ce stade n'est pas touché). */
  copied: PrescriptionEye[]
}

/**
 * Copie les valeurs d'un stade de la réfraction (au choix du professionnel) dans la prescription : sphère, cylindre, axe
 * et addition (l'addition n'existe que pour la réfraction subjective). La copie remplace les saisies de l'œil
 * concerné ; la prescription s'édite ensuite indépendamment. Aucun autre champ n'est touché (hauteur de montage,
 * usage, traitements…), un œil sans mesure dans ce stade reste inchangé.
 */
export function copyRefraction(
  draft: PrescriptionDraft,
  measurements: readonly MeasurementData[],
  stage: RefractionStage,
): RefractionCopy {
  const eyes = { ...draft.eyes }
  const copied: PrescriptionEye[] = []
  for (const eye of EYES) {
    const row = measurements.find((measurement) => measurement.stage === stage && measurement.eye === eye)
    if (row === undefined) continue
    eyes[eye] = {
      ...eyes[eye],
      noCorrection: false,
      sphere: measureText(row.sphere),
      cylinder: measureText(row.cylinder),
      axis: axisText(row.axis),
      addition: stage === 'SUBJECTIVE' ? measureText(row.addition) : '',
    }
    copied.push(eye)
  }
  return { draft: { ...draft, eyes }, copied }
}

/** Copie explicite des écarts pupillaires mesurés au centrage (aucun écart n'est fabriqué). */
export function copyCentering(
  draft: PrescriptionDraft,
  centering: { pd_total: string | null; pd_od: string | null; pd_og: string | null },
): PrescriptionDraft {
  return {
    ...draft,
    pdTotal: measureText(centering.pd_total),
    pdOd: measureText(centering.pd_od),
    pdOg: measureText(centering.pd_og),
  }
}

// --- Règles ----------------------------------------------------------------------------------------

export type RuleLevel = 'draft' | 'validate'

function positiveError(text: string, label: string): string | null {
  const message = measureError(text)
  if (message !== null) return `${label} : ${message}`
  const units = measureUnits(text)
  return units !== null && units <= 0n ? `${label} : doit être strictement positif (en millimètres).` : null
}

/**
 * Règles de la prescription (celles du serveur). Au niveau `draft`, un brouillon incomplet est admis ; au niveau
 * `validate`, chaque œil porte des valeurs saisies ou « aucune correction », et un cylindre non nul exige un axe.
 * `today` (AAAA-MM-JJ, jour du cabinet) borne la date d'une ordonnance externe.
 */
export function prescriptionErrors(draft: PrescriptionDraft, level: RuleLevel, today: string): StepError[] {
  const errors: StepError[] = []
  const add = (path: string, controlId: string, message: string | null): void => {
    if (message !== null) errors.push({ path, controlId, message })
  }

  if (draft.origin === 'EXTERNAL') {
    if (cleanText(draft.externalPrescriber) === null) {
      add('external_prescriber', RX_IDS.externalPrescriber, 'Le prescripteur de l’ordonnance externe est obligatoire.')
    } else {
      add('external_prescriber', RX_IDS.externalPrescriber, textError(draft.externalPrescriber, 'Le prescripteur', 180, true))
    }
    if (draft.externalPrescribedOn === null) {
      add('external_prescribed_on', RX_IDS.externalPrescribedOn, 'La date de l’ordonnance externe est obligatoire.')
    } else if (draft.externalPrescribedOn > today) {
      add('external_prescribed_on', RX_IDS.externalPrescribedOn, 'La date de l’ordonnance ne peut pas être dans le futur.')
    }
  }

  if (draft.usageCode === 'OTHER') {
    if (cleanText(draft.usageOther) === null) add('usage_other', RX_IDS.usageOther, 'Usage : précisez la valeur « Autre ».')
    else add('usage_other', RX_IDS.usageOther, textError(draft.usageOther, 'Usage'))
  }
  if (draft.lensTypeCode === 'OTHER') {
    if (cleanText(draft.lensTypeOther) === null) add('lens_type_other', RX_IDS.lensTypeOther, 'Type de verre : précisez la valeur « Autre ».')
    else add('lens_type_other', RX_IDS.lensTypeOther, textError(draft.lensTypeOther, 'Type de verre'))
  }
  if (draft.lensIndex === LENS_INDEX_OTHER) {
    if (cleanText(draft.lensIndexOther) === null) add('lens_index_other', RX_IDS.lensIndexOther, 'Indice : précisez l’indice « autre ».')
    else add('lens_index_other', RX_IDS.lensIndexOther, textError(draft.lensIndexOther, 'Indice'))
  }

  for (const [field, label, text, id] of [
    ['pd_total', 'Écart pupillaire total', draft.pdTotal, RX_IDS.pdTotal],
    ['pd_od', 'Écart pupillaire OD', draft.pdOd, RX_IDS.pdOd],
    ['pd_og', 'Écart pupillaire OG', draft.pdOg, RX_IDS.pdOg],
  ] as const) {
    add(field, id, positiveError(text, label))
  }
  const anyPd = [draft.pdTotal, draft.pdOd, draft.pdOg].some((text) => cleanText(text) !== null)
  const pdValid = [draft.pdTotal, draft.pdOd, draft.pdOg].every((text) => measureError(text) === null)
  add('pd_inconsistency_reason', RX_IDS.pdReason, textError(draft.pdReason, 'La justification'))
  if (pdValid && pdTextsMismatch(draft.pdTotal, draft.pdOd, draft.pdOg) && cleanText(draft.pdReason) === null) {
    add('pd_inconsistency_reason', RX_IDS.pdReason, 'La somme des écarts OD et OG diffère de l’écart total : corrigez une valeur ou justifiez l’écart.')
  }
  if (!anyPd && cleanText(draft.pdReason) !== null) {
    add('pd_inconsistency_reason', RX_IDS.pdReason, 'Une justification n’a de sens qu’avec un écart pupillaire saisi.')
  }

  add('notes', RX_IDS.notes, textError(draft.notes, 'Les observations'))

  EYES.forEach((eye, index) => {
    const value = draft.eyes[eye]
    const where = `eyes.${index}`
    const subject = `Œil ${eye}`
    add(`${where}.sphere`, RX_IDS.eye(eye, 'sphere'), prefixed(subject, 'Sphère', measureError(value.sphere)))
    add(`${where}.cylinder`, RX_IDS.eye(eye, 'cylinder'), prefixed(subject, 'Cylindre', measureError(value.cylinder)))
    add(`${where}.axis`, RX_IDS.eye(eye, 'axis'), prefixed(subject, 'Axe', axisError(value.axis)))
    add(`${where}.addition`, RX_IDS.eye(eye, 'addition'), prefixed(subject, 'Addition', measureError(value.addition)))
    add(`${where}.fitting_height`, RX_IDS.eye(eye, 'fitting_height'), positiveError(value.fittingHeight, `${subject} · Hauteur de montage`))
    const anyValue = [value.sphere, value.cylinder, value.axis, value.addition].some((text) => cleanText(text) !== null)
    if (value.noCorrection && anyValue) {
      add(`${where}.no_correction`, RX_IDS.eye(eye, 'no_correction'), `${subject} : une valeur est saisie ; décochez « Aucune correction » ou effacez les valeurs.`)
    }
    if (level === 'validate') {
      if (!value.noCorrection && !anyValue) {
        add(`${where}.no_correction`, RX_IDS.eye(eye, 'no_correction'), `${subject} : saisissez les valeurs, ou cochez « Aucune correction » pour cet œil.`)
      }
      const cylinder = measureUnits(value.cylinder)
      if (cylinder !== null && cylinder !== 0n && cleanText(value.axis) === null) {
        add(`${where}.axis`, RX_IDS.eye(eye, 'axis'), `${subject} : un cylindre non nul exige un axe (0 à 180 degrés).`)
      }
    }
  })

  errors.push(
    ...termErrors({
      path: 'treatments',
      group: 'LENS_TREATMENT',
      rows: draft.treatments,
      max: TREATMENTS_MAX,
      listControl: RX_IDS.treatments,
      otherControl: RX_IDS.treatmentOther,
      otherSubject: 'Traitement',
    }),
  )
  return errors
}

function prefixed(subject: string, label: string, message: string | null): string | null {
  return message === null ? null : `${subject} · ${label} : ${message}`
}

/** Jour civil du cabinet au format AAAA-MM-JJ (borne de la date d'une ordonnance externe). */
export function todayIso(today: CivilDate): string {
  return today.toString()
}

// --- Charges ---------------------------------------------------------------------------------------

export interface CreatePrescriptionContext {
  prescriptionId: string
  seriesId: string
  versionNo: number
  supersedesId: string | null
  patientId: string
  consultationId: string | null
}

/** Charge `CreatePrescription` : toutes les clés présentes, null explicite pour l'absent. */
export function buildCreatePayload(
  context: CreatePrescriptionContext,
  draft: PrescriptionDraft,
): Record<string, unknown> {
  return {
    prescription_id: context.prescriptionId,
    series_id: context.seriesId,
    version_no: context.versionNo,
    supersedes_id: context.supersedesId,
    patient_id: context.patientId,
    origin: draft.origin,
    consultation_id: context.consultationId,
    ...prescriptionContent(draft),
  }
}

/** Champs d'un brouillon qui diffèrent de la prescription enregistrée (corps du `PATCH`). */
export function buildDraftChanges(
  draft: PrescriptionDraft,
  saved: Prescription,
): Record<string, unknown> {
  const next = prescriptionContent(draft)
  const before = prescriptionContent(prescriptionDraft(saved, () => ''))
  const changes: Record<string, unknown> = {}
  for (const key of Object.keys(next) as (keyof PrescriptionContent)[]) {
    if (JSON.stringify(next[key]) !== JSON.stringify(before[key])) changes[key] = next[key]
  }
  return changes
}

/** Vrai si le brouillon diffère de ce qui est enregistré. */
export function isPrescriptionDirty(draft: PrescriptionDraft, saved: Prescription): boolean {
  return prescriptionUnparsable(draft) || Object.keys(buildDraftChanges(draft, saved)).length > 0
}

export function buildValidatePayload(prescriptionId: string, documentId: string): Record<string, unknown> {
  return { prescription_id: prescriptionId, document_id: documentId }
}

/** Numéro de la version suivante d'une série (la dernière version validée + 1). */
export function nextVersionNo(latest: Pick<Prescription, 'version_no'>): number {
  return latest.version_no + 1
}

// --- Nouvelle version, conflit, erreurs du serveur ---------------------------------------------------------

/**
 * Copie d'un brouillon avec des identifiants de lignes NEUFS (yeux, traitements) : une nouvelle version d'une
 * prescription validée reprend le contenu de la précédente, mais ses lignes sont de nouvelles lignes (les
 * identifiants de la version validée sont déjà pris et ne changent jamais).
 */
export function withNewIds(draft: PrescriptionDraft, newId: NewId = newUlid): PrescriptionDraft {
  return {
    ...draft,
    eyes: {
      OD: { ...draft.eyes.OD, id: newId() },
      OG: { ...draft.eyes.OG, id: newId() },
    },
    treatments: draft.treatments.map((row) => ({ ...row, id: newId() })),
  }
}

/** Contrôle visé par une clé d'erreur du serveur (`eyes.0.axis`, `usage_other`…) ; le formulaire sinon. */
export function prescriptionErrorTarget(key: string): string {
  const path = key.replace(/^changes\./, '')
  const eye = /^eyes\.([0-9]+)\.([a-z_]+)$/.exec(path)
  if (eye !== null) {
    const which = EYES[Number(eye[1])]
    return which === undefined ? RX_IDS.form : RX_IDS.eye(which, eye[2] ?? 'sphere')
  }
  const treatment = /^treatments(?:\.[0-9]+\.(other_text))?/.exec(path)
  if (treatment !== null) return treatment[1] === undefined ? RX_IDS.treatments : RX_IDS.treatmentOther
  switch (path) {
    case 'external_prescriber':
      return RX_IDS.externalPrescriber
    case 'external_prescribed_on':
      return RX_IDS.externalPrescribedOn
    case 'usage_code':
      return RX_IDS.usage
    case 'usage_other':
      return RX_IDS.usageOther
    case 'lens_type_code':
      return RX_IDS.lensType
    case 'lens_type_other':
      return RX_IDS.lensTypeOther
    case 'lens_index':
      return RX_IDS.lensIndex
    case 'lens_index_other':
      return RX_IDS.lensIndexOther
    case 'pd_total':
      return RX_IDS.pdTotal
    case 'pd_od':
      return RX_IDS.pdOd
    case 'pd_og':
      return RX_IDS.pdOg
    case 'pd_inconsistency_reason':
      return RX_IDS.pdReason
    case 'notes':
      return RX_IDS.notes
    default:
      return RX_IDS.form
  }
}

const CONTENT_LABELS: Readonly<Record<keyof PrescriptionContent, string>> = {
  external_prescriber: 'Prescripteur',
  external_prescribed_on: 'Date de l’ordonnance',
  external_file_id: 'Fichier de l’ordonnance',
  usage_code: 'Usage',
  usage_other: 'Précision de l’usage',
  lens_type_code: 'Type de verre',
  lens_type_other: 'Précision du type de verre',
  lens_index: 'Indice',
  lens_index_other: 'Précision de l’indice',
  pd_total: 'Écart pupillaire total',
  pd_od: 'Écart pupillaire OD',
  pd_og: 'Écart pupillaire OG',
  pd_inconsistency_reason: 'Justification de l’écart',
  notes: 'Observations',
  eyes: 'Correction par œil',
  treatments: 'Traitements',
}

function summarize(key: keyof PrescriptionContent, value: PrescriptionContent[keyof PrescriptionContent]): string {
  if (value === null) return 'vide'
  if (key === 'eyes') {
    return (value as PrescriptionEyeData[])
      .map((eye) => {
        const parts = eye.no_correction
          ? ['aucune correction']
          : [eye.sphere, eye.cylinder, eye.axis === null ? null : `${eye.axis}°`, eye.addition].map((part) => part ?? '—')
        return `${eye.eye} ${parts.join(' ')}${eye.fitting_height === null ? '' : ` · hauteur ${eye.fitting_height}`}`
      })
      .join(' ; ')
  }
  if (key === 'treatments') {
    const list = value as TermData[]
    return list.length === 0 ? 'aucun' : list.map((term) => term.term_code).join(', ')
  }
  return String(value)
}

export interface PrescriptionDifference {
  key: keyof PrescriptionContent
  label: string
  mine: string
  current: string
}

/**
 * Champs que ma saisie et la version actuelle du serveur n'ont pas en commun (conflit de brouillon) : montrés avant
 * tout nouvel enregistrement, pour qu'aucune modification d'une autre personne ne soit écrasée en silence.
 */
export function prescriptionDifferences(
  mine: PrescriptionDraft,
  current: PrescriptionDraft,
): PrescriptionDifference[] {
  const a = prescriptionContent(mine)
  const b = prescriptionContent(current)
  const differences: PrescriptionDifference[] = []
  for (const key of Object.keys(CONTENT_LABELS) as (keyof PrescriptionContent)[]) {
    const strip = (content: PrescriptionContent): unknown =>
      key === 'eyes' ? content.eyes.map((eye) => ({ ...eye, id: '' })) : key === 'treatments' ? content.treatments.map((term) => ({ ...term, id: '' })) : content[key]
    if (JSON.stringify(strip(a)) !== JSON.stringify(strip(b))) {
      differences.push({ key, label: CONTENT_LABELS[key], mine: summarize(key, a[key]), current: summarize(key, b[key]) })
    }
  }
  return differences
}
