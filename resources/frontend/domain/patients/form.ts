import type { Patient, PatientRecordsSex } from '../contracts/generated'
import { RuleViolation } from '../errors'
import { CivilDate } from '../values/civilDate'
import { normalizePhone, trimBlanks } from '../values/text'
import { SEX_CODES } from './identity'

/**
 * Règles du formulaire d'identité patient (fichier 02, EE001 ; AT04) : nom obligatoire, naissance
 * non future au jour civil du cabinet ou raison d'absence (jamais une date inventée), téléphone
 * principal ou raison d'absence, sexe F/M/UNKNOWN. Champs vides → null, jamais une chaîne vide.
 * Les mêmes limites que le contrat F06 (`CreatePatient`, `UpdatePatient`). Règles pures : ni Vue,
 * ni réseau, ni stockage.
 */
export const FORM_FIELDS = [
  'lastName',
  'firstNames',
  'sex',
  'birthDate',
  'birthDateMissingReason',
  'phonePrimary',
  'phoneSecondary',
  'phoneMissingReason',
  'address',
  'profession',
] as const

export type PatientFormField = (typeof FORM_FIELDS)[number]

/** Saisie telle que le formulaire la tient : textes bruts, date au format ISO ou null. */
export interface PatientFormState {
  lastName: string
  firstNames: string
  sex: PatientRecordsSex
  birthDate: string | null
  birthDateMissingReason: string
  phonePrimary: string
  phoneSecondary: string
  phoneMissingReason: string
  address: string
  profession: string
}

/** Identité normalisée, dans la forme du contrat (clés de la charge `CreatePatient`). */
export interface PatientIdentity {
  last_name: string
  first_names: string | null
  sex: PatientRecordsSex
  birth_date: string | null
  birth_date_missing_reason: string | null
  phone_primary: string | null
  phone_secondary: string | null
  phone_missing_reason: string | null
  address: string | null
  profession: string | null
}

export type PatientFormErrors = Partial<Record<PatientFormField, string>>

export type PatientFormResult =
  { ok: true; values: PatientIdentity } | { ok: false; errors: PatientFormErrors }

export const FIELD_LABELS: Readonly<Record<PatientFormField, string>> = {
  lastName: 'Nom',
  firstNames: 'Prénom(s)',
  sex: 'Sexe',
  birthDate: 'Date de naissance',
  birthDateMissingReason: 'Raison de l’absence de date de naissance',
  phonePrimary: 'Téléphone principal',
  phoneSecondary: 'Téléphone secondaire',
  phoneMissingReason: 'Raison de l’absence de téléphone',
  address: 'Adresse ou quartier',
  profession: 'Profession',
}

/** Clé de la charge du contrat pour chaque champ du formulaire. */
export const SERVER_FIELD: Readonly<Record<PatientFormField, keyof PatientIdentity>> = {
  lastName: 'last_name',
  firstNames: 'first_names',
  sex: 'sex',
  birthDate: 'birth_date',
  birthDateMissingReason: 'birth_date_missing_reason',
  phonePrimary: 'phone_primary',
  phoneSecondary: 'phone_secondary',
  phoneMissingReason: 'phone_missing_reason',
  address: 'address',
  profession: 'profession',
}

const FROM_SERVER: Readonly<Record<string, PatientFormField>> = Object.fromEntries(
  FORM_FIELDS.map((field) => [SERVER_FIELD[field], field]),
)

/**
 * Champ du formulaire correspondant à une clé d'erreur du serveur (`last_name`, ou `changes.last_name`
 * pour une modification) ; null si la clé n'est pas un champ du formulaire.
 */
export function formFieldFromServer(key: string): PatientFormField | null {
  const name = key.startsWith('changes.') ? key.slice('changes.'.length) : key
  return FROM_SERVER[name] ?? null
}

export function blankPatientForm(): PatientFormState {
  return {
    lastName: '',
    firstNames: '',
    sex: 'UNKNOWN',
    birthDate: null,
    birthDateMissingReason: '',
    phonePrimary: '',
    phoneSecondary: '',
    phoneMissingReason: '',
    address: '',
    profession: '',
  }
}

/** Formulaire prérempli avec la fiche lue sur le serveur (valeurs absentes → champ vide). */
export function formFromPatient(patient: Patient): PatientFormState {
  return {
    lastName: patient.last_name,
    firstNames: patient.first_names ?? '',
    sex: patient.sex,
    birthDate: patient.birth_date,
    birthDateMissingReason: patient.birth_date_missing_reason ?? '',
    phonePrimary: patient.phone_primary ?? '',
    phoneSecondary: patient.phone_secondary ?? '',
    phoneMissingReason: patient.phone_missing_reason ?? '',
    address: patient.address ?? '',
    profession: patient.profession ?? '',
  }
}

/** Vrai tant que rien n'a été saisi (un formulaire vierge n'est pas une saisie non enregistrée). */
export function isBlankForm(form: PatientFormState): boolean {
  const blank = blankPatientForm()
  return FORM_FIELDS.every((field) => {
    const value = form[field]
    return typeof value === 'string' && field !== 'sex'
      ? trimBlanks(value) === ''
      : value === blank[field]
  })
}

/** Texte facultatif : blancs de bord retirés, fins de ligne en « \n », vide → null. */
function clean(value: string | null): string | null {
  if (value === null) return null
  const text = trimBlanks(value).replace(/\r\n?/g, '\n')
  return text === '' ? null : text
}

