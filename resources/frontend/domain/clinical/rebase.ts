import { newUlid } from '../values/ulid'
import { ACUITY_SLOTS, acuitySlotKey, type AcuityRowData, CORRECTION_TITLES, type VisualAcuityData } from './acuity'
import type { CenteringData } from './centering'
import { axisData, axisText } from './fields'
import type { MedicationsData, OrderData } from './medications'
import { REFRACTION_EYES, REFRACTION_STAGES, type MeasurementData, type RefractionData, type RefractionEye, type RefractionStage } from './refraction'
import {
  type DeliveredStep,
  DELIVERED_STEPS,
  EYE_SHORT_LABELS,
  HISTORY_GROUP_LABELS,
  HISTORY_GROUPS,
  type HistoryGroup,
  isUrgency,
  STAGE_LABELS,
  URGENCY_LABELS,
  type Urgency,
} from './labels'
import {
  type AnamnesisData,
  buildStepData,
  type ConclusionData,
  draftFromData,
  type HistoriesData,
  type HistoryData,
  isStepDirty,
  type NewId,
  sortRows,
  type StepDataByName,
  type StepDrafts,
  type TermData,
} from './steps'
import { OTHER_CODE, termLabel, type TermGroup } from './terms'

/**
 * Reprise d'une saisie après un conflit de version (AT19, AT42 : aucune proposition écrasée en
 * silence ; même principe que `domain/patients/rebase.ts`). Trois états entrent en jeu pour chaque
 * étape : celui que la personne a ouvert (`base`), celui qui est maintenant sur le serveur (`latest`)
 * et sa saisie (`current`).
 *
 * - Une valeur qu'elle n'a pas touchée reprend la valeur actuelle du serveur : le travail d'un autre
 *   poste n'est pas annulé.
 * - Une valeur qu'elle a modifiée garde sa saisie.
 * - Si cette valeur a AUSSI été modifiée sur le serveur (autrement que vers la même valeur), elle est
 *   signalée avec la valeur actuelle avant tout nouvel enregistrement.
 *
 * Une « valeur » est un atome : un texte, une réponse oui/non, un niveau, ou UN terme coché. Cocher
 * « Glaucome » sur un poste et « Cataracte » sur un autre se combine donc sans conflit.
 */
export type Atoms = ReadonlyMap<string, string>

export interface RebaseConflict {
  key: string
  /** Rubrique en clair (« Antécédents oculaires : Glaucome »). */
  label: string
  /** Valeur actuelle du serveur, en clair. */
  current: string
}

export interface RebasedStep<S extends DeliveredStep> {
  draft: StepDrafts[S]
  conflicts: RebaseConflict[]
}

const YES = 'oui'
const NO = 'non'

function yesNoAtom(value: boolean | null): string | undefined {
  return value === null ? undefined : value ? YES : NO
}

function yesNoFromAtom(value: string | undefined): boolean | null {
  return value === YES ? true : value === NO ? false : null
}

function put(atoms: Map<string, string>, key: string, value: string | null | undefined): void {
  if (value !== null && value !== undefined) atoms.set(key, value)
}

function termAtoms(atoms: Map<string, string>, prefix: string, terms: readonly TermData[]): void {
  for (const term of terms) atoms.set(`${prefix}${term.term_code}`, term.other_text ?? '')
}

function termsFromAtoms(
  group: TermGroup,
  prefix: string,
  atoms: Atoms,
  ids: Atoms,
  newId: NewId,
): TermData[] {
  const rows = [...atoms.keys()]
    .filter((key) => key.startsWith(prefix))
    .map((key) => ({
      id: ids.get(key) ?? newId(),
      code: key.slice(prefix.length),
      otherText: atoms.get(key) ?? '',
    }))
  return sortRows(group, rows).map((row) => ({
    id: row.id,
    term_code: row.code,
    other_text: row.code === OTHER_CODE && row.otherText !== '' ? row.otherText : null,
  }))
}

// --- Atomes par étape -----------------------------------------------------------------------------

