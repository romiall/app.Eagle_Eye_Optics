import type { DocumentClinic } from './clinic'

/**
 * Données d'entrée des documents CLINIQUES (D02) : prescription optique, ordonnance de médicaments,
 * lettre d'orientation, fiche de consultation et dossier clinique. Ce sont des modèles de données du
 * DOCUMENT, volontairement indépendants des ressources du serveur : SEULS les adaptateurs
 * (`clinicalAdapters.ts`) lisent les ressources générées (`Prescription`, `Consultation`,
 * `ClinicalRevisionDetail` de `domain/contracts/generated.ts`) et y mettent des textes déjà résolus
 * (libellés français, noms) ; le modèle de document ne fait que les mettre en page. Il n'existe pas
 * d'autre « modèle d'impression » clinique : l'écran de lecture d'une prescription affiche les mêmes
 * valeurs, mises en forme par les mêmes fonctions (`app/services/clinicalDocuments.ts`).
 *
 * Champs attendus de l'écran clinique (C01 à C04) — rien n'est complété ni déduit :
 *  - une valeur absente est `null` et s'imprime par un tiret ou « non précisé », JAMAIS par zéro ni
 *    par un texte médical produit par le modèle ;
 *  - aucune posologie, aucun diagnostic, aucun conseil n'est généré (fichier 11) ;
 *  - le nom du professionnel imprimé n'est pas une signature électronique certifiée : la signature et
 *    le cachet se posent sur la version imprimée.
 */
export interface ClinicalPatient {
  recordNumber: string
  lastName: string
  firstNames: string | null
  /** Libellé du sexe tel que l'application l'affiche ; null = non renseigné. */
  sex: string | null
  /** Date de naissance civile « AAAA-MM-JJ » ; null = non renseignée (jamais une date inventée). */
  birthDate: string | null
}

export interface ProfessionalIdentity {
  displayName: string
  /** Numéro d'ordre ou mention professionnelle réelle ; null tant qu'elle n'est pas fournie (Q03). */
  professionalNumber: string | null
}

export type ClinicalDocumentStatus = 'DRAFT' | 'VALIDATED'

export interface PrescriptionEye {
  eye: 'OD' | 'OG'
  /** Absence de correction explicitement saisie pour cet œil. */
  noCorrection: boolean
  /** Valeurs décimales canoniques (« -1.25 »), null = non mesuré. */
  sphere: string | null
  cylinder: string | null
  axis: number | null
  addition: string | null
  fittingHeight: string | null
}

/** Contenu optique d'une prescription (partagé par le document et le dossier clinique). */
export interface PrescriptionBody {
  eyes: readonly [PrescriptionEye, PrescriptionEye]
  pdTotal: string | null
  pdOd: string | null
  pdOg: string | null
  /** Justification saisie d'un écart entre la DP totale et OD + OG ; null si aucune. */
  pdInconsistencyReason: string | null
  usage: string | null
  lensType: string | null
  lensIndex: string | null
  treatments: readonly string[]
  notes: string | null
}

export interface OpticalPrescriptionData {
  templateVersion: string
  prescriptionId: string
  /** Numéro de version de la prescription dans sa série. */
  versionNo: number
  status: ClinicalDocumentStatus
  origin: 'INTERNAL' | 'EXTERNAL'
  /** Instant de la consultation, ou null pour une prescription externe sans consultation. */
  consultationAt: string | null
  validatedAt: string | null
  clinic: DocumentClinic
  patient: ClinicalPatient
  /** Professionnel qui a validé (ou rédigé) ; null pour une prescription externe transcrite. */
  professional: ProfessionalIdentity | null
  externalPrescriber: string | null
  /** Date civile de l'ordonnance externe. */
  externalPrescribedOn: string | null
  body: PrescriptionBody
}

export interface MedicationItem {
  name: string
  form: string | null
  strength: string | null
  posology: string | null
  frequency: string | null
  duration: string | null
  instructions: string | null
}

export interface MedicationOrderData {
  templateVersion: string
  orderId: string
  status: ClinicalDocumentStatus
  /** Instant de l'ordonnance (validation, ou création d'un brouillon). */
  orderedAt: string
  clinic: DocumentClinic
  patient: ClinicalPatient
  professional: ProfessionalIdentity
  items: readonly MedicationItem[]
  notes: string | null
}

export interface ReferralLetterData {
  templateVersion: string
  letterId: string
  status: ClinicalDocumentStatus
  letterAt: string
  clinic: DocumentClinic
  patient: ClinicalPatient
  professional: ProfessionalIdentity
  /** Destinataire saisi (« Dr … ») ; null : lettre adressée « au confrère ophtalmologiste ». */
  addressee: string | null
  /** Niveau d'urgence tel que le professionnel l'a choisi (libellé), null = non renseigné. */
  urgency: string | null
  reasons: readonly string[]
  /** Résumé clinique rédigé par le professionnel (conclusion), repris tel quel. */
  summary: string | null
  /** Lignes de mesures utiles choisies par le professionnel, déjà formatées. */
  measurements: readonly string[]
}

export interface AcuityRowData {
  context: string
  distance: string
  correction: string
  od: string
  og: string
  ou: string
  scale: string | null
}

export interface RefractionRowData {
  stage: string
  eye: 'OD' | 'OG'
  sphere: string | null
  cylinder: string | null
  axis: number | null
  addition: string | null
}

export interface HistoryEntryData {
  group: string
  knownNone: boolean
  terms: readonly string[]
  comments: string | null
}

export interface ConsultationEntryData {
  consultationId: string
  revisionNo: number
  status: 'DRAFT' | 'IN_PROGRESS' | 'COMPLETED'
  effectiveAt: string
  clinicianName: string
  complaints: readonly string[]
  symptoms: string | null
  wearsGlasses: boolean | null
  glassesDuration: string | null
  previousCorrectionAvailable: boolean | null
  histories: readonly HistoryEntryData[]
  acuities: readonly AcuityRowData[]
  refraction: readonly RefractionRowData[]
  /** Observations de réfraction saisies, reprises telles quelles ; null si aucune. */
  refractionObservations: string | null
  /** Amétropies CHOISIES par le professionnel (libellés), jamais déduites des chiffres. */
  ametropias: readonly string[]
  pdTotal: string | null
  pdOd: string | null
  pdOg: string | null
  pdInconsistencyReason: string | null
  /** Médicaments prescrits (« traitement », fichier 11), tels que saisis, dans l'ordre de l'ordonnance. */
  medications: readonly MedicationItem[]
  conclusion: string | null
  referral: boolean | null
  referralUrgency: string | null
  referralReasons: readonly string[]
  missingDataReason: string | null
  amendmentReason: string | null
}

export interface PrescriptionSummaryData {
  versionNo: number
  status: ClinicalDocumentStatus
  /** Date affichée de la prescription (instant de validation ou de consultation). */
  at: string | null
  body: PrescriptionBody
}

/** Dossier clinique exporté : une consultation ou l'historique, avec la date et l'auteur de l'export. */
export interface ClinicalRecordData {
  templateVersion: string
  recordId: string
  selection: 'SINGLE' | 'HISTORY'
  exportedAt: string
  exportedByName: string
  clinic: DocumentClinic
  patient: ClinicalPatient
  consultations: readonly ConsultationEntryData[]
  prescriptions: readonly PrescriptionSummaryData[]
}
