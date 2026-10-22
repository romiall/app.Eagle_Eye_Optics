import { canOnScreen, session } from '@/app/session'
import { DOCUMENT_PERMISSIONS } from '@/documents/access'
import type { DocumentClinic } from '@/documents/data/clinic'
import type { ProfessionalIdentity } from '@/documents/data/clinical'
import {
  clinicalPatientFromFields,
  clinicalPatientFromRevision,
  consultationRecordFromRevision,
  medicationOrderFromRevision,
  prescriptionBodyFromResource,
  prescriptionFromResource,
  referralLetterFromRevision,
} from '@/documents/data/clinicalAdapters'
import { clinicalLabelsWithScales, defaultClinicalLabels } from '@/documents/data/clinicalLabels'
import { formatAxis, formatDiopter, formatMillimeters } from '@/documents/engine/format'
import type { DocumentKind } from '@/documents/engine/model'
import { type DocumentKey, DocumentStoreError } from '@/documents/store/port'
import { CLINICAL_TEMPLATE_VERSIONS } from '@/documents/templates/registry'
import { EYE_LABELS } from '@/domain/clinical/labels'
import type {
  ClinicalRevisionDetail,
  Consultation,
  Prescription,
  VisualAcuityScale,
} from '@/domain/contracts/generated'
import { newUlid } from '@/domain/values/ulid'

import { currentDocumentClinic } from './clinicIdentity'
import { getConsultationPrescriptions, getRevision } from './consultations'
import {
  assertMayOpen,
  buildDocument,
  DocumentAccessError,
  documentStore,
  isDocumentStorePersistent,
  keepOriginal,
  type RenderedDocument,
  renderDocument,
} from './documents'

/**
 * Documents CLINIQUES imprimables (D02, C04 ; fichier 11), générés SUR CE POSTE par le moteur de
 * documents. Un écran clinique n'appelle que ce service : il ne construit aucun PDF lui-même.
 *
 * Règles :
 *  - une consultation s'imprime depuis sa révision FIGÉE (`GET /consultations/{id}/revisions/{n}`), relue
 *    au moment de l'impression, jamais depuis la saisie en cours ni une ancienne copie : rien n'est proposé
 *    pour un examen non terminé (fiche, ordonnance, lettre) ;
 *  - une prescription optique ne s'imprime que VALIDÉE (le brouillon ne se remet pas au patient), à part
 *    du dossier complet (fichier 11) ;
 *  - l'ordonnance n'existe que si la rubrique MEDICATIONS est renseignée, la lettre d'orientation que si la
 *    conclusion dit « orientation : oui » ;
 *  - droits : ceux du référentiel par type (`documents/access.ts` : `clinical.read`, `clinical.export`
 *    pour la fiche), vérifiés AVANT toute lecture, puis par `renderDocument` ; le serveur revérifie chaque
 *    lecture ;
 *  - aucune donnée clinique dans l'adresse ni dans le nom du fichier (`documentFileName`) ;
 *  - cabinet : celui du compte connecté (`clinicFromSummary`), document de TEST tant que
 *    `production_documents_enabled` est faux.
 *
 * Conservation : le port `DocumentStore` (VOLATIL tant que le coffre O01 à O05 n'est pas livré). La
 * prescription garde son original sous la clé du serveur (`optical_prescription:<id>`, numéro de version,
 * `generated_documents.id` = `document_id`) ; l'ordonnance et la lettre, propres au poste, sous la clé de
 * leur révision, sans ligne serveur. La fiche est un EXPORT daté (date d'export imprimée) : elle n'est pas
 * conservée comme original. Le dépôt du PDF sur le serveur (`generated_documents` → READY) n'existe pas
 * encore : la ligne de la prescription reste `PENDING` côté serveur.
 */
export type ConsultationDocumentKind = 'CLINICAL_RECORD' | 'MEDICATION_ORDER' | 'REFERRAL_LETTER'

/** Refus d'impression explicable (aucune donnée personnelle ni clinique dans le message). */
export class ClinicalPrintRefused extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'ClinicalPrintRefused'
  }
}

export interface ConsultationPrintable {
  kind: ConsultationDocumentKind
  /** Libellé du bouton (il dit ce qu'il fait). */
  label: string
  /** Nom du document dans l'aperçu. */
  heading: string
}

const PRINTABLE_TEXTS: Readonly<
  Record<ConsultationDocumentKind, { label: string; heading: string }>