function anamnesisAtoms(data: AnamnesisData): Map<string, string> {
  const atoms = new Map<string, string>()
  put(atoms, 'symptoms', data.symptoms)
  put(atoms, 'wears_glasses', yesNoAtom(data.wears_glasses))
  put(atoms, 'glasses_duration', data.glasses_duration)
  put(atoms, 'previous_correction_available', yesNoAtom(data.previous_correction_available))
  termAtoms(atoms, 'complaint:', data.complaints)
  return atoms
}

function anamnesisFromAtoms(atoms: Atoms, ids: Atoms, newId: NewId): AnamnesisData {
  return {
    symptoms: atoms.get('symptoms') ?? null,
    wears_glasses: yesNoFromAtom(atoms.get('wears_glasses')),
    glasses_duration: atoms.get('glasses_duration') ?? null,
    previous_correction_available: yesNoFromAtom(atoms.get('previous_correction_available')),
    complaints: termsFromAtoms('COMPLAINT', 'complaint:', atoms, ids, newId),
  }
}

function anamnesisIds(data: AnamnesisData, ids: Map<string, string>): void {
  for (const term of data.complaints) setIfAbsent(ids, `complaint:${term.term_code}`, term.id)
}

function historiesAtoms(data: HistoriesData): Map<string, string> {
  const atoms = new Map<string, string>()
  for (const history of data.histories) {
    const group = history.history_group
    if (history.known_none) atoms.set(`${group}|none`, YES)
    put(atoms, `${group}|comments`, history.comments)
    termAtoms(atoms, `${group}|term:`, history.terms)
  }
  return atoms
}

function historiesFromAtoms(atoms: Atoms, ids: Atoms, newId: NewId): HistoriesData {
  const histories: HistoryData[] = []
  for (const group of HISTORY_GROUPS) {
    const knownNone = atoms.get(`${group}|none`) === YES
    const comments = atoms.get(`${group}|comments`) ?? null
    const terms = termsFromAtoms(group, `${group}|term:`, atoms, ids, newId)
    if (!knownNone && comments === null && terms.length === 0) continue
    histories.push({
      id: ids.get(`${group}|id`) ?? newId(),
      history_group: group,
      known_none: knownNone,
      comments,
      terms,
    })
  }
  return { histories }
}

function historiesIds(data: HistoriesData, ids: Map<string, string>): void {
  for (const history of data.histories) {
    const group: HistoryGroup = history.history_group
    setIfAbsent(ids, `${group}|id`, history.id)
    for (const term of history.terms) setIfAbsent(ids, `${group}|term:${term.term_code}`, term.id)
  }
}

/**
 * « Aucun connu » exclut les autres valeurs du groupe. Si la fusion les réunit (une personne a coché
 * « Aucun connu », une autre une valeur), c'est « Aucun connu » qui est retiré et signalé : une valeur
 * saisie n'est jamais perdue en silence.
 */
function repairHistories(atoms: Map<string, string>): string[] {
  const repaired: string[] = []
  for (const group of HISTORY_GROUPS) {
    const hasTerm = [...atoms.keys()].some((key) => key.startsWith(`${group}|term:`))
    if (hasTerm && atoms.has(`${group}|none`)) {
      atoms.delete(`${group}|none`)
      repaired.push(`${group}|none`)
    }
  }
  return repaired
}

function conclusionAtoms(data: ConclusionData): Map<string, string> {
  const atoms = new Map<string, string>()
  put(atoms, 'conclusion', data.conclusion)
  put(atoms, 'referral', yesNoAtom(data.referral))
  put(atoms, 'referral_urgency', data.referral_urgency)
  termAtoms(atoms, 'reason:', data.referral_reasons)
  return atoms
}

function conclusionFromAtoms(atoms: Atoms, ids: Atoms, newId: NewId): ConclusionData {
  const urgency = atoms.get('referral_urgency') ?? null
  return {
    conclusion: atoms.get('conclusion') ?? null,
    referral: yesNoFromAtom(atoms.get('referral')),
    referral_urgency: urgency !== null && isUrgency(urgency) ? (urgency as Urgency) : null,
    referral_reasons: termsFromAtoms('REFERRAL_REASON', 'reason:', atoms, ids, newId),
  }
}

