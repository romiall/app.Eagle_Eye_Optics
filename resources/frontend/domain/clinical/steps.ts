import { RuleViolation } from '../errors'
import { trimBlanks } from '../values/text'
import { newUlid } from '../values/ulid'
import { CONTROL_IDS } from './controls'
import {
  type DeliveredStep,
  HISTORY_GROUP_LABELS,
  HISTORY_GROUPS,
  type HistoryGroup,
  isUrgency,
  OTHER_PRECISION_LABELS,
  type Urgency,
} from './labels'
import { NONE_CODE, OTHER_CODE, termRank, type TermGroup } from './terms'

/**
 * Rubriques narratives du dossier DP (C01 ; fichier 02, « Consultation et antécédents » ; DP §4, §5
 * et §10) : ANAMNESIS (motifs, symptômes, lunettes), HISTORIES (antécédents) et CONCLUSION
 * (conclusion, orientation). Deux formes :
 *
 * - le BROUILLON (`*Draft`) que l'écran modifie : textes bruts, lignes de termes triées dans l'ordre
 *   du référentiel, un identifiant ULID par ligne créé sur le poste ;
 * - la DONNÉE (`*Data`) envoyée à `SaveClinicalStep` : forme exacte du contrat
 *   (`contracts/schemas/clinical-steps.json`), champs vides à null (jamais une chaîne vide).
 *
 * L'étape est REMPLACÉE en entier à chaque enregistrement : une ligne absente est supprimée par le
 * serveur. Les règles de `validateStep` sont celles du serveur ; une règle de plus ne s'invente pas
 * ici. Règles pures : ni Vue, ni réseau, ni stockage.
 */
export const TEXT_MAX_LENGTH = 4000
/** Plafonds du schéma `clinical-steps.json`. */
export const COMPLAINTS_MAX = 50
export const HISTORY_TERMS_MAX = 50
export const REFERRAL_REASONS_MAX = 20

export type NewId = () => string

// --- Forme du contrat -----------------------------------------------------------------------------

export interface TermData {
  id: string
  term_code: string
  other_text: string | null
}

export interface AnamnesisData {
  symptoms: string | null
  wears_glasses: boolean | null
  glasses_duration: string | null
  previous_correction_available: boolean | null
  complaints: TermData[]
}

export interface HistoryData {
  id: string
  history_group: HistoryGroup
  known_none: boolean
  comments: string | null
  terms: TermData[]
}

export interface HistoriesData {
  histories: HistoryData[]
}

export interface ConclusionData {
  conclusion: string | null
  referral: boolean | null
  referral_urgency: Urgency | null
  referral_reasons: TermData[]
}

export interface StepDataByName {
  ANAMNESIS: AnamnesisData
  HISTORIES: HistoriesData
  CONCLUSION: ConclusionData
}

// --- Brouillons -----------------------------------------------------------------------------------

/** Une valeur cochée : identifiant de la ligne (créé sur le poste), code du terme, précision. */
export interface TermRow {
  id: string
  code: string
  otherText: string
}

export interface AnamnesisDraft {
  complaints: TermRow[]
  symptoms: string
  wearsGlasses: boolean | null
  glassesDuration: string
  previousCorrectionAvailable: boolean | null
}

export interface HistoryGroupDraft {
  /** Identifiant de la ligne `consultation_histories` si le groupe est enregistré. */
  id: string
  knownNone: boolean
  terms: TermRow[]
  comments: string
}

export type HistoriesDraft = Record<HistoryGroup, HistoryGroupDraft>

export interface ConclusionDraft {
  conclusion: string
  referral: boolean | null
  urgency: Urgency | null
  reasons: TermRow[]
}

export interface StepDrafts {
  ANAMNESIS: AnamnesisDraft
  HISTORIES: HistoriesDraft
  CONCLUSION: ConclusionDraft
}

// --- Textes ---------------------------------------------------------------------------------------

