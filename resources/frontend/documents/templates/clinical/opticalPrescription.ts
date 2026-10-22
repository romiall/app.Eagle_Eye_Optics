import type { OpticalPrescriptionData } from '../../data/clinical'
import { invalid } from '../../engine/errors'
import { formatCivilDateFr, formatDateFr, shortReference } from '../../engine/format'
import {
  type Block,
  type DocumentModel,
  type FieldRow,
  fields,
  notice,
  paragraph,
  signature,
} from '../../engine/model'
import {
  assembleModel,
  type BuildOptions,
  requireInstant,
  requireText,
  resolveOptions,
} from '../common'
import {
  patientRowsClinical,
  prescriptionBlocks,
  prescriptionDetailRows,
  professionalRows,
} from './shared'

export const SIGNATURE_DISCLAIMER =
  'Le nom du professionnel imprimé n’est pas une signature électronique certifiée. La signature et le cachet se posent sur la version imprimée.'

/**
 * Prescription optique (fichier 11) : cabinet, patient et dossier, date de consultation,
 * professionnel, version, tableau OD/OG (sphère, cylindre, axe, addition), DP totale et monoculaire,
 * hauteur, usage, type, indice, traitements et observations approuvées. Imprimée à part du dossier
 * complet. Un brouillon porte BROUILLON ; une prescription validée est une pièce versionnée. Le
 * document n'ajoute aucun texte médical : aucune description, aucun diagnostic, aucun conseil.
 * Une ordonnance EXTERNE transcrite garde sa provenance (prescripteur et date d'origine).
 */
export function buildOpticalPrescription(
  data: OpticalPrescriptionData,
  options?: BuildOptions,
): DocumentModel {
  const resolved = resolveOptions(options)
  const draft = data.status === 'DRAFT'
  if (!draft) {
    requireInstant(data.validatedAt, 'validated_at')
    if (data.professional === null) {
      throw invalid(
        'prescription.professional_missing',
        'professional',
        'Une prescription validée porte le nom du professionnel qui l’a validée.',
      )
    }
  }
  if (data.origin === 'EXTERNAL') requireText(data.externalPrescriber, 'external_prescriber')
  const timeZone = data.clinic.timezone
  const prescription = prescriptionBlocks(data.body, !draft)

  const headerFields: FieldRow[] = [{ label: 'Version', value: String(data.versionNo) }]
  if (data.origin === 'EXTERNAL') {
    if (data.externalPrescribedOn !== null) {
      headerFields.push({
        label: 'Ordonnance du',
        value: formatCivilDateFr(data.externalPrescribedOn, 'external_prescribed_on'),
      })
    }
  } else if (data.consultationAt !== null) {
    headerFields.push({
      label: 'Consultation du',
      value: formatDateFr(data.consultationAt, timeZone, 'consultation_at'),
    })
  }
  if (data.validatedAt !== null) {
    headerFields.push({
      label: 'Validée le',
      value: formatDateFr(data.validatedAt, timeZone, 'validated_at'),
    })
  }

  const body: Block[] = [fields(patientRowsClinical(data.patient), 1)]
  if (data.professional !== null) {
    const label = data.origin === 'EXTERNAL' ? 'Transcription validée par' : 'Prescripteur'
    body.push(fields(professionalRows(data.professional, data.clinic, label), 1))
  }
  if (data.origin === 'EXTERNAL') {
    body.push(
      notice(
        'info',
        `Ordonnance externe transcrite. Prescripteur d’origine : ${data.externalPrescriber ?? ''}.${
          data.professional === null ? '' : ' La transcription est validée par le professionnel indiqué.'
        }`,
      ),
    )
  }
  body.push(
    ...prescription.blocks,
    fields(prescriptionDetailRows(data.body), 1),
    signature([{ label: 'Signature et cachet du professionnel', heightMm: 24 }]),
    paragraph(SIGNATURE_DISCLAIMER, { muted: true, sizePt: 8 }),
  )

  return assembleModel({
    kind: 'OPTICAL_PRESCRIPTION',
    templateVersion: data.templateVersion,
    clinic: data.clinic,
    options: resolved,
    family: 'clinical',
    heading: 'PRESCRIPTION OPTIQUE',
    subtitle: draft ? `Brouillon, version ${data.versionNo}` : `Version ${data.versionNo}`,
    number: null,
    reference: `Prescription ${shortReference(data.prescriptionId)} \u{00B7} version ${data.versionNo}`,
    documentAt: data.validatedAt ?? data.consultationAt,
    draft,
    headerFields,
    pdfTitle: `Prescription optique - version ${data.versionNo}`,
    body,
    warnings: prescription.warnings,
  })
}