function conclusionIds(data: ConclusionData, ids: Map<string, string>): void {
  for (const term of data.referral_reasons) setIfAbsent(ids, `reason:${term.term_code}`, term.id)
}

function setIfAbsent(ids: Map<string, string>, key: string, id: string): void {
  if (!ids.has(key)) ids.set(key, id)
}

// --- Atomes des rubriques mesurées (C02) et des médicaments (C04) -------------------------------------

function acuityAtoms(data: VisualAcuityData): Map<string, string> {
  const atoms = new Map<string, string>()
  for (const row of data.acuities) {
    const key = acuitySlotKey(row.context, row.distance, row.correction, row.eye)
    atoms.set(`${key}::value`, row.raw_value)
    if (row.scale_id !== '') atoms.set(`${key}::scale`, row.scale_id)
  }
  return atoms
}

function acuityFromAtoms(atoms: Atoms, ids: Atoms, newId: NewId): VisualAcuityData {
  const acuities: AcuityRowData[] = []
  for (const slot of ACUITY_SLOTS) {
    const value = atoms.get(`${slot.key}::value`)
    if (value === undefined) continue
    acuities.push({
      id: ids.get(`${slot.key}::id`) ?? newId(),
      context: slot.context,
      distance: slot.distance,
      correction: slot.correction,
      eye: slot.eye,
      scale_id: atoms.get(`${slot.key}::scale`) ?? '',
      raw_value: value,
      normalized_value: ids.get(`${slot.key}::normalized`) ?? null,
    })
  }
  return { acuities }
}

function acuityIds(data: VisualAcuityData, ids: Map<string, string>): void {
  for (const row of data.acuities) {
    const key = acuitySlotKey(row.context, row.distance, row.correction, row.eye)
    setIfAbsent(ids, `${key}::id`, row.id)
    if (row.normalized_value !== null) setIfAbsent(ids, `${key}::normalized`, row.normalized_value)
  }
}

const MEASURE_FIELDS = ['sphere', 'cylinder', 'axis', 'addition'] as const

function refractionAtoms(data: RefractionData): Map<string, string> {
  const atoms = new Map<string, string>()
  for (const row of data.measurements) {
    const prefix = `${row.stage}|${row.eye}::`
    put(atoms, `${prefix}sphere`, row.sphere)
    put(atoms, `${prefix}cylinder`, row.cylinder)
    put(atoms, `${prefix}axis`, row.axis === null ? null : axisText(row.axis))
    put(atoms, `${prefix}addition`, row.addition)
  }
  put(atoms, 'observations', data.refraction_observations)
  termAtoms(atoms, 'ametropia:', data.ametropias)
  return atoms
}

function refractionFromAtoms(atoms: Atoms, ids: Atoms, newId: NewId): RefractionData {
  const measurements: MeasurementData[] = []
  for (const stage of REFRACTION_STAGES) {
    for (const eye of REFRACTION_EYES) {
      const prefix = `${stage}|${eye}::`
      if (!MEASURE_FIELDS.some((field) => atoms.has(`${prefix}${field}`))) continue
      measurements.push({
        id: ids.get(`${prefix}id`) ?? newId(),
        stage,
        eye,
        sphere: atoms.get(`${prefix}sphere`) ?? null,
        cylinder: atoms.get(`${prefix}cylinder`) ?? null,
        axis: axisData(atoms.get(`${prefix}axis`) ?? ''),
        addition: atoms.get(`${prefix}addition`) ?? null,
      })
    }
  }
  return {
    measurements,
    refraction_observations: atoms.get('observations') ?? null,
    ametropias: termsFromAtoms('AMETROPIA', 'ametropia:', atoms, ids, newId),
  }
}

function refractionIds(data: RefractionData, ids: Map<string, string>): void {
  for (const row of data.measurements) setIfAbsent(ids, `${row.stage}|${row.eye}::id`, row.id)
  for (const term of data.ametropias) setIfAbsent(ids, `ametropia:${term.term_code}`, term.id)
}

function centeringAtoms(data: CenteringData): Map<string, string> {
  const atoms = new Map<string, string>()
  put(atoms, 'pd_total', data.pd_total)
  put(atoms, 'pd_od', data.pd_od)
  put(atoms, 'pd_og', data.pd_og)
  put(atoms, 'reason', data.inconsistency_reason)
  return atoms
}

