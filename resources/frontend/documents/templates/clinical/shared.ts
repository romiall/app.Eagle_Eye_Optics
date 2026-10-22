import type { DocumentClinic } from '../../data/clinic'
import type {
  ClinicalPatient,
  MedicationItem,
  PrescriptionBody,
  PrescriptionEye,
  ProfessionalIdentity,
} from '../../data/clinical'
import { invalid } from '../../engine/errors'
import {
  formatAxis,
  formatCivilDateFr,
  formatDiopter,
  formatMillimeters,
  formatPersonName,
  MISSING,
} from '../../engine/format'
import {
  type Block,
  type FieldRow,
  fields,
  paragraph,
  row,
  table,
  type TableColumn,
} from '../../engine/model'

/**
 * Éléments partagés des documents cliniques (D02) : bloc patient, bloc professionnel, tableaux OD/OG
 * de la prescription (fichier 11 : « le tableau respecte les deux lignes OD/OG du modèle DP »).
 *
 * Règles d'impression de la prescription (fichier 02) : zéro est une valeur visible, une mesure
 * absente est un tiret, JAMAIS zéro ; un cylindre non nul demande un axe ; une prescription VALIDÉE
 * exige des valeurs saisies ou la mention d'absence de correction pour chaque œil (un brouillon
 * l'accepte avec un avertissement).
 */
export const EYE_LABELS = { OD: 'OD (œil droit)', OG: 'OG (œil gauche)' } as const

export const NOT_MEASURED_LEGEND =
  '\u{2014} : valeur non mesurée ou non prescrite. Aucune valeur n’est remplacée par zéro.'

export function patientRowsClinical(patient: ClinicalPatient): FieldRow[] {
  const rows: FieldRow[] = [
    { label: 'Patient', value: formatPersonName(patient.lastName, patient.firstNames) },
    { label: 'Dossier n°', value: patient.recordNumber },
  ]
  if (patient.sex !== null) rows.push({ label: 'Sexe', value: patient.sex })
  if (patient.birthDate !== null) {
    rows.push({ label: 'Né(e) le', value: formatCivilDateFr(patient.birthDate, 'birth_date') })
  }
  return rows
}

/** Professionnel : nom imprimé (pas une signature certifiée) et numéro réel s'il est fourni. */
export function professionalRows(
  professional: ProfessionalIdentity,
  clinic: DocumentClinic,
  label = 'Professionnel',
): FieldRow[] {
  const rows: FieldRow[] = [{ label, value: professional.displayName }]
  if (professional.professionalNumber !== null) {
    rows.push({ label: 'N° professionnel', value: professional.professionalNumber })
  } else if (clinic.testDocument) {
    rows.push({ label: 'N° professionnel', value: 'à renseigner (mention réelle non fournie)' })
  }
  return rows
}

function column(
  key: string,
  header: string,
  width: TableColumn['width'],
  align: TableColumn['align'],
): TableColumn {
  return { key, header, width, align }
}

function hasValue(eye: PrescriptionEye): boolean {
  return (
    eye.sphere !== null || eye.cylinder !== null || eye.axis !== null || eye.addition !== null
  )
}

/** Un nombre décimal canonique est non nul s'il contient un chiffre autre que zéro. */
function isNonZero(value: string | null): boolean {
  return value !== null && /[1-9]/.test(value)
}

export interface PrescriptionBlocks {
  blocks: Block[]
  warnings: string[]
}

/**
 * Tableaux de la prescription optique. `strict` (prescription validée) transforme les manques en
 * erreurs : l'impression d'une prescription incomplète est refusée, pas dissimulée.
 */