/** Texte facultatif : fins de ligne en « \n », blancs de bord retirés, vide → null. */
export function cleanText(value: string): string | null {
  const text = value.replace(/\r\n?/g, '\n')
  let trimmed: string
  try {
    trimmed = trimBlanks(text)
  } catch (error) {
    // Caractère isolé invalide : `validateStep` le signale ; la donnée reste constructible.
    if (!(error instanceof RuleViolation)) throw error
    trimmed = text.trim()
  }
  return trimmed === '' ? null : trimmed
}

function textError(value: string, subject: string): string | null {
  try {
    const trimmed = trimBlanks(value.replace(/\r\n?/g, '\n'))
    return [...trimmed].length > TEXT_MAX_LENGTH
      ? `${subject} : le texte ne peut pas dépasser ${TEXT_MAX_LENGTH} caractères.`
      : null
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    return `${subject} : le texte contient un caractère non valide.`
  }
}

// --- Lignes de termes -----------------------------------------------------------------------------

/** Lignes dans l'ordre du référentiel ; un code inconnu vient après les autres, par ordre alphabétique. */
export function sortRows(group: TermGroup, rows: readonly TermRow[]): TermRow[] {
  return [...rows].sort((left, right) => {
    const rank = termRank(group, left.code) - termRank(group, right.code)
    if (rank !== 0) return rank
    return left.code < right.code ? -1 : left.code > right.code ? 1 : 0
  })
}

/** Coche ou décoche un terme ; une ligne déjà cochée garde son identifiant et sa précision. */
export function toggleTerm(
  group: TermGroup,
  rows: readonly TermRow[],
  code: string,
  checked: boolean,
  newId: NewId = newUlid,
): TermRow[] {
  const existing = rows.find((row) => row.code === code)
  if (!checked) return rows.filter((row) => row.code !== code)
  if (existing !== undefined) return [...rows]
  return sortRows(group, [...rows, { id: newId(), code, otherText: '' }])
}

/** Remplace la précision de la ligne « Autre » (ou de tout terme qui en porte une). */
export function setOtherText(rows: readonly TermRow[], code: string, text: string): TermRow[] {
  return rows.map((row) => (row.code === code ? { ...row, otherText: text } : row))
}

function rowsFromData(group: TermGroup, terms: readonly TermData[] | undefined): TermRow[] {
  const seen = new Set<string>()
  const rows: TermRow[] = []
  for (const term of terms ?? []) {
    if (term.term_code === NONE_CODE || seen.has(term.term_code)) continue
    seen.add(term.term_code)
    rows.push({ id: term.id, code: term.term_code, otherText: term.other_text ?? '' })
  }
  return sortRows(group, rows)
}

function termsData(rows: readonly TermRow[]): TermData[] {
  return rows.map((row) => ({
    id: row.id,
    term_code: row.code,
    other_text: row.code === OTHER_CODE ? cleanText(row.otherText) : null,
  }))
}

interface TermsCheck {
  path: string
  group: TermGroup
  rows: readonly TermRow[]
  max: number
  listControl: string
  otherControl: string
  otherSubject: string
}

function termErrors(check: TermsCheck): StepError[] {
  const errors: StepError[] = []
  if (check.rows.length > check.max) {
    errors.push({
      path: check.path,
      controlId: check.listControl,
      message: `Pas plus de ${check.max} valeurs dans cette liste.`,
    })
  }
  check.rows.forEach((row, index) => {
    if (row.code !== OTHER_CODE) return
    const path = `${check.path}.${index}.other_text`
    if (cleanText(row.otherText) === null) {
      errors.push({
        path,
        controlId: check.otherControl,
        message: `${check.otherSubject} : précisez la valeur « Autre ».`,
      })
      return
    }
    const message = textError(row.otherText, 'La précision')
    if (message !== null) errors.push({ path, controlId: check.otherControl, message })
  })
  return errors
}

// --- Erreurs --------------------------------------------------------------------------------------

