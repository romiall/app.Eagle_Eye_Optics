import type {
  ClinicalRevisionDetail,
  Consultation,
  ConsultationPatient,
  Prescription,
} from '@/domain/contracts/generated'
import type { HistoryGroup } from '@/domain/clinical/labels'
import { sexLabel } from '@/domain/patients/identity'

import { invalid } from '../engine/errors'
import { formatPersonName, MISSING } from '../engine/format'
import type { DocumentClinic } from './clinic'
import type {
  AcuityRowData,
  ClinicalPatient,
  ClinicalRecordData,
  ConsultationEntryData,
  MedicationItem,
  MedicationOrderData,
  OpticalPrescriptionData,
  PrescriptionBody,
  PrescriptionEye,
  PrescriptionSummaryData,
  ProfessionalIdentity,
  ReferralLetterData,
} from './clinical'
import type { ClinicalLabels } from './clinicalLabels'

/**
 * Adaptateurs FINS entre les ressources cliniques du serveur et les modèles de données des documents
 * (`clinical.ts`). Ce sont les SEULS à connaître la forme du serveur : `Prescription`, `Consultation`
 * (avec `patient` et ses sept rubriques, dont `MEDICATIONS`) et la révision figée
 * `ClinicalRevisionDetail` (`GET /consultations/{id}/revisions/{n}`) de `domain/contracts/generated.ts`.
 * Ils ne font que renommer, résoudre les codes en libellés français et ordonner (OD puis OG) : ils
 * n'ajoutent, ne déduisent et n'arrondissent rien.
 *
 * Un document de consultation (fiche, ordonnance, lettre d'orientation) se construit TOUJOURS d'une
 * révision figée, jamais de la saisie en cours : `consultationEntryFromResource` ne sert qu'aux
 * contrôles du moteur (contenu provisoire marqué comme tel), aucun écran ne l'imprime.
 *
 * `patient` : `clinicalPatientFromFields` accepte les champs de `Patient` / `ConsultationPatient` ; un
 * sexe « non renseigné » n'est pas imprimé.
 */
type Steps = Consultation['steps']
type ResourceEye = Prescription['eyes'][number]
type Acuity = Steps['VISUAL_ACUITY']['acuities'][number]
type Measurement = Steps['REFRACTION']['measurements'][number]
type HistoryRow = Steps['HISTORIES']['histories'][number]
type Order = Steps['MEDICATIONS']['orders'][number]
type RevisionSnapshot = ClinicalRevisionDetail['snapshot']

interface CodedTerm {
  term_code: string
  other_text: string | null
}