> = {
  CLINICAL_RECORD: {
    label: 'Aperçu de la fiche de consultation',
    heading: 'Fiche de consultation',
  },
  MEDICATION_ORDER: {
    label: 'Aperçu de l’ordonnance de médicaments',
    heading: 'Ordonnance de médicaments',
  },
  REFERRAL_LETTER: { label: 'Aperçu de la lettre d’orientation', heading: 'Lettre d’orientation' },
}

/**
 * Le compte de l'écran peut-il ouvrir ce type de document ? Même règle que `renderDocument`, lue avec les
 * droits affichés par l'écran (`canOnScreen`) : rien n'est proposé à un compte qui ne l'ouvrirait pas.
 */
export function mayOfferDocument(kind: DocumentKind): boolean {
  return DOCUMENT_PERMISSIONS[kind].some((permission) => canOnScreen(permission))
}

/** Examen terminé, avec une révision figée à lire : seule condition pour imprimer une consultation. */
export function isPrintableConsultation(consultation: Consultation): boolean {
  return consultation.status === 'COMPLETED' && consultation.current_revision >= 1
}

/**
 * Documents proposés pour une consultation, dans l'ordre fiche, ordonnance, lettre. La présence de
 * médicaments et de l'orientation est lue dans l'état enregistré (identique à la révision courante pour
 * un examen terminé) ; le document lui-même est construit de la révision relue.
 */
export function consultationPrintables(consultation: Consultation): ConsultationPrintable[] {
  if (!isPrintableConsultation(consultation)) return []
  const orders: readonly unknown[] = consultation.steps.MEDICATIONS.orders
  const kinds: ConsultationDocumentKind[] = ['CLINICAL_RECORD']
  if (orders.length > 0) kinds.push('MEDICATION_ORDER')
  if (consultation.steps.CONCLUSION.referral === true) kinds.push('REFERRAL_LETTER')
  return kinds
    .filter((kind) => mayOfferDocument(kind))
    .map((kind) => ({ kind, ...PRINTABLE_TEXTS[kind] }))
}

/** Dernière version VALIDÉE d'une liste de prescriptions (plus récente d'abord), ou null. */
export function printablePrescription(list: readonly Prescription[]): Prescription | null {
  return list.find((prescription) => prescription.status === 'VALIDATED') ?? null
}

/** Compte connecté tel que la session le donne (lecture seule). */
type Account = NonNullable<typeof session.account>

function account(kind: DocumentKind): Account {
  const current = session.account
  if (current === null) throw new DocumentAccessError(kind)
  return current
}

/** Identité saisie par la gérante (paramètres du cabinet), sinon le seul nom du cabinet de la session. */
async function clinicOf(): Promise<DocumentClinic> {
  return currentDocumentClinic()
}

const REOPENED =
  'Réimpression : l’original conservé pendant cette session est rouvert (le PDF régénéré en différait).'

/**
 * Conserve l'original (jamais remplacé) ; si un original différent existe déjà pour la même source
 * (identité du patient ou nom du cabinet changés entre-temps), c'est LUI qui est rouvert.
 */
async function keepOrReopen(
  rendered: RenderedDocument,
  key: DocumentKey,
  documentId: string | null,
  entityId: string,
): Promise<RenderedDocument> {
  if (documentId === null) return rendered
  try {
    await keepOriginal(rendered, { key, documentId, entityId })
    return rendered
  } catch (error) {
    if (!(error instanceof DocumentStoreError) || error.code !== 'DOCUMENT_CONFLICT') throw error
    const stored = await documentStore().get(key)
    if (stored === null || stored.meta.sha256 === null) throw error
    return {
      ...rendered,
      bytes: stored.bytes,
      sha256: stored.meta.sha256,
      warnings: [...rendered.warnings, REOPENED],
    }
  }
}

/** Document propre au poste (aucune ligne serveur) : identifiant local stable pour la même source. */
async function keepLocal(
  rendered: RenderedDocument,
  key: DocumentKey,
  entityId: string,
): Promise<RenderedDocument> {
  const existing = await documentStore().getMeta(key)
  return keepOrReopen(rendered, key, existing?.documentId ?? newUlid(), entityId)
}

/** Révision courante relue sur le serveur ; elle doit être celle de l'examen affiché. */
async function frozenRevision(consultation: Consultation): Promise<ClinicalRevisionDetail> {
  const { data } = await getRevision(consultation.id, consultation.current_revision)
  if (
    data.revision_no !== consultation.current_revision ||
    data.snapshot.consultation_id !== consultation.id
  ) {
    throw new ClinicalPrintRefused(
      'La révision lue ne correspond pas à l’examen affiché : rechargez la consultation puis recommencez.',
    )
  }
  return data
}