/**
 * Erreur d'une étape : `path` est la clé pointée de la donnée envoyée (`complaints.0.other_text`),
 * comme les clés de `error.fields` du serveur (sans le préfixe `step.data.`) ; `controlId` est le
 * contrôle à atteindre.
 */
export interface StepError {
  path: string
  controlId: string
  message: string
}

export type StepValidation<D> = { ok: true; data: D } | { ok: false; errors: StepError[] }

// --- ANAMNESIS ------------------------------------------------------------------------------------

export function blankAnamnesis(): AnamnesisDraft {
  return {
    complaints: [],
    symptoms: '',
    wearsGlasses: null,
    glassesDuration: '',
    previousCorrectionAvailable: null,
  }
}

export function anamnesisDraft(data: AnamnesisData): AnamnesisDraft {
  return {
    complaints: rowsFromData('COMPLAINT', data.complaints),
    symptoms: data.symptoms ?? '',
    wearsGlasses: data.wears_glasses ?? null,
    glassesDuration: data.glasses_duration ?? '',
    previousCorrectionAvailable: data.previous_correction_available ?? null,
  }
}

/**
 * Donnée envoyée. La durée et l'ancienne correction n'existent que pour une personne qui porte des
 * lunettes : sinon elles partent à null (le serveur refuse le contraire), même si l'écran les garde
 * en mémoire au cas où la réponse « Oui » serait rétablie.
 */
export function anamnesisData(draft: AnamnesisDraft): AnamnesisData {
  const wears = draft.wearsGlasses === true
  return {
    symptoms: cleanText(draft.symptoms),
    wears_glasses: draft.wearsGlasses,
    glasses_duration: wears ? cleanText(draft.glassesDuration) : null,
    previous_correction_available: wears ? draft.previousCorrectionAvailable : null,
    complaints: termsData(draft.complaints),
  }
}

/** Vrai si des réponses sur les lunettes sont saisies mais ne seront pas envoyées (porteur ≠ « Oui »). */
export function glassesAnswersIgnored(draft: AnamnesisDraft): boolean {
  return (
    draft.wearsGlasses !== true &&
    (cleanText(draft.glassesDuration) !== null || draft.previousCorrectionAvailable !== null)
  )
}

function anamnesisErrors(draft: AnamnesisDraft): StepError[] {
  const errors: StepError[] = []
  const symptoms = textError(draft.symptoms, 'Les plaintes et symptômes')
  if (symptoms !== null) {
    errors.push({ path: 'symptoms', controlId: CONTROL_IDS.symptoms, message: symptoms })
  }
  if (draft.wearsGlasses === true) {
    const duration = textError(draft.glassesDuration, 'La durée')
    if (duration !== null) {
      errors.push({
        path: 'glasses_duration',
        controlId: CONTROL_IDS.glassesDuration,
        message: duration,
      })
    }
  }
  errors.push(
    ...termErrors({
      path: 'complaints',
      group: 'COMPLAINT',
      rows: draft.complaints,
      max: COMPLAINTS_MAX,
      listControl: CONTROL_IDS.complaints,
      otherControl: CONTROL_IDS.complaintOther,
      otherSubject: 'Motif',
    }),
  )
  return errors
}

// --- HISTORIES ------------------------------------------------------------------------------------

export function blankHistoryGroup(newId: NewId = newUlid): HistoryGroupDraft {
  return { id: newId(), knownNone: false, terms: [], comments: '' }
}

export function blankHistories(newId: NewId = newUlid): HistoriesDraft {
  return {
    OCULAR_HISTORY: blankHistoryGroup(newId),
    MEDICAL_HISTORY: blankHistoryGroup(newId),
    FAMILY_HISTORY: blankHistoryGroup(newId),
  }
}

/**
 * Un groupe est « renseigné » s'il a « Aucun connu », une valeur ou un commentaire. Un groupe jamais
 * ouvert reste « non renseigné » : il n'est pas envoyé, et surtout pas comme « Aucun connu ».
 */
export function isHistoryGroupFilled(group: HistoryGroupDraft): boolean {
  return group.knownNone || group.terms.length > 0 || cleanText(group.comments) !== null
}

