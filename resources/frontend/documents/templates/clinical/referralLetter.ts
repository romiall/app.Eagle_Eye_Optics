import type { ReferralLetterData } from '../../data/clinical'
import { formatDateFr, formatPersonName, shortReference } from '../../engine/format'
import {
  type Block,
  type DocumentModel,
  fields,
  heading,
  paragraph,
  signature,
} from '../../engine/model'
import { assembleModel, type BuildOptions, resolveOptions } from '../common'
import { SIGNATURE_DISCLAIMER } from './opticalPrescription'
import { patientRowsClinical, professionalRows } from './shared'

/**
 * Lettre d'orientation vers un ophtalmologiste (DP §10, fichier 02). Le niveau d'urgence et les motifs
 * sont ceux choisis par le professionnel : le modèle n'en déduit aucun et n'écrit aucune appréciation
 * médicale. Le niveau d'urgence n'apparaît que sur ce document clinique, jamais sur une pièce
 * financière. Le résumé clinique est repris tel que rédigé par le professionnel.
 */
export function buildReferralLetter(
  data: ReferralLetterData,
  options?: BuildOptions,
): DocumentModel {
  const resolved = resolveOptions(options)
  const draft = data.status === 'DRAFT'
  const timeZone = data.clinic.timezone
  const patientName = formatPersonName(data.patient.lastName, data.patient.firstNames)

  const body: Block[] = [
    fields(
      [
        {
          label: 'À l’attention de',
          value: data.addressee ?? 'Confrère ophtalmologiste',
        },
        {
          label: 'Objet',
          value: `Orientation du patient ${patientName} (dossier n° ${data.patient.recordNumber})`,
        },
      ],
      1,
    ),
    fields(patientRowsClinical(data.patient), 1),
    paragraph('Je vous adresse le patient désigné ci-dessus pour avis ophtalmologique.'),
    fields(
      [
        { label: 'Niveau d’urgence', value: data.urgency ?? 'Non renseigné' },
        {
          label: 'Motif(s) d’orientation',
          value: data.reasons.length > 0 ? data.reasons.join(', ') : 'Non renseigné',
        },
      ],
      1,
    ),
    heading('Résumé clinique', 2),
    paragraph(data.summary !== null && data.summary.trim() !== '' ? data.summary : 'Non renseigné'),
  ]
  if (data.measurements.length > 0) {
    body.push(heading('Mesures jointes', 2))
    for (const line of data.measurements) body.push(paragraph(line, { sizePt: 9 }))
  }
  body.push(
    paragraph('Confraternellement,'),
    fields(professionalRows(data.professional, data.clinic, 'Professionnel'), 1),
    signature([{ label: 'Signature et cachet du professionnel', heightMm: 24 }]),
    paragraph(SIGNATURE_DISCLAIMER, { muted: true, sizePt: 8 }),
  )

  return assembleModel({
    kind: 'REFERRAL_LETTER',
    templateVersion: data.templateVersion,
    clinic: data.clinic,
    options: resolved,
    family: 'clinical',
    heading: 'LETTRE D’ORIENTATION',
    subtitle: draft ? 'Brouillon' : null,
    number: null,
    reference: `Lettre ${shortReference(data.letterId)}`,
    documentAt: data.letterAt,
    draft,
    headerFields: [{ label: 'Date', value: formatDateFr(data.letterAt, timeZone, 'letter_at') }],
    pdfTitle: 'Lettre d’orientation',
    body,
  })
}