function centeringFromAtoms(atoms: Atoms, ids: Atoms, newId: NewId): CenteringData {
  const any = ['pd_total', 'pd_od', 'pd_og', 'reason'].some((key) => atoms.has(key))
  return {
    centering_id: any ? (ids.get('id') ?? newId()) : null,
    pd_total: atoms.get('pd_total') ?? null,
    pd_od: atoms.get('pd_od') ?? null,
    pd_og: atoms.get('pd_og') ?? null,
    inconsistency_reason: atoms.get('reason') ?? null,
  }
}

function centeringIds(data: CenteringData, ids: Map<string, string>): void {
  if (data.centering_id !== null) setIfAbsent(ids, 'id', data.centering_id)
}

/**
 * Médicaments : une valeur = UNE ligne entière (identifiant, contenu en JSON stable) ; l'ordre est un atome à part.
 * Deux personnes qui ajoutent chacune un médicament les gardent tous les deux ; une ligne modifiée des deux
 * côtés est signalée ; l'ordre repris est celui de la personne qui l'a changé (le sien si les deux l'ont fait).
 */
function medicationsAtoms(data: MedicationsData): Map<string, string> {
  const atoms = new Map<string, string>()
  for (const order of data.orders) atoms.set(`order:${order.id}`, JSON.stringify(order))
  if (data.orders.length > 0) atoms.set('sequence', data.orders.map((order) => order.id).join(','))
  return atoms
}

function medicationsFromAtoms(atoms: Atoms): MedicationsData {
  const byId = new Map<string, OrderData>()
  for (const [key, value] of atoms) {
    if (!key.startsWith('order:')) continue
    try {
      const order = JSON.parse(value) as OrderData
      byId.set(key.slice('order:'.length), order)
    } catch {
      // Une ligne illisible ne s'invente pas : elle est écartée (jamais le cas d'une ligne produite par ce module).
    }
  }
  const orders: OrderData[] = []
  for (const id of (atoms.get('sequence') ?? '').split(',')) {
    const order = byId.get(id)
    if (order !== undefined) {
      orders.push(order)
      byId.delete(id)
    }
  }
  orders.push(...byId.values())
  return { orders }
}

// --- Libellés des atomes (messages de conflit) ----------------------------------------------------

function atomLabel(step: DeliveredStep, key: string): string {
  if (step === 'ANAMNESIS') {
    if (key.startsWith('complaint:')) {
      return `Motif « ${termLabel('COMPLAINT', key.slice('complaint:'.length))} »`
    }
    switch (key) {
      case 'symptoms':
        return 'Plaintes et symptômes'
      case 'wears_glasses':
        return 'Porte actuellement des lunettes'
      case 'glasses_duration':
        return 'Durée du port de lunettes'
      case 'previous_correction_available':
        return 'Ancienne correction disponible'
      default:
        return key
    }
  }
  if (step === 'HISTORIES') {
    const [group, rest = ''] = key.split('|')
    const title = HISTORY_GROUP_LABELS[group as HistoryGroup] ?? group ?? key
    if (rest === 'none') return `${title} : Aucun connu`
    if (rest === 'comments') return `${title} : commentaires`
    if (rest.startsWith('term:')) {
      return `${title} : ${termLabel(group as TermGroup, rest.slice('term:'.length))}`
    }
    return key
  }
  if (step === 'VISUAL_ACUITY') {
    const [slot = '', field = ''] = key.split('::')
    const [context = '', distance = '', correction = '', eye = ''] = slot.split('|')
    const when =
      context === 'POST_REFRACTION'
        ? 'Acuité après correction'
        : `${distance === 'FAR' ? 'Acuité de loin' : 'Acuité de près'}, ${CORRECTION_TITLES[correction === 'WITH' ? 'WITH' : 'WITHOUT'].toLowerCase()}`
    const where = EYE_SHORT_LABELS[eye as keyof typeof EYE_SHORT_LABELS] ?? eye
    return `${when}, ${where} : ${field === 'scale' ? 'échelle' : 'valeur lue'}`
  }
  if (step === 'REFRACTION') {
    if (key.startsWith('ametropia:')) {
      return `Amétropie « ${termLabel('AMETROPIA', key.slice('ametropia:'.length))} »`
    }
    if (key === 'observations') return 'Observations de réfraction'
    const [slot = '', field = ''] = key.split('::')
    const [stage = '', eye = ''] = slot.split('|')
    const names: Record<string, string> = { sphere: 'sphère', cylinder: 'cylindre', axis: 'axe', addition: 'addition' }
    return `${STAGE_LABELS[stage as RefractionStage] ?? stage}, ${EYE_SHORT_LABELS[eye as RefractionEye] ?? eye} : ${names[field] ?? field}`
  }
  if (step === 'CENTERING') {
    const names: Record<string, string> = {
      pd_total: 'Écart pupillaire total',
      pd_od: 'Écart pupillaire OD',
      pd_og: 'Écart pupillaire OG',
      reason: 'Justification de l’écart',
    }
    return names[key] ?? key
  }
  if (step === 'MEDICATIONS') {
    return key === 'sequence' ? 'Ordre des médicaments' : `Médicament (${key.slice('order:'.length)})`
  }
  if (key.startsWith('reason:')) {
    return `Motif d’orientation « ${termLabel('REFERRAL_REASON', key.slice('reason:'.length))} »`
  }
  switch (key) {
    case 'conclusion':
      return 'Conclusion'
    case 'referral':
      return 'Orientation vers un ophtalmologiste'
    case 'referral_urgency':
      return 'Niveau d’urgence de l’orientation'
    default:
      return key
  }
}