export function historiesDraft(data: HistoriesData, newId: NewId = newUlid): HistoriesDraft {
  const draft = blankHistories(newId)
  for (const history of data.histories ?? []) {
    if (!(HISTORY_GROUPS as readonly string[]).includes(history.history_group)) continue
    draft[history.history_group] = {
      id: history.id,
      knownNone: history.known_none,
      terms: rowsFromData(history.history_group, history.terms),
      comments: history.comments ?? '',
    }
  }
  return draft
}

export function historiesData(draft: HistoriesDraft): HistoriesData {
  return {
    histories: HISTORY_GROUPS.filter((group) => isHistoryGroupFilled(draft[group])).map(
      (group) => ({
        id: draft[group].id,
        history_group: group,
        known_none: draft[group].knownNone,
        comments: cleanText(draft[group].comments),
        terms: termsData(draft[group].terms),
      }),
    ),
  }
}

function historiesErrors(draft: HistoriesDraft): StepError[] {
  const errors: StepError[] = []
  const filled = HISTORY_GROUPS.filter((group) => isHistoryGroupFilled(draft[group]))
  filled.forEach((group, index) => {
    const value = draft[group]
    const title = HISTORY_GROUP_LABELS[group]
    const base = `histories.${index}`
    if (value.knownNone && value.terms.length > 0) {
      errors.push({
        path: `${base}.known_none`,
        controlId: CONTROL_IDS.historyNone(group),
        message: `${title} : « Aucun connu » ne se combine avec aucune autre valeur du groupe.`,
      })
    }
    errors.push(
      ...termErrors({
        path: `${base}.terms`,
        group,
        rows: value.terms,
        max: HISTORY_TERMS_MAX,
        listControl: CONTROL_IDS.historyGroup(group),
        otherControl: CONTROL_IDS.historyOther(group),
        otherSubject: title,
      }),
    )
    const comments = textError(value.comments, `Le commentaire (${title.toLowerCase()})`)
    if (comments !== null) {
      errors.push({
        path: `${base}.comments`,
        controlId: CONTROL_IDS.historyComments(group),
        message: comments,
      })
    }
  })
  return errors
}

// --- CONCLUSION -----------------------------------------------------------------------------------

export function blankConclusion(): ConclusionDraft {
  return { conclusion: '', referral: null, urgency: null, reasons: [] }
}

export function conclusionDraft(data: ConclusionData): ConclusionDraft {
  return {
    conclusion: data.conclusion ?? '',
    referral: data.referral ?? null,
    urgency:
      data.referral_urgency !== null && isUrgency(data.referral_urgency)
        ? data.referral_urgency
        : null,
    reasons: rowsFromData('REFERRAL_REASON', data.referral_reasons),
  }
}

/**
 * Donnée envoyée. Le niveau d'urgence et les motifs n'existent que pour une orientation « Oui » :
 * sinon ils partent vides (le serveur refuse le contraire), l'écran les garde en mémoire.
 */
export function conclusionData(draft: ConclusionDraft): ConclusionData {
  const referred = draft.referral === true
  return {
    conclusion: cleanText(draft.conclusion),
    referral: draft.referral,
    referral_urgency: referred ? draft.urgency : null,
    referral_reasons: referred ? termsData(draft.reasons) : [],
  }
}

/** Vrai si un niveau ou des motifs d'orientation sont saisis mais ne seront pas envoyés (orientation ≠ « Oui »). */
export function referralAnswersIgnored(draft: ConclusionDraft): boolean {
  return draft.referral !== true && (draft.urgency !== null || draft.reasons.length > 0)
}