/**
 * Fiche de consultation, ordonnance ou lettre d'orientation d'un examen TERMINÉ, tirée de sa révision
 * figée relue sur le serveur. Les droits sont vérifiés avant toute lecture.
 */
export async function consultationDocument(
  kind: ConsultationDocumentKind,
  consultation: Consultation,
  scales: readonly VisualAcuityScale[] = [],
): Promise<RenderedDocument> {
  assertMayOpen(kind)
  const me = account(kind)
  if (!isPrintableConsultation(consultation)) {
    throw new ClinicalPrintRefused(
      'Seul un examen terminé s’imprime : une saisie en cours n’est jamais remise au patient.',
    )
  }
  const revision = await frozenRevision(consultation)
  const base = {
    revision,
    clinic: await clinicOf(),
    patient: clinicalPatientFromRevision(revision.snapshot.patient, consultation.patient),
    labels: clinicalLabelsWithScales(scales),
  }
  const sourceOf = (prefix: string): string => `${prefix}:${revision.id}`

  switch (kind) {
    case 'MEDICATION_ORDER': {
      const data = medicationOrderFromRevision({
        ...base,
        templateVersion: CLINICAL_TEMPLATE_VERSIONS.MEDICATION_ORDER,
      })
      if (data === null) {
        throw new ClinicalPrintRefused(
          'Aucun médicament n’est enregistré dans la révision figée : aucune ordonnance n’est produite.',
        )
      }
      const rendered = await renderDocument(buildDocument('MEDICATION_ORDER', data))
      return keepLocal(
        rendered,
        {
          documentType: 'MEDICATION_ORDER',
          sourceKey: sourceOf('medication_order'),
          revisionNo: revision.revision_no,
        },
        revision.id,
      )
    }
    case 'REFERRAL_LETTER': {
      const data = referralLetterFromRevision({
        ...base,
        templateVersion: CLINICAL_TEMPLATE_VERSIONS.REFERRAL_LETTER,
      })
      if (data === null) {
        throw new ClinicalPrintRefused(
          'La révision figée ne propose pas d’orientation : aucune lettre d’orientation n’est produite.',
        )
      }
      const rendered = await renderDocument(buildDocument('REFERRAL_LETTER', data))
      return keepLocal(
        rendered,
        {
          documentType: 'REFERRAL_LETTER',
          sourceKey: sourceOf('referral_letter'),
          revisionNo: revision.revision_no,
        },
        revision.id,
      )
    }
    case 'CLINICAL_RECORD': {
      const { data: prescriptions } = await getConsultationPrescriptions(consultation.id)
      const data = consultationRecordFromRevision({
        ...base,
        templateVersion: CLINICAL_TEMPLATE_VERSIONS.CLINICAL_RECORD,
        prescriptions,
        exportedAt: new Date().toISOString(),
        exportedByName: me.display_name,
      })
      // Export daté : chaque export porte sa date, il n'est pas conservé comme original.
      return renderDocument(buildDocument('CLINICAL_RECORD', data))
    }
  }
}

/**
 * Nom du professionnel qui a VALIDÉ la prescription. La ressource ne porte que son identifiant
 * (`validated_by`) et son nom d’affichage lu par le serveur (`validated_by_name`) : le nom vient du compte connecté s’il s’agit de lui, sinon du serveur, sinon d’une révision de la
 * consultation enregistrée par la même personne (nom figé dans le snapshot). Sinon l'impression est
 * refusée : un nom n'est jamais deviné ni remplacé par celui d'un autre.
 */
async function validatorOf(
  prescription: Prescription,
  consultation: Consultation,
  me: Account,
): Promise<ProfessionalIdentity> {
  const userId = prescription.validated_by
  if (userId === null) {
    throw new ClinicalPrintRefused('Cette prescription ne porte pas l’auteur de sa validation.')
  }
  if (userId === me.id) return { displayName: me.display_name, professionalNumber: null }
  // Nom d'affichage du compte validateur, lu par le serveur dans le cabinet (jamais deviné à l'écran).
  const named = prescription.validated_by_name?.trim() ?? ''
  if (named !== '') return { displayName: named, professionalNumber: null }
  const authored = [...consultation.revisions]
    .reverse()
    .find((revision) => revision.author_id === userId)
  if (authored !== undefined) {
    const { data } = await getRevision(consultation.id, authored.revision_no)
    if (data.snapshot.author.user_id === userId) {
      return { displayName: data.snapshot.author.display_name, professionalNumber: null }
    }
  }
  throw new ClinicalPrintRefused(
    'Le nom du professionnel qui a validé cette prescription n’est pas disponible pour ce compte : imprimez-la depuis son compte.',
  )
}