/** Texte saisi, ou null s'il est vide (jamais une chaîne blanche imprimée comme une valeur). */
function text(value: string | null): string | null {
  if (value === null) return null
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

export function clinicalPatientFromFields(fields: {
  record_number: string
  last_name: string
  first_names: string | null
  sex: 'F' | 'M' | 'UNKNOWN'
  birth_date: string | null
}): ClinicalPatient {
  return {
    recordNumber: fields.record_number,
    lastName: fields.last_name,
    firstNames: fields.first_names,
    sex: fields.sex === 'UNKNOWN' ? null : sexLabel(fields.sex),
    birthDate: fields.birth_date,
  }
}

/**
 * Patient d'un document tiré d'une révision : nom, prénoms et dossier FIGÉS dans le snapshot. Le snapshot
 * ne fige ni le sexe ni la date de naissance : ils viennent de l'identité administrative rendue avec la
 * consultation (`Consultation.patient`), seulement s'il s'agit bien du même patient ; sinon ils ne sont
 * pas imprimés (rien n'est complété d'une autre source).
 */
export function clinicalPatientFromRevision(
  snapshot: RevisionSnapshot['patient'],
  current: ConsultationPatient | null,
): ClinicalPatient {
  const same = current !== null && current.id === snapshot.patient_id ? current : null
  return {
    recordNumber: snapshot.record_number,
    lastName: snapshot.last_name,
    firstNames: snapshot.first_names,
    sex: same === null || same.sex === 'UNKNOWN' ? null : sexLabel(same.sex),
    birthDate: same === null ? null : same.birth_date,
  }
}

/** Auteur d'une révision figée (nom tel qu'enregistré dans le snapshot) : pas une signature certifiée. */
export function professionalOfRevision(revision: ClinicalRevisionDetail): ProfessionalIdentity {
  return { displayName: revision.snapshot.author.display_name, professionalNumber: null }
}

// --- Prescription optique ---------------------------------------------------------------------------

function eyeFromResource(eye: ResourceEye): PrescriptionEye {
  return {
    eye: eye.eye,
    noCorrection: eye.no_correction,
    sphere: eye.sphere,
    cylinder: eye.cylinder,
    axis: eye.axis,
    addition: eye.addition,
    fittingHeight: eye.fitting_height,
  }
}

/** Les deux yeux dans l'ordre OD puis OG ; une prescription sans exactement un OD et un OG est refusée. */
function orderedEyes(prescription: Prescription): readonly [PrescriptionEye, PrescriptionEye] {
  const eyes: readonly ResourceEye[] = prescription.eyes
  const right = eyes.filter((eye) => eye.eye === 'OD')
  const left = eyes.filter((eye) => eye.eye === 'OG')
  const [od] = right
  const [og] = left
  if (od === undefined || og === undefined || right.length !== 1 || left.length !== 1) {
    throw invalid('prescription.eyes', 'eyes', 'Une prescription porte un œil droit et un œil gauche.')
  }
  return [eyeFromResource(od), eyeFromResource(og)]
}

/** Contenu optique d'une prescription : celui du document ET celui de l'écran de lecture. */
export function prescriptionBodyFromResource(
  prescription: Prescription,
  labels: ClinicalLabels,
): PrescriptionBody {
  const treatments: readonly CodedTerm[] = prescription.treatments
  return {
    eyes: orderedEyes(prescription),
    pdTotal: prescription.pd_total,
    pdOd: prescription.pd_od,
    pdOg: prescription.pd_og,
    pdInconsistencyReason: text(prescription.pd_inconsistency_reason),
    usage:
      prescription.usage_code === null
        ? null
        : labels.usage(prescription.usage_code, prescription.usage_other),
    lensType:
      prescription.lens_type_code === null
        ? null
        : labels.lensType(prescription.lens_type_code, prescription.lens_type_other),
    lensIndex:
      prescription.lens_index !== null
        ? labels.lensIndex(prescription.lens_index, null)
        : prescription.lens_index_other === null
          ? null
          : labels.lensIndex('OTHER', prescription.lens_index_other),
    treatments: treatments.map((treatment) =>
      labels.treatment(treatment.term_code, treatment.other_text),
    ),
    notes: text(prescription.notes),
  }
}

export interface PrescriptionInput {
  prescription: Prescription
  clinic: DocumentClinic
  patient: ClinicalPatient
  /** Nom du professionnel qui a validé (ou rédigé) ; null tant que la prescription n'est pas validée. */
  professional: ProfessionalIdentity | null
  /** Instant de la consultation liée, ou null (prescription externe sans consultation). */
  consultationAt: string | null
  labels: ClinicalLabels
  templateVersion: string
}

export function prescriptionFromResource(input: PrescriptionInput): OpticalPrescriptionData {
  const { prescription } = input
  return {
    templateVersion: input.templateVersion,
    prescriptionId: prescription.id,
    versionNo: prescription.version_no,
    status: prescription.status,
    origin: prescription.origin,
    consultationAt: input.consultationAt,
    validatedAt: prescription.validated_at,
    clinic: input.clinic,
    patient: input.patient,
    professional: input.professional,
    externalPrescriber: prescription.external_prescriber,
    externalPrescribedOn: prescription.external_prescribed_on,
    body: prescriptionBodyFromResource(prescription, input.labels),
  }
}

/** Version résumée d'une prescription pour le dossier clinique exporté. */
export function prescriptionSummaryFromResource(
  prescription: Prescription,
  labels: ClinicalLabels,
): PrescriptionSummaryData {
  return {
    versionNo: prescription.version_no,
    status: prescription.status,
    at: prescription.validated_at,
    body: prescriptionBodyFromResource(prescription, labels),
  }
}

// --- Rubriques de la consultation ------------------------------------------------------------------

const EYE_CELL: Readonly<Record<'OD' | 'OG' | 'OU', 'od' | 'og' | 'ou'>> = {
  OD: 'od',
  OG: 'og',
  OU: 'ou',
}

/**
 * Acuités à plat → une ligne par (contexte, distance, correction) avec OD, OG, OU. Une valeur non
 * mesurée est un tiret. La valeur originale est conservée telle quelle (`raw_value`) : aucune
 * conversion entre échelles. Plusieurs échelles dans une ligne sont toutes citées.
 */
export function acuityRowsFromResource(
  acuities: Steps['VISUAL_ACUITY']['acuities'],
  labels: ClinicalLabels,
): AcuityRowData[] {
  const list: readonly Acuity[] = acuities
  const rows = new Map<string, AcuityRowData & { scales: Set<string> }>()
  for (const acuity of list) {
    const key = `${acuity.context}|${acuity.distance}|${acuity.correction}`
    let row = rows.get(key)
    if (row === undefined) {
      row = {
        context: labels.context(acuity.context),
        distance: labels.distance(acuity.distance),
        correction: labels.correction(acuity.correction),
        od: MISSING,
        og: MISSING,
        ou: MISSING,
        scale: null,
        scales: new Set<string>(),
      }
      rows.set(key, row)
    }
    row[EYE_CELL[acuity.eye]] = acuity.raw_value
    const scale = labels.scale(acuity.scale_id)
    if (scale !== null) row.scales.add(scale)
  }
  return [...rows.values()].map(({ scales, ...row }) => ({
    ...row,
    scale: scales.size === 0 ? null : [...scales].join(', '),
  }))
}

/**
 * Médicaments de la rubrique MEDICATIONS, tels que saisis et dans l'ordre de l'ordonnance : forme en
 * libellé (« Autre » : la forme précisée), dosage (`strength`), posologie (`dosage`), fréquence, durée
 * (texte saisi et durée structurée, les deux s'ils existent), instructions. Rien n'est complété : une
 * information absente reste absente (le document imprime un tiret).
 */
export function medicationItemsFromSteps(
  medications: Steps['MEDICATIONS'],
  labels: ClinicalLabels,
): MedicationItem[] {
  const orders: readonly Order[] = medications.orders
  return orders.map((order) => {
    const structured =
      order.duration_value !== null && order.duration_unit !== null
        ? `${order.duration_value} ${labels.durationUnit(order.duration_unit)}`
        : null
    const duration = [text(order.duration_text), structured]
      .filter((part): part is string => part !== null)
      .join(' \u{00B7} ')
    return {
      name: order.name,
      form: labels.medicationForm(order.form, order.form_other),
      strength: text(order.strength),
      posology: text(order.dosage),
      frequency: text(order.frequency),
      duration: duration === '' ? null : duration,
      instructions: text(order.instructions),
    }
  })
}

interface EntrySource {
  consultationId: string
  revisionNo: number
  status: ConsultationEntryData['status']
  effectiveAt: string
  authorName: string
  steps: Steps
  missingDataReason: string | null
  amendmentReason: string | null
  labels: ClinicalLabels
}

/** Les sept rubriques → une entrée de fiche / de dossier (même lecture pour la ressource et la révision). */
function entryFromSteps(source: EntrySource): ConsultationEntryData {
  const { labels, steps } = source
  const { ANAMNESIS, HISTORIES, VISUAL_ACUITY, REFRACTION, CENTERING, MEDICATIONS, CONCLUSION } = steps
  const complaints: readonly CodedTerm[] = ANAMNESIS.complaints
  const histories: readonly HistoryRow[] = HISTORIES.histories
  const measurements: readonly Measurement[] = REFRACTION.measurements
  const ametropias: readonly CodedTerm[] = REFRACTION.ametropias
  const reasons: readonly CodedTerm[] = CONCLUSION.referral_reasons
  return {
    consultationId: source.consultationId,
    revisionNo: source.revisionNo,
    status: source.status,
    effectiveAt: source.effectiveAt,
    clinicianName: source.authorName,
    complaints: complaints.map((complaint) =>
      labels.complaint(complaint.term_code, complaint.other_text),
    ),
    symptoms: text(ANAMNESIS.symptoms),
    wearsGlasses: ANAMNESIS.wears_glasses,
    glassesDuration: text(ANAMNESIS.glasses_duration),
    previousCorrectionAvailable: ANAMNESIS.previous_correction_available,
    histories: histories.map((history) => {
      const group = history.history_group as HistoryGroup
      const terms: readonly CodedTerm[] = history.terms
      return {
        group: labels.historyGroup(group),
        knownNone: history.known_none,
        terms: terms.map((term) => labels.history(group, term.term_code, term.other_text)),
        comments: text(history.comments),
      }
    }),
    acuities: acuityRowsFromResource(VISUAL_ACUITY.acuities, labels),
    refraction: measurements.map((measure) => ({
      stage: labels.stage(measure.stage),
      eye: measure.eye,
      sphere: measure.sphere,
      cylinder: measure.cylinder,
      axis: measure.axis,
      addition: measure.addition,
    })),
    refractionObservations: text(REFRACTION.refraction_observations),
    ametropias: ametropias.map((ametropia) =>
      labels.ametropia(ametropia.term_code, ametropia.other_text),
    ),
    pdTotal: CENTERING.pd_total,
    pdOd: CENTERING.pd_od,
    pdOg: CENTERING.pd_og,
    pdInconsistencyReason: text(CENTERING.inconsistency_reason),
    medications: medicationItemsFromSteps(MEDICATIONS, labels),
    conclusion: text(CONCLUSION.conclusion),
    referral: CONCLUSION.referral,
    referralUrgency:
      CONCLUSION.referral_urgency === null ? null : labels.urgency(CONCLUSION.referral_urgency),
    referralReasons: reasons.map((reason) =>
      labels.referralReason(reason.term_code, reason.other_text),
    ),
    missingDataReason: text(source.missingDataReason),
    amendmentReason: text(source.amendmentReason),
  }
}

export interface ConsultationInput {
  consultation: Consultation
  clinicianName: string
  labels: ClinicalLabels
}

/**
 * Consultation du serveur (état COURANT) → entrée du dossier. Réservé aux contrôles du moteur : un écran
 * imprime une consultation à partir de sa révision figée (`consultationEntryFromRevision`).
 */
export function consultationEntryFromResource(input: ConsultationInput): ConsultationEntryData {
  const { consultation } = input
  return entryFromSteps({
    consultationId: consultation.id,
    revisionNo: consultation.current_revision,
    status: consultation.status,
    effectiveAt: consultation.effective_at,
    authorName: input.clinicianName,
    steps: consultation.steps,
    missingDataReason: consultation.finalization_missing_data_reason,
    amendmentReason: null,
    labels: input.labels,
  })
}

/**
 * Révision FIGÉE → entrée de la fiche de consultation : contenu, auteur, numéro de révision, motif de
 * correction et justification d'absence tels qu'enregistrés. Une révision est toujours « terminée ».
 */
export function consultationEntryFromRevision(
  revision: ClinicalRevisionDetail,
  labels: ClinicalLabels,
): ConsultationEntryData {
  const snapshot = revision.snapshot
  return entryFromSteps({
    consultationId: snapshot.consultation_id,
    revisionNo: revision.revision_no,
    status: 'COMPLETED',
    effectiveAt: snapshot.effective_at,
    authorName: snapshot.author.display_name,
    steps: snapshot.steps,
    missingDataReason: snapshot.finalization_missing_data_reason,
    amendmentReason: snapshot.amendment_reason,
    labels,
  })
}

// --- Documents tirés d'une révision figée ----------------------------------------------------------

export interface RevisionDocumentInput {
  revision: ClinicalRevisionDetail
  clinic: DocumentClinic
  patient: ClinicalPatient
  labels: ClinicalLabels
  templateVersion: string
}

/**
 * Ordonnance de médicaments d'une révision figée, ou null si la rubrique MEDICATIONS est vide (aucune
 * ordonnance vide n'est produite). Date = enregistrement de la révision ; professionnel = son auteur.
 */
export function medicationOrderFromRevision(
  input: RevisionDocumentInput,
): MedicationOrderData | null {
  const { revision } = input
  const items = medicationItemsFromSteps(revision.snapshot.steps.MEDICATIONS, input.labels)
  if (items.length === 0) return null
  return {
    templateVersion: input.templateVersion,
    orderId: revision.id,
    status: 'VALIDATED',
    orderedAt: revision.recorded_at,
    clinic: input.clinic,
    patient: input.patient,
    professional: professionalOfRevision(revision),
    items,
    notes: null,
  }
}

/**
 * Lettre d'orientation d'une révision figée, seulement si la conclusion dit « orientation : oui » (jamais
 * pour « non » ni « non renseigné »). Niveau d'urgence, motifs et résumé (la conclusion) tels que saisis ;
 * aucune mesure n'est jointe d'office (le professionnel ne les a pas choisies), aucun destinataire inventé.
 */
export function referralLetterFromRevision(
  input: RevisionDocumentInput,
): ReferralLetterData | null {
  const { revision, labels } = input
  const conclusion = revision.snapshot.steps.CONCLUSION
  if (conclusion.referral !== true) return null
  const reasons: readonly CodedTerm[] = conclusion.referral_reasons
  return {
    templateVersion: input.templateVersion,
    letterId: revision.id,
    status: 'VALIDATED',
    letterAt: revision.recorded_at,
    clinic: input.clinic,
    patient: input.patient,
    professional: professionalOfRevision(revision),
    addressee: null,
    urgency: conclusion.referral_urgency === null ? null : labels.urgency(conclusion.referral_urgency),
    reasons: reasons.map((reason) => labels.referralReason(reason.term_code, reason.other_text)),
    summary: text(conclusion.conclusion),
    measurements: [],
  }
}

export interface ConsultationRecordInput extends RevisionDocumentInput {
  /** Prescriptions de la consultation : seules les versions VALIDÉES figurent sur la fiche. */
  prescriptions: readonly Prescription[]
  exportedAt: string
  exportedByName: string
}

/** Fiche d'UNE consultation (sélection « une consultation ») tirée de sa révision figée. */
export function consultationRecordFromRevision(input: ConsultationRecordInput): ClinicalRecordData {
  const { revision, labels } = input
  return {
    templateVersion: input.templateVersion,
    recordId: revision.id,
    selection: 'SINGLE',
    exportedAt: input.exportedAt,
    exportedByName: input.exportedByName,
    clinic: input.clinic,
    patient: input.patient,
    consultations: [consultationEntryFromRevision(revision, labels)],
    prescriptions: input.prescriptions
      .filter((prescription) => prescription.status === 'VALIDATED')
      .map((prescription) => prescriptionSummaryFromResource(prescription, labels)),
  }
}

/** « NOM Prénoms » pour l'objet d'une lettre : même règle que les autres documents. */
export function displayNameOf(patient: ClinicalPatient): string {
  return formatPersonName(patient.lastName, patient.firstNames)
}