function conclusionErrors(draft: ConclusionDraft): StepError[] {
  const errors: StepError[] = []
  const conclusion = textError(draft.conclusion, 'La conclusion')
  if (conclusion !== null) {
    errors.push({ path: 'conclusion', controlId: CONTROL_IDS.conclusion, message: conclusion })
  }
  if (draft.referral === true) {
    if (draft.urgency === null) {
      errors.push({
        path: 'referral_urgency',
        controlId: CONTROL_IDS.urgency,
        message: 'Le niveau d’urgence est obligatoire quand une orientation est proposée.',
      })
    }
    errors.push(
      ...termErrors({
        path: 'referral_reasons',
        group: 'REFERRAL_REASON',
        rows: draft.reasons,
        max: REFERRAL_REASONS_MAX,
        listControl: CONTROL_IDS.reasons,
        otherControl: CONTROL_IDS.reasonOther,
        otherSubject: 'Motif d’orientation',
      }),
    )
  }
  return errors
}

// --- Accès par nom d'étape ------------------------------------------------------------------------

interface StepCodec<D, W> {
  blank(newId: NewId): D
  fromData(data: W, newId: NewId): D
  toData(draft: D): W
  errors(draft: D): StepError[]
}

const CODECS: { [S in DeliveredStep]: StepCodec<StepDrafts[S], StepDataByName[S]> } = {
  ANAMNESIS: {
    blank: () => blankAnamnesis(),
    fromData: (data) => anamnesisDraft(data),
    toData: anamnesisData,
    errors: anamnesisErrors,
  },
  HISTORIES: {
    blank: (newId) => blankHistories(newId),
    fromData: (data, newId) => historiesDraft(data, newId),
    toData: historiesData,
    errors: historiesErrors,
  },
  CONCLUSION: {
    blank: () => blankConclusion(),
    fromData: (data) => conclusionDraft(data),
    toData: conclusionData,
    errors: conclusionErrors,
  },
}

function codecOf<S extends DeliveredStep>(step: S): StepCodec<StepDrafts[S], StepDataByName[S]> {
  return CODECS[step] as StepCodec<StepDrafts[S], StepDataByName[S]>
}

export function blankDrafts(newId: NewId = newUlid): StepDrafts {
  return {
    ANAMNESIS: CODECS.ANAMNESIS.blank(newId),
    HISTORIES: CODECS.HISTORIES.blank(newId),
    CONCLUSION: CODECS.CONCLUSION.blank(newId),
  }
}

/** Brouillon d'une étape à partir de ce que le serveur a enregistré. */
export function draftFromData<S extends DeliveredStep>(
  step: S,
  data: StepDataByName[S],
  newId: NewId = newUlid,
): StepDrafts[S] {
  return codecOf(step).fromData(data, newId)
}

/** Donnée à envoyer pour un brouillon (sans contrôle : voir `validateStep`). */
export function buildStepData<S extends DeliveredStep>(
  step: S,
  draft: StepDrafts[S],
): StepDataByName[S] {
  return codecOf(step).toData(draft)
}

/** Règles de l'étape (les mêmes que le serveur) ; sinon la donnée à envoyer. */
export function validateStep<S extends DeliveredStep>(
  step: S,
  draft: StepDrafts[S],
): StepValidation<StepDataByName[S]> {
  const codec = codecOf(step)
  const errors = codec.errors(draft)
  return errors.length > 0 ? { ok: false, errors } : { ok: true, data: codec.toData(draft) }
}

const NO_ID: NewId = () => ''

/**
 * Vrai si le brouillon diffère de ce que le serveur a enregistré. Les deux côtés passent par la même
 * normalisation (blancs de bord, lignes triées) : un texte ressaisi à l'identique n'est pas une
 * modification.
 */
export function isStepDirty<S extends DeliveredStep>(
  step: S,
  draft: StepDrafts[S],
  saved: StepDataByName[S],
): boolean {
  const codec = codecOf(step)
  return (
    JSON.stringify(codec.toData(draft)) !==
    JSON.stringify(codec.toData(codec.fromData(saved, NO_ID)))
  )
}

/** Précisions « Autre » : libellés propres à chaque liste. */
export function otherPrecisionLabel(group: TermGroup): string {
  return OTHER_PRECISION_LABELS[group]
}
