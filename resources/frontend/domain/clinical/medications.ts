import { CONTROL_IDS } from './controls'
import { cleanText, type NewId, type StepCodec, type StepError, textError } from './fields'
import { DURATION_UNIT_LABELS, MEDICATION_FORM_LABELS } from './labels'

/**
 * Rubrique MEDICATIONS (C04 ; fichier 02, « Médicaments et orientation » ; DP §9) : plusieurs lignes de
 * médicaments en SAISIE LIBRE CONTRÔLÉE (aucune base de médicaments n'est fournie ni inventée).
 *
 * - Nom et forme (collyre, pommade, comprimé, autre) obligatoires ; « Autre » exige la précision de la forme.
 * - Dosage / concentration, posologie, fréquence, durée en texte et instructions : facultatifs, conservés tels que
 *   saisis. AUCUNE posologie, fréquence ni durée n'est déduite ou complétée (`missingPosology` le SIGNALE seulement).
 * - Durée structurée (nombre entier strictement positif et unité jour / semaine / mois) : les deux ou aucune ; elle
 *   sert au calcul d'un contrôle choisi par le professionnel, jamais choisi ici.
 * - L'ordre des lignes est celui de l'ordonnance (la position est le rang) : on peut monter et descendre une ligne.
 * - Prescrire ne crée aucune vente et ne décrémente aucun stock.
 *
 * Règles pures : ni Vue, ni réseau, ni stockage.
 */
export type MedicationForm = keyof typeof MEDICATION_FORM_LABELS
export type DurationUnit = keyof typeof DURATION_UNIT_LABELS

export const MEDICATION_FORMS = Object.keys(MEDICATION_FORM_LABELS) as MedicationForm[]
export const DURATION_UNITS = Object.keys(DURATION_UNIT_LABELS) as DurationUnit[]
export const ORDERS_MAX = 20
export const DURATION_VALUE_MAX = 4294967295
export const NAME_MAX = 180
export const FORM_OTHER_MAX = 100
export const STRENGTH_MAX = 100
export const FREQUENCY_MAX = 180
export const DURATION_TEXT_MAX = 180

export interface OrderData {
  id: string
  catalog_item_id: string | null
  name: string
  form: MedicationForm
  form_other: string | null
  strength: string | null
  dosage: string | null
  frequency: string | null
  duration_text: string | null
  duration_value: number | null
  duration_unit: DurationUnit | null
  instructions: string | null
}

export interface MedicationsData {
  orders: OrderData[]
}

export interface OrderDraft {
  id: string
  /** Article du catalogue référencé (aucun écran de catalogue à ce jalon : conservé tel que reçu). */
  catalogItemId: string | null
  name: string
  form: MedicationForm | null
  formOther: string
  strength: string
  dosage: string
  frequency: string
  durationText: string
  durationValue: string
  durationUnit: DurationUnit | null
  instructions: string
}

export interface MedicationsDraft {
  orders: OrderDraft[]
}

export function blankOrder(newId: NewId): OrderDraft {
  return {
    id: newId(),
    catalogItemId: null,
    name: '',
    form: null,
    formOther: '',
    strength: '',
    dosage: '',
    frequency: '',
    durationText: '',
    durationValue: '',
    durationUnit: null,
    instructions: '',
  }
}

export function blankMedications(): MedicationsDraft {
  return { orders: [] }
}

export function medicationsDraft(data: MedicationsData): MedicationsDraft {
  return {
    orders: (data.orders ?? []).map((order) => ({
      id: order.id,
      catalogItemId: order.catalog_item_id,
      name: order.name,
      form: order.form,
      formOther: order.form_other ?? '',
      strength: order.strength ?? '',
      dosage: order.dosage ?? '',
      frequency: order.frequency ?? '',
      durationText: order.duration_text ?? '',
      durationValue: order.duration_value === null ? '' : String(order.duration_value),
      durationUnit: order.duration_unit,
      instructions: order.instructions ?? '',
    })),
  }
}

/** Nombre entier strictement positif saisi, ou null (vide ou invalide : `durationError` dit pourquoi). */
export function durationNumber(text: string): number | null {
  const cleaned = cleanText(text)
  if (cleaned === null || !/^[0-9]{1,10}$/.test(cleaned)) return null
  const value = Number(cleaned)
  return value >= 1 && value <= DURATION_VALUE_MAX ? value : null
}

function orderData(order: OrderDraft): OrderData {
  const form = order.form ?? 'OTHER'
  return {
    id: order.id,
    catalog_item_id: order.catalogItemId,
    name: cleanText(order.name) ?? '',
    form,
    form_other: form === 'OTHER' ? cleanText(order.formOther) : null,
    strength: cleanText(order.strength),
    dosage: cleanText(order.dosage),
    frequency: cleanText(order.frequency),
    duration_text: cleanText(order.durationText),
    duration_value: durationNumber(order.durationValue),
    duration_unit: order.durationUnit,
    instructions: cleanText(order.instructions),
  }
}

