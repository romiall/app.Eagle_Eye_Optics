import type {
  ClinicalRecordData,
  ConsultationEntryData,
  PrescriptionSummaryData,
} from '../../data/clinical'
import { invalid } from '../../engine/errors'
import {
  formatAxis,
  formatDateFr,
  formatDateTimeFr,
  formatDiopter,
  formatMillimeters,
  shortReference,
} from '../../engine/format'
import {
  type Block,
  type DocumentModel,
  type FieldRow,
  fields,
  heading,
  notice,
  paragraph,
  row,
  table,
  type TableColumn,
} from '../../engine/model'
import { assembleModel, type BuildOptions, requireInstant, resolveOptions } from '../common'
import {
  EYE_LABELS,
  medicationTable,
  NOT_MEASURED_LEGEND,
  patientRowsClinical,
  prescriptionBlocks,
  prescriptionDetailRows,
} from './shared'

const NOT_PROVIDED = 'Non renseigné'

function column(
  key: string,
  header: string,
  width: TableColumn['width'],
  align: TableColumn['align'],
): TableColumn {
  return { key, header, width, align }
}

function yesNo(value: boolean | null): string {
  if (value === null) return NOT_PROVIDED
  return value ? 'Oui' : 'Non'
}

const STATUS_LABELS = {
  DRAFT: 'brouillon',
  IN_PROGRESS: 'en cours',
  COMPLETED: 'terminée',
} as const

function consultationBlocks(
  entry: ConsultationEntryData,
  timeZone: string,
  wide: boolean,
): Block[] {
  const blocks: Block[] = [
    heading(
      `Consultation du ${formatDateFr(entry.effectiveAt, timeZone, 'effective_at')} (version ${entry.revisionNo}, ${STATUS_LABELS[entry.status]})`,
      2,
    ),
  ]
  const anamnesis: FieldRow[] = [
    // Fichier 11 : « auteur/version ». L'auteur est celui de la révision imprimée (nom figé).
    { label: 'Auteur de la révision', value: entry.clinicianName },
    {
      label: 'Motifs de consultation',
      value: entry.complaints.length > 0 ? entry.complaints.join(', ') : NOT_PROVIDED,
    },
    { label: 'Symptômes', value: entry.symptoms ?? NOT_PROVIDED },
    { label: 'Porte des lunettes', value: yesNo(entry.wearsGlasses) },
  ]
  if (entry.glassesDuration !== null) {
    anamnesis.push({ label: 'Depuis', value: entry.glassesDuration })
  }
  anamnesis.push({
    label: 'Correction précédente disponible',
    value: yesNo(entry.previousCorrectionAvailable),
  })
  blocks.push(fields(anamnesis, 1))

  if (entry.histories.length > 0) {
    blocks.push(
      fields(
        entry.histories.map((history) => {
          const content = history.knownNone
            ? 'Aucun connu'
            : history.terms.length > 0
              ? history.terms.join(', ')
              : NOT_PROVIDED
          const comments = history.comments === null ? '' : `\nCommentaires : ${history.comments}`
          return { label: history.group, value: `${content}${comments}` }
        }),
        1,
      ),
    )
  }

  if (entry.acuities.length > 0) {
    blocks.push(
      table(
        'Acuité visuelle',
        [
          column('context', 'Contexte', { fr: 1 }, 'left'),
          column('distance', 'Distance', { mm: 22 }, 'left'),
          column('correction', 'Correction', { mm: 28 }, 'left'),
          column('od', 'OD', { mm: 18 }, 'right'),
          column('og', 'OG', { mm: 18 }, 'right'),
          column('ou', 'OU', { mm: 18 }, 'right'),
          column('scale', 'Échelle', { mm: 27 }, 'left'),
        ],
        entry.acuities.map((acuity) =>
          row([
            acuity.context,
            acuity.distance,
            acuity.correction,
            acuity.od,
            acuity.og,
            acuity.ou,
            acuity.scale ?? 'non précisée',
          ]),
        ),
        { sizePt: 8.5 },
      ),
    )
  }

  if (entry.refraction.length > 0) {
    blocks.push(
      table(
        'Réfraction',
        [
          column('stage', 'Examen', { fr: 1 }, 'left'),
          column('eye', 'Œil', { mm: 30 }, 'left'),
          column('sphere', 'Sphère', { mm: 22 }, 'right'),
          column('cylinder', 'Cylindre', { mm: 22 }, 'right'),
          column('axis', 'Axe', { mm: 18 }, 'right'),
          column('addition', 'Addition', { mm: 22 }, 'right'),
        ],
        entry.refraction.map((measure) =>
          row([
            measure.stage,
            EYE_LABELS[measure.eye],
            formatDiopter(measure.sphere),
            formatDiopter(measure.cylinder),
            formatAxis(measure.axis),
            formatDiopter(measure.addition),
          ]),
        ),
        { sizePt: 8.5 },
      ),
    )
  }
  const refraction: FieldRow[] = []
  if (entry.ametropias.length > 0) {
    refraction.push({ label: 'Amétropies', value: entry.ametropias.join(', ') })
  }
  if (entry.refractionObservations !== null) {
    refraction.push({ label: 'Observations de réfraction', value: entry.refractionObservations })
  }
  if (refraction.length > 0) blocks.push(fields(refraction, 1))
  blocks.push(paragraph(NOT_MEASURED_LEGEND, { muted: true, sizePt: 8 }))

  const centering: FieldRow[] = [
    { label: 'DP totale', value: formatMillimeters(entry.pdTotal) },
    { label: 'DP OD', value: formatMillimeters(entry.pdOd) },
    { label: 'DP OG', value: formatMillimeters(entry.pdOg) },
  ]
  if (entry.pdInconsistencyReason !== null) {
    centering.push({ label: 'Justification d’écart de DP', value: entry.pdInconsistencyReason })
  }
  blocks.push(fields(centering, 2))

  // Traitement (fichier 11) : les médicaments tels que saisis, rien n'est complété.
  if (entry.medications.length > 0) {
    blocks.push(medicationTable('Médicaments prescrits', entry.medications, wide))
  }

  const conclusion: FieldRow[] = [{ label: 'Conclusion', value: entry.conclusion ?? NOT_PROVIDED }]
  if (entry.referral === true) {
    conclusion.push(
      { label: 'Orientation', value: 'Orienté vers ophtalmologiste' },
      { label: 'Niveau d’urgence', value: entry.referralUrgency ?? NOT_PROVIDED },
      {
        label: 'Motif(s) d’orientation',
        value: entry.referralReasons.length > 0 ? entry.referralReasons.join(', ') : NOT_PROVIDED,
      },
    )
  } else {
    conclusion.push({ label: 'Orientation', value: entry.referral === false ? 'Non' : NOT_PROVIDED })
  }
  if (entry.missingDataReason !== null) {
    conclusion.push({ label: 'Données non renseignées', value: entry.missingDataReason })
  }
  if (entry.amendmentReason !== null) {
    conclusion.push({ label: 'Motif de la correction', value: entry.amendmentReason })
  }
  blocks.push(fields(conclusion, 1))
  return blocks
}

