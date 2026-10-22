import type { MedicationOrderData } from '../../data/clinical'
import { invalid } from '../../engine/errors'
import { formatDateFr, shortReference } from '../../engine/format'
import { type Block, type DocumentModel, fields, paragraph, signature } from '../../engine/model'
import { assembleModel, type BuildOptions, requireText, resolveOptions } from '../common'
import { SIGNATURE_DISCLAIMER } from './opticalPrescription'
import { medicationTable, patientRowsClinical, professionalRows } from './shared'

/**
 * Ordonnance de médicaments (fichier 11) : patient, date, professionnel, produits, forme, dosage,
 * posologie, fréquence, durée et instructions ISSUS DE LA SAISIE. Le modèle ne complète jamais une
 * posologie absente, ne suggère aucun produit et ne vérifie aucune compatibilité : une information non
 * saisie s'imprime par un tiret. Prescrire ne crée ni vente ni mouvement de stock. Le modèle de
 * l'ordonnance reste à valider par le cabinet selon les capacités professionnelles (fichier 11).
 */
export function buildMedicationOrder(
  data: MedicationOrderData,
  options?: BuildOptions,
): DocumentModel {
  const resolved = resolveOptions(options)
  const draft = data.status === 'DRAFT'
  if (!draft && data.items.length === 0) {
    throw invalid('medication.no_items', 'items', 'Une ordonnance validée comporte un produit.')
  }
  for (const item of data.items) requireText(item.name, 'name')
  const timeZone = data.clinic.timezone
  const wide = resolved.format === 'A4'

  const body: Block[] = [
    fields(patientRowsClinical(data.patient), 1),
    fields(professionalRows(data.professional, data.clinic, 'Prescripteur'), 1),
    medicationTable('Produits prescrits', data.items, wide),
    paragraph(
      '\u{2014} : information non précisée par le prescripteur. L’application ne complète aucune posologie.',
      { muted: true, sizePt: 8 },
    ),
  ]
  if (data.notes !== null && data.notes.trim() !== '') {
    body.push(fields([{ label: 'Observations', value: data.notes }], 1))
  }
  body.push(
    signature([{ label: 'Signature et cachet du professionnel', heightMm: 24 }]),
    paragraph(SIGNATURE_DISCLAIMER, { muted: true, sizePt: 8 }),
  )

  return assembleModel({
    kind: 'MEDICATION_ORDER',
    templateVersion: data.templateVersion,
    clinic: data.clinic,
    options: resolved,
    family: 'clinical',
    heading: 'ORDONNANCE',
    subtitle: draft ? 'Brouillon' : null,
    number: null,
    reference: `Ordonnance ${shortReference(data.orderId)}`,
    documentAt: data.orderedAt,
    draft,
    headerFields: [{ label: 'Date', value: formatDateFr(data.orderedAt, timeZone, 'ordered_at') }],
    pdfTitle: 'Ordonnance de médicaments',
    body,
  })
}
