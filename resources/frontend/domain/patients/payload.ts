import type { CreatePatientPayload, Patient, UpdatePatientPayload } from '../contracts/generated'
import { identityOf, type PatientIdentity } from './form'

/**
 * Charges des commandes `CreatePatient` et `UpdatePatient` (contrat F06). Champs vides → null, jamais
 * une chaîne vide. Aucune règle n'est rejouée ici : `validatePatientForm` a déjà produit l'identité
 * normalisée.
 */

/**
 * Charge de création sans le numéro de dossier : il n'est jamais saisi. Le serveur l'attribue sous
 * verrou (route directe : `record_number` vaut null dans le corps) ; un poste préparé le prendra de
 * sa plage dans son journal.
 */
export type CreatePatientRequestPayload = Omit<CreatePatientPayload, 'record_number'>

export interface CreatePatientIds {
  /** Identifiant de la fiche, connu avant tout envoi (`effectId('patient_id')`). */
  patientId: string
  /** Identifiant de l'usage du numéro (`effectId('record_number_usage_id')`). */
  recordNumberUsageId: string
}

export function buildCreatePatientPayload(
  values: PatientIdentity,
  ids: CreatePatientIds,
): CreatePatientRequestPayload {
  return {
    patient_id: ids.patientId,
    record_number_usage_id: ids.recordNumberUsageId,
    last_name: values.last_name,
    first_names: values.first_names,
    sex: values.sex,
    birth_date: values.birth_date,
    birth_date_missing_reason: values.birth_date_missing_reason,
    phone_primary: values.phone_primary,
    phone_secondary: values.phone_secondary,
    phone_missing_reason: values.phone_missing_reason,
    address: values.address,
    profession: values.profession,
    guardian_contact: null,
    legacy_record: false,
    first_known_visit_on: null,
    first_visit_certified: false,
    legacy_record_number: null,
  }
}

export type PatientChanges = UpdatePatientPayload['changes']

/** Charge `UpdatePatient` (alias d'objet : transmissible au répartiteur de mutations). */
export type UpdatePatientRequestPayload = { patient_id: string; changes: PatientChanges }

/**
 * Champs réellement modifiés par rapport à la fiche lue. Les deux côtés passent par la même
 * normalisation : un téléphone enregistré avec des espaces et ressaisi sans, ou un champ vidé qui
 * était déjà vide, n'est pas une modification. Vide : rien à enregistrer.
 */
export function changedFields(original: Patient, values: PatientIdentity): PatientChanges {
  const before = identityOf(original)
  const changes: PatientChanges = {}
  if (values.last_name !== before.last_name) changes.last_name = values.last_name
  if (values.first_names !== before.first_names) changes.first_names = values.first_names
  if (values.sex !== before.sex) changes.sex = values.sex
  if (values.birth_date !== before.birth_date) changes.birth_date = values.birth_date
  if (values.birth_date_missing_reason !== before.birth_date_missing_reason) {
    changes.birth_date_missing_reason = values.birth_date_missing_reason
  }
  if (values.phone_primary !== before.phone_primary) changes.phone_primary = values.phone_primary
  if (values.phone_secondary !== before.phone_secondary) {
    changes.phone_secondary = values.phone_secondary
  }
  if (values.phone_missing_reason !== before.phone_missing_reason) {
    changes.phone_missing_reason = values.phone_missing_reason
  }
  if (values.address !== before.address) changes.address = values.address
  if (values.profession !== before.profession) changes.profession = values.profession
  return changes
}

/** Charge `UpdatePatient` ; null quand aucun champ n'a changé (la commande exige au moins un champ). */
export function buildUpdatePatientPayload(
  original: Patient,
  values: PatientIdentity,
): UpdatePatientRequestPayload | null {
  const changes = changedFields(original, values)
  return Object.keys(changes).length === 0 ? null : { patient_id: original.id, changes }
}