function prescriptionSection(
  summary: PrescriptionSummaryData,
  timeZone: string,
  warnings: string[],
): Block[] {
  const strict = summary.status === 'VALIDATED'
  // Dans le dossier, une prescription incomplète est signalée, jamais bloquante pour tout l'export.
  const built = prescriptionBlocks(summary.body, false)
  warnings.push(...built.warnings)
  const date = summary.at === null ? '' : `, ${formatDateFr(summary.at, timeZone, 'prescription_at')}`
  return [
    heading(
      `Prescription optique, version ${summary.versionNo} (${strict ? 'validée' : 'brouillon'}${date})`,
      3,
    ),
    ...built.blocks,
    fields(prescriptionDetailRows(summary.body), 1),
  ]
}

/**
 * Dossier clinique en PDF (fichier 11) : identité, consultations sélectionnées, mesures,
 * prescriptions, conclusion, orientation et suivi, auteur et version, avec l'indication de la
 * SÉLECTION (une consultation ou l'historique) et la date d'export. Export réservé au professionnel et
 * à la gérante : il n'est jamais donné avec un reçu ni à la caisse (voir `documents/access.ts`).
 */
export function buildClinicalRecord(
  data: ClinicalRecordData,
  options?: BuildOptions,
): DocumentModel {
  const resolved = resolveOptions(options)
  requireInstant(data.exportedAt, 'exported_at')
  if (data.consultations.length === 0 && data.prescriptions.length === 0) {
    throw invalid('record.empty', 'consultations', 'Le dossier exporté ne contient aucune consultation.')
  }
  if (data.selection === 'SINGLE' && data.consultations.length > 1) {
    throw invalid(
      'record.selection_mismatch',
      'selection',
      'Une sélection « une consultation » ne contient qu’une consultation.',
    )
  }
  const timeZone = data.clinic.timezone
  const provisional = data.consultations.some((entry) => entry.status !== 'COMPLETED')
  const warnings: string[] = []

  const body: Block[] = [fields(patientRowsClinical(data.patient), 1)]
  if (provisional) {
    body.push(
      notice('warning', 'Contenu provisoire : au moins une consultation sélectionnée n’est pas terminée.'),
    )
  }
  const wide = resolved.format === 'A4'
  for (const entry of data.consultations) body.push(...consultationBlocks(entry, timeZone, wide))
  if (data.prescriptions.length > 0) {
    body.push(heading('Prescriptions', 1))
    for (const summary of data.prescriptions) {
      body.push(...prescriptionSection(summary, timeZone, warnings))
    }
  }
  body.push(
    paragraph('Document confidentiel : il contient des données de santé.', {
      muted: true,
      sizePt: 8.5,
    }),
  )

  const single = data.selection === 'SINGLE'
  return assembleModel({
    kind: 'CLINICAL_RECORD',
    templateVersion: data.templateVersion,
    clinic: data.clinic,
    options: resolved,
    family: 'clinical',
    heading: single ? 'FICHE DE CONSULTATION' : 'DOSSIER CLINIQUE',
    subtitle: single
      ? 'Une consultation'
      : `Historique : ${data.consultations.length} consultation(s)`,
    number: null,
    reference: `Dossier ${shortReference(data.recordId)}`,
    documentAt: data.exportedAt,
    draft: provisional,
    headerFields: [
      { label: 'Exporté le', value: formatDateTimeFr(data.exportedAt, timeZone, 'exported_at') },
      { label: 'Exporté par', value: data.exportedByName },
      { label: 'Sélection', value: single ? 'Une consultation' : 'Historique' },
    ],
    pdfTitle: single ? 'Fiche de consultation' : 'Dossier clinique',
    body,
    warnings,
  })
}