export function medicationsData(draft: MedicationsDraft): MedicationsData {
  return { orders: draft.orders.map(orderData) }
}

/** Intitulé d'une ligne pour les messages (« Médicament 2 » ou son nom). */
export function orderTitle(order: OrderDraft, index: number): string {
  return cleanText(order.name) ?? `Médicament ${index + 1}`
}

export function medicationsErrors(draft: MedicationsDraft): StepError[] {
  const errors: StepError[] = []
  if (draft.orders.length > ORDERS_MAX) {
    errors.push({
      path: 'orders',
      controlId: CONTROL_IDS.orders,
      message: `Pas plus de ${ORDERS_MAX} médicaments dans une consultation.`,
    })
  }
  draft.orders.forEach((order, index) => {
    const path = `orders.${index}`
    const title = orderTitle(order, index)
    const add = (field: string, controlField: string, message: string | null): void => {
      if (message !== null) {
        errors.push({
          path: `${path}.${field}`,
          controlId: CONTROL_IDS.order(index, controlField),
          message,
        })
      }
    }
    if (cleanText(order.name) === null) {
      add('name', 'nom', `${title} : le nom du médicament est obligatoire.`)
    } else {
      add('name', 'nom', textError(order.name, `${title} · Nom`, NAME_MAX, true))
    }
    if (order.form === null) {
      add('form', 'forme', `${title} : choisissez la forme (collyre, pommade, comprimé ou autre).`)
    } else if (order.form === 'OTHER') {
      if (cleanText(order.formOther) === null) {
        add('form_other', 'forme-precision', `${title} : précisez la forme « Autre ».`)
      } else {
        add(
          'form_other',
          'forme-precision',
          textError(order.formOther, `${title} · Forme`, FORM_OTHER_MAX, true),
        )
      }
    }
    add(
      'strength',
      'dosage',
      textError(order.strength, `${title} · Dosage ou concentration`, STRENGTH_MAX, true),
    )
    add('dosage', 'posologie', textError(order.dosage, `${title} · Posologie`))
    add(
      'frequency',
      'frequence',
      textError(order.frequency, `${title} · Fréquence`, FREQUENCY_MAX, true),
    )
    add(
      'duration_text',
      'duree',
      textError(order.durationText, `${title} · Durée`, DURATION_TEXT_MAX, true),
    )
    add('instructions', 'instructions', textError(order.instructions, `${title} · Instructions`))

    const hasValue = cleanText(order.durationValue) !== null
    if (hasValue && durationNumber(order.durationValue) === null) {
      add(
        'duration_value',
        'duree-nombre',
        `${title} : la durée est un nombre entier strictement positif.`,
      )
    } else if (hasValue && order.durationUnit === null) {
      add(
        'duration_unit',
        'duree-unite',
        `${title} : choisissez l’unité de la durée (jour, semaine ou mois).`,
      )
    } else if (!hasValue && order.durationUnit !== null) {
      add(
        'duration_value',
        'duree-nombre',
        `${title} : indiquez le nombre de la durée, ou retirez l’unité.`,
      )
    }
  })
  return errors
}

export function medicationsUnparsable(draft: MedicationsDraft): boolean {
  return draft.orders.some(
    (order) =>
      cleanText(order.durationValue) !== null && durationNumber(order.durationValue) === null,
  )
}

/**
 * Lignes dont la posologie, la fréquence et les instructions sont toutes vides : indication NON BLOQUANTE (le
 * système ne génère aucune posologie manquante, c'est au professionnel de la saisir ou de l'assumer).
 */
export function missingPosology(draft: MedicationsDraft): number[] {
  return draft.orders
    .map((order, index) => ({ order, index }))
    .filter(
      ({ order }) =>
        cleanText(order.dosage) === null &&
        cleanText(order.frequency) === null &&
        cleanText(order.instructions) === null &&
        cleanText(order.durationText) === null,
    )
    .map(({ index }) => index)
}

/** Déplace une ligne vers le haut (-1) ou le bas (+1) ; hors bornes, la liste est rendue telle quelle. */
export function moveOrder(
  draft: MedicationsDraft,
  index: number,
  direction: -1 | 1,
): MedicationsDraft {
  const target = index + direction
  const orders = [...draft.orders]
  const moving = orders[index]
  const other = orders[target]
  if (moving === undefined || other === undefined) return draft
  orders[index] = other
  orders[target] = moving
  return { orders }
}

export const medicationsCodec: StepCodec<MedicationsDraft, MedicationsData> = {
  blank: () => blankMedications(),
  fromData: (data) => medicationsDraft(data),
  toData: medicationsData,
  errors: medicationsErrors,
  unparsable: medicationsUnparsable,
}