const SHOWN_TEXT_MAX = 80

function isTermAtom(key: string): boolean {
  return (
    key.startsWith('complaint:') ||
    key.startsWith('reason:') ||
    key.startsWith('ametropia:') ||
    key.includes('|term:')
  )
}

/** Nom lisible d'une ligne de médicament sérialisée dans un atome. */
function orderName(value: string): string {
  try {
    const order = JSON.parse(value) as { name?: string }
    return typeof order.name === 'string' && order.name !== '' ? order.name : 'sans nom'
  } catch {
    return 'illisible'
  }
}

function describeAtom(key: string, value: string | undefined): string {
  if (key.startsWith('order:')) return value === undefined ? 'absent' : `« ${shorten(orderName(value))} »`
  if (key === 'sequence') return value === undefined ? 'vide' : `${value.split(',').length} ligne(s) dans cet ordre`
  if (isTermAtom(key)) {
    if (value === undefined) return 'non coché'
    return value === '' ? 'coché' : `coché (${shorten(value)})`
  }
  if (value === undefined) return 'vide'
  if (value === YES) return 'oui'
  if (value === NO) return 'non'
  if (key === 'referral_urgency' && isUrgency(value)) return URGENCY_LABELS[value]
  return shorten(value)
}

function shorten(text: string): string {
  const characters = [...text]
  return characters.length > SHOWN_TEXT_MAX
    ? `${characters.slice(0, SHOWN_TEXT_MAX).join('')}…`
    : text
}

// --- Reprise --------------------------------------------------------------------------------------

type AtomOps<W> = {
  atoms(data: W): Map<string, string>
  fromAtoms(atoms: Atoms, ids: Atoms, newId: NewId): W
  collectIds(data: W, ids: Map<string, string>): void
  repair(atoms: Map<string, string>): string[]
}