export function prescriptionBlocks(body: PrescriptionBody, strict: boolean): PrescriptionBlocks {
  const warnings: string[] = []
  const problem = (code: string, field: string, message: string): void => {
    if (strict) throw invalid(code, field, message)
    warnings.push(message)
  }
  const [first, second] = body.eyes
  if (first.eye !== 'OD' || second.eye !== 'OG') {
    throw invalid('prescription.eye_order', 'eyes', 'Les yeux se présentent dans l’ordre OD puis OG.')
  }
  for (const eye of body.eyes) {
    const name = eye.eye
    if (eye.noCorrection && (hasValue(eye) || eye.fittingHeight !== null)) {
      problem(
        'prescription.no_correction_with_values',
        name,
        `${name} : « absence de correction » contredit les valeurs saisies.`,
      )
    }
    if (!eye.noCorrection && !hasValue(eye)) {
      problem(
        'prescription.eye_empty',
        name,
        `${name} : ni valeurs saisies ni mention d’absence de correction.`,
      )
    }
    if (isNonZero(eye.cylinder) && eye.axis === null) {
      problem('prescription.axis_missing', name, `${name} : un cylindre non nul demande un axe.`)
    }
  }

  const correctionRows = body.eyes.map((eye) =>
    row(
      eye.noCorrection
        ? [EYE_LABELS[eye.eye], 'Absence de correction', MISSING, MISSING, MISSING]
        : [
            EYE_LABELS[eye.eye],
            formatDiopter(eye.sphere, `${eye.eye}.sphere`),
            formatDiopter(eye.cylinder, `${eye.eye}.cylinder`),
            formatAxis(eye.axis, `${eye.eye}.axis`),
            formatDiopter(eye.addition, `${eye.eye}.addition`),
          ],
    ),
  )
  const centeringRows = body.eyes.map((eye) =>
    row([
      EYE_LABELS[eye.eye],
      formatMillimeters(eye.eye === 'OD' ? body.pdOd : body.pdOg, `pd_${eye.eye}`),
      formatMillimeters(eye.fittingHeight, `${eye.eye}.fitting_height`),
    ]),
  )

  const blocks: Block[] = [
    table(
      'Correction par œil',
      [
        column('eye', 'Œil', { mm: 34 }, 'left'),
        column('sphere', 'Sphère (dioptries)', { fr: 1 }, 'right'),
        column('cylinder', 'Cylindre (dioptries)', { fr: 1 }, 'right'),
        column('axis', 'Axe (degrés)', { fr: 0.8 }, 'right'),
        column('addition', 'Addition (dioptries)', { fr: 1 }, 'right'),
      ],
      correctionRows,
    ),
    table(
      'Centrage',
      [
        column('eye', 'Œil', { mm: 34 }, 'left'),
        column('pd', 'DP monoculaire (mm)', { fr: 1 }, 'right'),
        column('height', 'Hauteur de montage (mm)', { fr: 1 }, 'right'),
      ],
      centeringRows,
    ),
    fields([{ label: 'DP totale', value: formatMillimeters(body.pdTotal, 'pd_total') }], 1),
    paragraph(NOT_MEASURED_LEGEND, { muted: true, sizePt: 8 }),
  ]
  return { blocks, warnings }
}

/**
 * Tableau des médicaments (ordonnance et fiche de consultation) : informations ISSUES DE LA SAISIE, une
 * information absente s'imprime par un tiret, jamais complétée. Sept colonnes en A4, deux en A5.
 */
export function medicationTable(
  title: string,
  items: readonly MedicationItem[],
  wide: boolean,
): Block {
  const text = (value: string | null): string => value ?? MISSING
  const columns: TableColumn[] = wide
    ? [
        column('name', 'Produit', { fr: 1.3 }, 'left'),
        column('form', 'Forme', { mm: 18 }, 'left'),
        column('strength', 'Dosage', { mm: 20 }, 'left'),
        column('posology', 'Posologie', { fr: 1 }, 'left'),
        column('frequency', 'Fréquence', { mm: 22 }, 'left'),
        column('duration', 'Durée', { mm: 18 }, 'left'),
        column('instructions', 'Instructions', { fr: 1 }, 'left'),
      ]
    : [column('name', 'Produit', { fr: 1 }, 'left'), column('detail', 'Prescription', { fr: 2 }, 'left')]
  const rows = items.map((item) =>
    wide
      ? row([
          item.name,
          text(item.form),
          text(item.strength),
          text(item.posology),
          text(item.frequency),
          text(item.duration),
          text(item.instructions),
        ])
      : row([
          item.name,
          [
            `Forme : ${text(item.form)}`,
            `Dosage : ${text(item.strength)}`,
            `Posologie : ${text(item.posology)}`,
            `Fréquence : ${text(item.frequency)}`,
            `Durée : ${text(item.duration)}`,
            `Instructions : ${text(item.instructions)}`,
          ].join('\n'),
        ]),
  )
  return table(title, columns, rows, { sizePt: 8.5 })
}

/** Justification d'un écart de DP, usage, verres, traitements et observations approuvées de la prescription. */
export function prescriptionDetailRows(body: PrescriptionBody): FieldRow[] {
  const rows: FieldRow[] = []
  if (body.pdInconsistencyReason !== null && body.pdInconsistencyReason.trim() !== '') {
    rows.push({ label: 'Justification de l’écart de DP', value: body.pdInconsistencyReason })
  }
  rows.push(
    { label: 'Usage', value: body.usage ?? MISSING },
    { label: 'Type de verre', value: body.lensType ?? MISSING },
    { label: 'Indice', value: body.lensIndex ?? MISSING },
    { label: 'Traitements', value: body.treatments.length > 0 ? body.treatments.join(', ') : MISSING },
  )
  if (body.notes !== null && body.notes.trim() !== '') {
    rows.push({ label: 'Observations', value: body.notes })
  }
  return rows
}