function bounded(value: string, max: number, field: string, tooLong: string): string | null {
  const text = clean(value)
  if (text !== null && [...text].length > max) {
    throw RuleViolation.invalid(field, 'text.too_long', tooLong, { max_length: max })
  }
  return text
}

/**
 * Identité d'une fiche du serveur dans la même forme normalisée que la saisie validée, pour détecter
 * ce qui a réellement changé : un téléphone enregistré « +237 600 00 00 01 » égale la saisie
 * « +237600000001 » (aucune modification fantôme), un vide et un null sont la même absence.
 */
export function identityOf(patient: Patient): PatientIdentity {
  const phone = (value: string | null): string | null => {
    try {
      return normalizePhone(value, 'phone')
    } catch (error) {
      if (!(error instanceof RuleViolation)) throw error
      return clean(value)
    }
  }
  return {
    last_name: clean(patient.last_name) ?? '',
    first_names: clean(patient.first_names),
    sex: patient.sex,
    birth_date: patient.birth_date,
    birth_date_missing_reason: clean(patient.birth_date_missing_reason),
    phone_primary: phone(patient.phone_primary),
    phone_secondary: phone(patient.phone_secondary),
    phone_missing_reason: clean(patient.phone_missing_reason),
    address: clean(patient.address),
    profession: clean(patient.profession),
  }
}

/**
 * Valide la saisie à la date civile `today` du cabinet. Chaque refus porte son message français sur
 * le champ à corriger. Une naissance renseignée efface la raison d'absence, un téléphone principal
 * renseigné efface la raison d'absence de téléphone : la charge n'envoie jamais une raison contredite.
 */
export function validatePatientForm(form: PatientFormState, today: CivilDate): PatientFormResult {
  const errors: PatientFormErrors = {}

  function read<T>(field: PatientFormField, action: () => T, fallback: T): T {
    try {
      return action()
    } catch (error) {
      if (!(error instanceof RuleViolation)) throw error
      errors[field] = error.message
      return fallback
    }
  }

  const lastName = read(
    'lastName',
    () => {
      const value = bounded(
        form.lastName,
        120,
        'last_name',
        'Le nom ne peut pas dépasser 120 caractères.',
      )
      if (value === null) {
        throw RuleViolation.invalid('last_name', 'last_name.required', 'Le nom est obligatoire.')
      }
      return value
    },
    '',
  )
  const firstNames = read(
    'firstNames',
    () =>
      bounded(
        form.firstNames,
        180,
        'first_names',
        'Les prénoms ne peuvent pas dépasser 180 caractères.',
      ),
    null,
  )
  const sex = read(
    'sex',
    () => {
      if (!SEX_CODES.includes(form.sex)) {
        throw RuleViolation.invalid(
          'sex',
          'sex.invalid',
          'Choisissez Féminin, Masculin ou Non renseigné.',
        )
      }
      return form.sex
    },
    'UNKNOWN',
  )

  const typedBirth = form.birthDate
  const birthDate =
    typedBirth === null
      ? null
      : read(
          'birthDate',
          () => {
            const date = CivilDate.fromString(typedBirth, 'birth_date')
            if (date.compare(today) > 0) {
              throw RuleViolation.invalid(
                'birth_date',
                'birth_date.future',
                'La date de naissance ne peut pas être dans le futur.',
              )
            }
            return date.toString()
          },
          null,
        )
  const birthReason = read(
    'birthDateMissingReason',
    () =>
      bounded(
        form.birthDateMissingReason,
        255,
        'birth_date_missing_reason',
        'La raison ne peut pas dépasser 255 caractères.',
      ),
    null,
  )
  if (typedBirth === null && birthReason === null && errors.birthDateMissingReason === undefined) {
    errors.birthDateMissingReason = 'Date de naissance absente : indiquez la raison.'
  }

  const phonePrimary = read(
    'phonePrimary',
    () => normalizePhone(form.phonePrimary, 'phone_primary'),
    null,
  )
  const phoneSecondary = read(
    'phoneSecondary',
    () => normalizePhone(form.phoneSecondary, 'phone_secondary'),
    null,
  )
  const phoneReason = read(
    'phoneMissingReason',
    () =>
      bounded(
        form.phoneMissingReason,
        255,
        'phone_missing_reason',
        'La raison ne peut pas dépasser 255 caractères.',
      ),
    null,
  )
  if (
    phonePrimary === null &&
    errors.phonePrimary === undefined &&
    phoneReason === null &&
    errors.phoneMissingReason === undefined
  ) {
    errors.phoneMissingReason = 'Téléphone absent : indiquez la raison.'
  }

  const address = read(
    'address',
    () => bounded(form.address, 4000, 'address', 'L’adresse ne peut pas dépasser 4000 caractères.'),
    null,
  )
  const profession = read(
    'profession',
    () =>
      bounded(
        form.profession,
        180,
        'profession',
        'La profession ne peut pas dépasser 180 caractères.',
      ),
    null,
  )

  if (Object.keys(errors).length > 0) return { ok: false, errors }
  return {
    ok: true,
    values: {
      last_name: lastName,
      first_names: firstNames,
      sex,
      birth_date: birthDate,
      birth_date_missing_reason: birthDate === null ? birthReason : null,
      phone_primary: phonePrimary,
      phone_secondary: phoneSecondary,
      phone_missing_reason: phonePrimary === null ? phoneReason : null,
      address,
      profession,
    },
  }
}