const OPS: { [S in DeliveredStep]: AtomOps<StepDataByName[S]> } = {
  ANAMNESIS: {
    atoms: anamnesisAtoms,
    fromAtoms: anamnesisFromAtoms,
    collectIds: anamnesisIds,
    repair: () => [],
  },
  HISTORIES: {
    atoms: historiesAtoms,
    fromAtoms: historiesFromAtoms,
    collectIds: historiesIds,
    repair: repairHistories,
  },
  VISUAL_ACUITY: {
    atoms: acuityAtoms,
    fromAtoms: acuityFromAtoms,
    collectIds: acuityIds,
    repair: () => [],
  },
  REFRACTION: {
    atoms: refractionAtoms,
    fromAtoms: refractionFromAtoms,
    collectIds: refractionIds,
    repair: () => [],
  },
  CENTERING: {
    atoms: centeringAtoms,
    fromAtoms: centeringFromAtoms,
    collectIds: centeringIds,
    repair: () => [],
  },
  MEDICATIONS: {
    atoms: medicationsAtoms,
    fromAtoms: (atoms) => medicationsFromAtoms(atoms),
    collectIds: () => undefined,
    repair: () => [],
  },
  CONCLUSION: {
    atoms: conclusionAtoms,
    fromAtoms: conclusionFromAtoms,
    collectIds: conclusionIds,
    repair: () => [],
  },
}

/** Forme normalisée d'une donnée du serveur : celle que produirait un brouillon sans modification. */
function normalized<S extends DeliveredStep>(step: S, data: StepDataByName[S]): StepDataByName[S] {
  return buildStepData(
    step,
    draftFromData(step, data, () => ''),
  )
}

export function rebaseStep<S extends DeliveredStep>(
  step: S,
  base: StepDataByName[S],
  latest: StepDataByName[S],
  current: StepDrafts[S],
  newId: NewId = newUlid,
): RebasedStep<S> {
  const ops = OPS[step] as AtomOps<StepDataByName[S]>
  const mineData = buildStepData(step, current)
  const mine = ops.atoms(mineData)
  const original = ops.atoms(normalized(step, base))
  const theirs = ops.atoms(normalized(step, latest))

  const merged = new Map<string, string>()
  const conflicts: RebaseConflict[] = []
  const keys = new Set([...original.keys(), ...mine.keys(), ...theirs.keys()])
  for (const key of keys) {
    const mineValue = mine.get(key)
    const baseValue = original.get(key)
    const theirValue = theirs.get(key)
    const keep = mineValue === baseValue ? theirValue : mineValue
    if (keep !== undefined) merged.set(key, keep)
    if (mineValue !== baseValue && theirValue !== baseValue && theirValue !== mineValue) {
      conflicts.push({
        key,
        label: atomLabel(step, key),
        current: describeAtom(key, theirValue),
      })
    }
  }
  for (const key of ops.repair(merged)) {
    if (conflicts.some((conflict) => conflict.key === key)) continue
    conflicts.push({
      key,
      label: atomLabel(step, key),
      current: describeAtom(key, theirs.get(key)),
    })
  }

  const ids = new Map<string, string>()
  ops.collectIds(normalized(step, latest), ids)
  ops.collectIds(mineData, ids)
  ops.collectIds(normalized(step, base), ids)
  // Les contraintes du serveur sont rétablies sur la donnée fusionnée (voir `normalized`).
  const data = normalized(step, ops.fromAtoms(merged, ids, newId))
  return { draft: draftFromData(step, data, newId), conflicts }
}

export interface RebasedConsultation {
  drafts: StepDrafts
  conflicts: Record<DeliveredStep, RebaseConflict[]>
}

/**
 * Reprise de toutes les étapes livrées. Une étape que la personne n'a pas modifiée reprend telle quelle
 * ce que le serveur a maintenant ; une étape modifiée est rebasée valeur par valeur.
 */
export function rebaseConsultation(
  base: { [S in DeliveredStep]: StepDataByName[S] },
  latest: { [S in DeliveredStep]: StepDataByName[S] },
  current: StepDrafts,
  newId: NewId = newUlid,
): RebasedConsultation {
  const conflicts = Object.fromEntries(
    DELIVERED_STEPS.map((step) => [step, [] as RebaseConflict[]]),
  ) as Record<DeliveredStep, RebaseConflict[]>
  const next = {} as StepDrafts
  const assign = <S extends DeliveredStep>(step: S): void => {
    if (!isStepDirty(step, current[step], base[step])) {
      next[step] = draftFromData(step, latest[step], newId)
      return
    }
    const result = rebaseStep(step, base[step], latest[step], current[step], newId)
    next[step] = result.draft
    conflicts[step] = result.conflicts
  }
  for (const step of DELIVERED_STEPS) assign(step)
  return { drafts: next, conflicts }
}