/** Ordonnance de la prescription optique VALIDÉE d'une consultation (pièce versionnée). */
export async function prescriptionDocument(
  prescription: Prescription,
  consultation: Consultation,
): Promise<RenderedDocument> {
  assertMayOpen('OPTICAL_PRESCRIPTION')
  const me = account('OPTICAL_PRESCRIPTION')
  if (prescription.status !== 'VALIDATED') {
    throw new ClinicalPrintRefused(
      'Seule une prescription validée s’imprime : un brouillon ne se remet pas au patient.',
    )
  }
  if (prescription.consultation_id !== consultation.id) {
    throw new ClinicalPrintRefused(
      'Cette prescription n’appartient pas à la consultation affichée.',
    )
  }
  const professional = await validatorOf(prescription, consultation, me)
  const data = prescriptionFromResource({
    prescription,
    clinic: await clinicOf(),
    patient: clinicalPatientFromFields(consultation.patient),
    professional,
    consultationAt: consultation.effective_at,
    labels: defaultClinicalLabels(),
    // Version écrite par le serveur dans generated_documents (ValidatePrescriptionHandler::TEMPLATE_VERSION).
    templateVersion: CLINICAL_TEMPLATE_VERSIONS.OPTICAL_PRESCRIPTION,
  })
  const rendered = await renderDocument(buildDocument('OPTICAL_PRESCRIPTION', data))
  return keepOrReopen(
    rendered,
    {
      documentType: 'OPTICAL_PRESCRIPTION',
      sourceKey: `optical_prescription:${prescription.id}`,
      revisionNo: prescription.version_no,
    },
    prescription.document_id,
    prescription.id,
  )
}

/** Vrai seulement si les PDF conservés survivent à la fermeture du navigateur (coffre livré). */
export function clinicalDocumentsPersistent(): boolean {
  return isDocumentStorePersistent()
}

// --- Lecture à l'écran d'une prescription ---------------------------------------------------------

export interface PrescriptionScreenEye {
  eye: 'OD' | 'OG'
  label: string
  noCorrection: boolean
  sphere: string
  cylinder: string
  axis: string
  addition: string
  fittingHeight: string
}

export interface PrescriptionScreenModel {
  versionNo: number
  status: 'DRAFT' | 'VALIDATED'
  external: { prescriber: string; prescribedOn: string | null } | null
  eyes: PrescriptionScreenEye[]
  pd: { total: string; od: string; og: string }
  pdJustification: string | null
  usage: string | null
  lensType: string | null
  lensIndex: string | null
  treatments: string[]
  notes: string | null
}

/**
 * Ce que l'écran de lecture affiche d'une prescription : LES MÊMES valeurs que le document imprimé (même
 * adaptateur, mêmes libellés, mêmes mises en forme : signe explicite, zéro visible « 0,00 », valeur
 * absente « — », jamais zéro par défaut, unités visibles).
 */
export function prescriptionScreenModel(prescription: Prescription): PrescriptionScreenModel {
  const body = prescriptionBodyFromResource(prescription, defaultClinicalLabels())
  return {
    versionNo: prescription.version_no,
    status: prescription.status,
    external:
      prescription.origin === 'EXTERNAL' && prescription.external_prescriber !== null
        ? {
            prescriber: prescription.external_prescriber,
            prescribedOn: prescription.external_prescribed_on,
          }
        : null,
    eyes: body.eyes.map((eye) => ({
      eye: eye.eye,
      label: EYE_LABELS[eye.eye],
      noCorrection: eye.noCorrection,
      sphere: formatDiopter(eye.sphere),
      cylinder: formatDiopter(eye.cylinder),
      axis: formatAxis(eye.axis),
      addition: formatDiopter(eye.addition),
      fittingHeight: formatMillimeters(eye.fittingHeight),
    })),
    pd: {
      total: formatMillimeters(body.pdTotal),
      od: formatMillimeters(body.pdOd),
      og: formatMillimeters(body.pdOg),
    },
    pdJustification: body.pdInconsistencyReason,
    usage: body.usage,
    lensType: body.lensType,
    lensIndex: body.lensIndex,
    treatments: [...body.treatments],
    notes: body.notes,
  }
}
