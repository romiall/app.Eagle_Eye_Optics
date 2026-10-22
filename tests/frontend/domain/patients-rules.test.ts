// @vitest-environment node
// Règles pures de l'identité patient (P01, P02 ; fichier 02 « Patients et accueil » ; AT03, AT04) :
// formulaire, âge, charges des commandes CreatePatient et UpdatePatient validées contre le contrat
// F06, reprise après conflit de version et ressemblances. Données FICTIVES.
import { describe, expect, it } from 'vitest'

import { commandContract } from '@/app/services/commandIndex'
import { directBody } from '@/app/services/mutations'
import type { Patient, PatientPage, PatientSummary } from '@/domain/contracts/generated'
import {
  duplicateQueries,
  mergeSuggestions,
  MIN_SEARCH_LENGTH,
  searchTerm,
} from '@/domain/patients/duplicates'
import {
  blankPatientForm,
  formFieldFromServer,
  formFromPatient,
  identityOf,
  isBlankForm,
  type PatientFormState,
  validatePatientForm,
} from '@/domain/patients/form'
import {
  formatAge,
  patientAge,
  patientDisplayName,
  SEX_CODES,
  sexLabel,
} from '@/domain/patients/identity'
import {
  buildCreatePatientPayload,
  buildUpdatePatientPayload,
  changedFields,
} from '@/domain/patients/payload'
import { rebaseEdits } from '@/domain/patients/rebase'
import { CivilDate } from '@/domain/values/civilDate'

import { CONTRACTS_BASE_URI, contractValidator } from '../support/contracts'

const TODAY = CivilDate.fromString('2026-10-15')
const IDS = {
  patientId: '01J9ZXK4M2Q8R7S6T5V4W30001',
  recordNumberUsageId: '01J9ZXK4M2Q8R7S6T5V4W30002',
}
const KEY = '01J9ZXK4M2Q8R7S6T5V4W3K001'

function form(overrides: Partial<PatientFormState> = {}): PatientFormState {
  return {
    ...blankPatientForm(),
    lastName: 'PATIENT FICTIF',
    firstNames: 'Élodie Marie',
    sex: 'F',
    birthDate: '1990-05-17',
    phonePrimary: '+237 600 00 00 01',
    address: 'Quartier fictif, Douala',
    ...overrides,
  }
}

function validValues(overrides: Partial<PatientFormState> = {}) {
  const result = validatePatientForm(form(overrides), TODAY)
  if (!result.ok) throw new Error(`Saisie refusée : ${JSON.stringify(result.errors)}`)
  return result.values
}

function errorsOf(overrides: Partial<PatientFormState>) {
  const result = validatePatientForm(form(overrides), TODAY)
  if (result.ok) throw new Error('Refus attendu.')
  return result.errors
}

function patient(overrides: Partial<Patient> = {}): Patient {
  return {
    id: IDS.patientId,
    record_number: 'EE-2026-0001',
    last_name: 'PATIENT FICTIF',
    first_names: 'Élodie Marie',
    sex: 'F',
    birth_date: '1990-05-17',
    phone_primary: '+237 600 00 00 01',
    archived: false,
    merged_into_patient_id: null,
    version: 3,
    birth_date_missing_reason: null,
    phone_secondary: null,
    phone_missing_reason: null,
    address: 'Quartier fictif, Douala',
    profession: null,
    guardian_contact: null,
    legacy_record: false,
    first_known_visit_on: null,
    first_visit_certified: false,
    legacy_record_number: null,
    created_at: '2026-10-14T09:30:00.000Z',
    updated_at: '2026-10-14T09:30:00.000Z',
    ...overrides,
  }
}

function summary(id: string, overrides: Partial<PatientSummary> = {}): PatientSummary {
  return {
    id,
    record_number: 'EE-2026-0002',
    last_name: 'AUTRE FICTIF',
    first_names: null,
    sex: 'UNKNOWN',
    birth_date: null,
    phone_primary: null,
    archived: false,
    merged_into_patient_id: null,
    version: 1,
    ...overrides,
  }
}

function page(items: PatientSummary[], total = items.length): PatientPage {
  return {
    data: items,
    meta: { current_page: 1, last_page: 1, per_page: 25, total },
  }
}

describe('sexe et nom affichés', () => {
  it('libellés français des trois valeurs du contrat', () => {
    expect(SEX_CODES).toEqual(['F', 'M', 'UNKNOWN'])
    expect(sexLabel('F')).toBe('Féminin')
    expect(sexLabel('M')).toBe('Masculin')
    expect(sexLabel('UNKNOWN')).toBe('Non renseigné')
  })

  it('un prénom peut être absent', () => {
    expect(patientDisplayName('NGONO', 'Marie')).toBe('NGONO Marie')
    expect(patientDisplayName('NGONO', null)).toBe('NGONO')
    expect(patientDisplayName('NGONO', '  ')).toBe('NGONO')
  })
})

describe('âge calculé à la date civile du cabinet', () => {
  const age = (birth: string | null) => formatAge(patientAge(birth, TODAY))

  it('« âge non renseigné » sans naissance : jamais une date inventée', () => {
    expect(patientAge(null, TODAY)).toEqual({ kind: 'unknown' })
    expect(age(null)).toBe('âge non renseigné')
  })

  it('années révolues, la veille et le jour de l’anniversaire', () => {
    expect(age('2026-10-16')).toBe('date de naissance à vérifier')
    expect(age('1990-10-15')).toBe('36 ans')
    expect(age('1990-10-16')).toBe('35 ans')
    expect(age('2025-10-15')).toBe('1 an')
    expect(age('2025-10-16')).toBe('11 mois')
  })

  it('un nourrisson a un âge en mois, jamais « 0 an »', () => {
    expect(age('2026-10-15')).toBe('moins d’un mois')
    expect(age('2026-09-16')).toBe('moins d’un mois')
    expect(age('2026-09-15')).toBe('1 mois')
    expect(age('2026-05-31')).toBe('4 mois')
  })

  it('un 29 février compte le 1er mars d’une année non bissextile', () => {
    expect(age('2024-02-29')).toBe('2 ans')
    expect(formatAge(patientAge('2025-02-28', CivilDate.fromString('2026-02-28')))).toBe('1 an')
    expect(formatAge(patientAge('2024-02-29', CivilDate.fromString('2026-02-28')))).toBe('1 an')
    expect(formatAge(patientAge('2024-02-29', CivilDate.fromString('2026-03-01')))).toBe('2 ans')
  })

  it('une date illisible ou future n’affirme aucun âge', () => {
    expect(patientAge('pas une date', TODAY)).toEqual({ kind: 'invalid' })
    expect(patientAge('2026-13-40', TODAY)).toEqual({ kind: 'invalid' })
    expect(patientAge('2027-01-01', TODAY)).toEqual({ kind: 'invalid' })
  })
})

describe('formulaire d’identité : règles de saisie', () => {
  it('accepte une identité complète et normalise téléphone et blancs', () => {
    expect(
      validValues({
        lastName: '  PATIENT FICTIF  ',
        phonePrimary: '+237 600 00 00 01',
        phoneSecondary: '(237) 600-00-00-02',
        address: '  Quartier fictif\r\nDouala  ',
        profession: ' Enseignante ',
      }),
    ).toEqual({
      last_name: 'PATIENT FICTIF',
      first_names: 'Élodie Marie',
      sex: 'F',
      birth_date: '1990-05-17',
      birth_date_missing_reason: null,
      phone_primary: '+237600000001',
      phone_secondary: '237600000002',
      phone_missing_reason: null,
      address: 'Quartier fictif\nDouala',
      profession: 'Enseignante',
    })
  })

  it('champs vides → null, jamais une chaîne vide', () => {
    const values = validValues({
      firstNames: '   ',
      phoneSecondary: '',
      address: '\n  \n',
      profession: '',
    })

    expect(values.first_names).toBeNull()
    expect(values.phone_secondary).toBeNull()
    expect(values.address).toBeNull()
    expect(values.profession).toBeNull()
    for (const value of Object.values(values)) expect(value).not.toBe('')
  })

  it('le nom est obligatoire après suppression des blancs', () => {
    expect(errorsOf({ lastName: '' }).lastName).toBe('Le nom est obligatoire.')
    expect(errorsOf({ lastName: '   ' }).lastName).toBe('Le nom est obligatoire.')
    expect(errorsOf({ lastName: 'N'.repeat(121) }).lastName).toBe(
      'Le nom ne peut pas dépasser 120 caractères.',
    )
    expect(validValues({ lastName: 'N'.repeat(120) }).last_name).toHaveLength(120)
  })

  it('refuse une naissance future au jour civil du cabinet, accepte aujourd’hui', () => {
    expect(errorsOf({ birthDate: '2026-10-16' }).birthDate).toBe(
      'La date de naissance ne peut pas être dans le futur.',
    )
    expect(validValues({ birthDate: '2026-10-15' }).birth_date).toBe('2026-10-15')
    // Le jour civil du cabinet (Africa/Douala, UTC+1) peut précéder celui du serveur UTC.
    const douala = CivilDate.fromEpochMilliseconds(
      Date.parse('2026-10-14T23:30:00.000Z'),
      'Africa/Douala',
    )
    expect(douala.toString()).toBe('2026-10-15')
    expect(validatePatientForm(form({ birthDate: '2026-10-15' }), douala).ok).toBe(true)
    const utcDay = CivilDate.fromString('2026-10-14')
    const result = validatePatientForm(form({ birthDate: '2026-10-15' }), utcDay)
    expect(result.ok).toBe(false)
  })

  it('une date de naissance qui n’existe pas est refusée', () => {
    expect(errorsOf({ birthDate: '2026-02-30' }).birthDate).toBe(
      'Cette date n’existe pas dans le calendrier.',
    )
  })

  it('naissance absente : la raison est obligatoire, aucune date n’est inventée', () => {
    expect(errorsOf({ birthDate: null }).birthDateMissingReason).toBe(
      'Date de naissance absente : indiquez la raison.',
    )
    expect(errorsOf({ birthDate: null, birthDateMissingReason: '  ' }).birthDateMissingReason).toBe(
      'Date de naissance absente : indiquez la raison.',
    )
    const values = validValues({
      birthDate: null,
      birthDateMissingReason: ' Patient sans pièce, âge inconnu ',
    })
    expect(values.birth_date).toBeNull()
    expect(values.birth_date_missing_reason).toBe('Patient sans pièce, âge inconnu')
  })

  it('une naissance renseignée efface la raison d’absence restée dans le champ', () => {
    const values = validValues({ birthDateMissingReason: 'ancienne raison' })
    expect(values.birth_date).toBe('1990-05-17')
    expect(values.birth_date_missing_reason).toBeNull()
  })

  it('téléphone principal absent : la raison est obligatoire', () => {
    expect(errorsOf({ phonePrimary: '' }).phoneMissingReason).toBe(
      'Téléphone absent : indiquez la raison.',
    )
    const values = validValues({ phonePrimary: '', phoneMissingReason: 'Pas de téléphone' })
    expect(values.phone_primary).toBeNull()
    expect(values.phone_missing_reason).toBe('Pas de téléphone')
    // Un téléphone secondaire seul n’y change rien.
    expect(errorsOf({ phonePrimary: '', phoneSecondary: '600000002' }).phoneMissingReason).toBe(
      'Téléphone absent : indiquez la raison.',
    )
  })

  it('un téléphone principal renseigné efface la raison d’absence restée dans le champ', () => {
    expect(validValues({ phoneMissingReason: 'ancienne raison' }).phone_missing_reason).toBeNull()
  })

  it('refuse un téléphone illisible, sans demander de raison en plus', () => {
    const errors = errorsOf({ phonePrimary: 'appeler Marie' })
    expect(errors.phonePrimary).toContain('Numéro de téléphone invalide')
    expect(errors.phoneMissingReason).toBeUndefined()
    expect(errorsOf({ phoneSecondary: '12' }).phoneSecondary).toBe(
      'Un numéro de téléphone compte de 3 à 31 chiffres.',
    )
  })

  it('un téléphone partagé n’est pas une erreur : la règle ne regarde que la saisie', () => {
    // Deux patients différents, même téléphone : la règle ne connaît pas les autres fiches.
    expect(validValues({ lastName: 'AUTRE FICTIF' }).phone_primary).toBe('+237600000001')
  })

  it('refuse un sexe hors F, M, UNKNOWN et des textes trop longs', () => {
    expect(errorsOf({ sex: 'X' as never }).sex).toBe(
      'Choisissez Féminin, Masculin ou Non renseigné.',
    )
    expect(errorsOf({ firstNames: 'P'.repeat(181) }).firstNames).toContain('180 caractères')
    expect(errorsOf({ address: 'A'.repeat(4001) }).address).toContain('4000 caractères')
    expect(errorsOf({ profession: 'P'.repeat(181) }).profession).toContain('180 caractères')
    expect(
      errorsOf({ birthDate: null, birthDateMissingReason: 'R'.repeat(256) }).birthDateMissingReason,
    ).toBe('La raison ne peut pas dépasser 255 caractères.')
  })

  it('signale toutes les erreurs à la fois, chacune sur son champ', () => {
    const errors = errorsOf({ lastName: '', birthDate: '2030-01-01', phonePrimary: '' })
    expect(Object.keys(errors).sort()).toEqual(['birthDate', 'lastName', 'phoneMissingReason'])
  })

  it('un formulaire vierge n’est pas une saisie non enregistrée', () => {
    expect(isBlankForm(blankPatientForm())).toBe(true)
    expect(isBlankForm({ ...blankPatientForm(), address: ' \n ' })).toBe(true)
    expect(isBlankForm({ ...blankPatientForm(), sex: 'F' })).toBe(false)
    expect(isBlankForm({ ...blankPatientForm(), birthDate: '1990-05-17' })).toBe(false)
    expect(isBlankForm({ ...blankPatientForm(), lastName: 'X' })).toBe(false)
  })

  it('relie les erreurs du serveur aux champs du formulaire', () => {
    expect(formFieldFromServer('last_name')).toBe('lastName')
    expect(formFieldFromServer('changes.phone_primary')).toBe('phonePrimary')
    expect(formFieldFromServer('birth_date_missing_reason')).toBe('birthDateMissingReason')
    expect(formFieldFromServer('record_number_usage_id')).toBeNull()
    expect(formFieldFromServer('changes')).toBeNull()
  })

  it('préremplit le formulaire depuis la fiche, absences → champs vides', () => {
    expect(formFromPatient(patient({ first_names: null, address: null }))).toMatchObject({
      lastName: 'PATIENT FICTIF',
      firstNames: '',
      address: '',
      birthDate: '1990-05-17',
      sex: 'F',
    })
  })
})

describe('charges CreatePatient et UpdatePatient : conformes au contrat F06', () => {
  const ajv = contractValidator()

  function directSchema(name: string) {
    const validate = ajv.getSchema(
      `${CONTRACTS_BASE_URI}commands/${name}.v1.json#/$defs/DirectRequest`,
    )
    if (validate === undefined) throw new Error(`DirectRequest de ${name} absent`)
    return validate
  }

  it('la création produit un corps de route directe accepté, numéro à null', () => {
    const payload = buildCreatePatientPayload(validValues(), IDS)
    const body = directBody(commandContract('CreatePatient'), {
      name: 'CreatePatient',
      payload,
      idempotencyKey: KEY,
    })
    const validate = directSchema('CreatePatient')

    expect(validate(body), JSON.stringify(validate.errors)).toBe(true)
    expect(body).toMatchObject({
      expected_version: null,
      record_number: null,
      patient_id: IDS.patientId,
      record_number_usage_id: IDS.recordNumberUsageId,
      legacy_record: false,
      first_known_visit_on: null,
      first_visit_certified: false,
      legacy_record_number: null,
      guardian_contact: null,
    })
  })

  it('la création sans naissance ni téléphone, avec raisons, est acceptée par le contrat', () => {
    const payload = buildCreatePatientPayload(
      validValues({
        birthDate: null,
        birthDateMissingReason: 'Âge inconnu',
        phonePrimary: '',
        phoneMissingReason: 'Pas de téléphone',
      }),
      IDS,
    )
    const body = directBody(commandContract('CreatePatient'), {
      name: 'CreatePatient',
      payload,
      idempotencyKey: KEY,
    })
    const validate = directSchema('CreatePatient')

    expect(validate(body), JSON.stringify(validate.errors)).toBe(true)
    expect(body['birth_date']).toBeNull()
    expect(body['phone_primary']).toBeNull()
  })

  it('aucune chaîne vide dans la charge de création', () => {
    const payload = buildCreatePatientPayload(
      validValues({ firstNames: '', phoneSecondary: '', address: '', profession: '' }),
      IDS,
    )
    for (const [key, value] of Object.entries(payload)) {
      expect(value, key).not.toBe('')
    }
  })

  it('la modification n’envoie que les champs modifiés, avec la version, et est acceptée', () => {
    const original = patient()
    const payload = buildUpdatePatientPayload(
      original,
      validValues({ profession: 'Enseignante', address: 'Nouveau quartier fictif' }),
    )

    expect(payload).toEqual({
      patient_id: IDS.patientId,
      changes: { address: 'Nouveau quartier fictif', profession: 'Enseignante' },
    })
    const body = directBody(commandContract('UpdatePatient'), {
      name: 'UpdatePatient',
      path: { id: IDS.patientId },
      payload: payload ?? {},
      expectedVersion: original.version,
      idempotencyKey: KEY,
    })
    const validate = directSchema('UpdatePatient')
    expect(validate(body), JSON.stringify(validate.errors)).toBe(true)
    expect(body['expected_version']).toBe(3)
  })

  it('rien n’a changé : aucune charge (la commande exige au moins un champ)', () => {
    expect(buildUpdatePatientPayload(patient(), validValues())).toBeNull()
  })

  it('un téléphone enregistré avec des espaces et ressaisi à l’identique n’est pas une modification', () => {
    const original = patient({ phone_primary: '+237 600 00 00 01' })
    expect(changedFields(original, validValues({ phonePrimary: '+237 600 00 00 01' }))).toEqual({})
    expect(changedFields(original, validValues({ phonePrimary: '+237600000001' }))).toEqual({})
    expect(changedFields(original, validValues({ phonePrimary: '+237 600 00 00 09' }))).toEqual({
      phone_primary: '+237600000009',
    })
  })

  it('un vide et un null sont la même absence ; vider un champ le met à null', () => {
    const original = patient({ profession: '', phone_secondary: null, address: 'Quartier fictif' })
    const changes = changedFields(original, validValues({ profession: '', address: '' }))
    expect(changes).toEqual({ address: null })
  })

  it('renseigner une naissance inconnue efface la raison enregistrée dans la même charge', () => {
    const original = patient({ birth_date: null, birth_date_missing_reason: 'Âge inconnu' })
    expect(changedFields(original, validValues({ birthDate: '1985-03-02' }))).toEqual({
      birth_date: '1985-03-02',
      birth_date_missing_reason: null,
    })
  })

  it('identityOf tolère un téléphone hérité illisible sans le perdre', () => {
    expect(identityOf(patient({ phone_primary: 'poste 12' })).phone_primary).toBe('poste 12')
  })
})

describe('reprise de la saisie après un conflit de version (AT42)', () => {
  const base = patient()

  it('les champs non touchés reprennent le serveur, les champs modifiés gardent la saisie', () => {
    const latest = patient({ version: 4, profession: 'Infirmière', address: 'Autre quartier' })
    const mine = { ...formFromPatient(base), phonePrimary: '+237 677 00 00 00' }

    const result = rebaseEdits(base, latest, mine)

    expect(result.form.profession).toBe('Infirmière')
    expect(result.form.address).toBe('Autre quartier')
    expect(result.form.phonePrimary).toBe('+237 677 00 00 00')
    expect(result.conflicts).toEqual([])
  })

  it('signale un champ modifié des deux côtés avec des valeurs différentes, et garde la saisie', () => {
    const latest = patient({ version: 4, profession: 'Infirmière' })
    const mine = { ...formFromPatient(base), profession: 'Enseignante', address: 'Mon quartier' }

    const result = rebaseEdits(base, latest, mine)

    expect(result.conflicts).toEqual(['profession'])
    expect(result.form.profession).toBe('Enseignante')
    expect(result.form.address).toBe('Mon quartier')
  })

  it('deux modifications identiques ne sont pas un conflit', () => {
    const latest = patient({ version: 4, profession: 'Enseignante' })
    const mine = { ...formFromPatient(base), profession: ' Enseignante ' }

    expect(rebaseEdits(base, latest, mine).conflicts).toEqual([])
  })

  it('sans saisie propre, la fiche affichée est celle du serveur', () => {
    const latest = patient({ version: 4, last_name: 'NOUVEAU NOM FICTIF' })
    const result = rebaseEdits(base, latest, formFromPatient(base))

    expect(result.form).toEqual(formFromPatient(latest))
    expect(result.conflicts).toEqual([])
  })

  it('le formulaire repris donne une charge calculée contre la version actuelle', () => {
    const latest = patient({ version: 4, profession: 'Infirmière' })
    const mine = { ...formFromPatient(base), address: 'Mon quartier' }
    const result = rebaseEdits(base, latest, mine)
    const validated = validatePatientForm(result.form, TODAY)
    if (!validated.ok) throw new Error('Saisie refusée')

    // Seule la saisie de la personne part ; la profession du serveur n’est pas écrasée par l’ancienne.
    expect(changedFields(latest, validated.values)).toEqual({ address: 'Mon quartier' })
  })
})

describe('recherche et ressemblances', () => {
  it('une recherche demande 2 caractères au moins, blancs de bord retirés', () => {
    expect(MIN_SEARCH_LENGTH).toBe(2)
    expect(searchTerm('')).toEqual({ ok: false, length: 0 })
    expect(searchTerm('  a  ')).toEqual({ ok: false, length: 1 })
    expect(searchTerm('  Éb  ')).toEqual({ ok: true, text: 'Éb' })
    expect(searchTerm('NGONO   \t Marie')).toEqual({ ok: true, text: 'NGONO Marie' })
    // Deux points de code, même si un seul s’affiche à l’écran en UTF-16.
    expect(searchTerm('𠀀𠀁')).toEqual({ ok: true, text: '𠀀𠀁' })
  })

  it('cherche par nom + premier prénom et par chiffres du téléphone principal', () => {
    expect(
      duplicateQueries({
        last_name: 'PATIENT FICTIF',
        first_names: 'Élodie Marie',
        phone_primary: '+237600000001',
      }),
    ).toEqual({ name: 'PATIENT FICTIF Élodie', phone: '237600000001' })
    expect(
      duplicateQueries({ last_name: 'PATIENT FICTIF', first_names: null, phone_primary: null }),
    ).toEqual({ name: 'PATIENT FICTIF', phone: null })
  })

  it('un nom d’un seul caractère sans prénom n’est pas cherché (sous le seuil)', () => {
    expect(duplicateQueries({ last_name: 'X', first_names: null, phone_primary: null })).toEqual({
      name: null,
      phone: null,
    })
    expect(duplicateQueries({ last_name: 'X', first_names: 'Ana', phone_primary: null }).name).toBe(
      'X Ana',
    )
  })

  const A = '01J9ZXK4M2Q8R7S6T5V4W30011'
  const B = '01J9ZXK4M2Q8R7S6T5V4W30012'
  const C = '01J9ZXK4M2Q8R7S6T5V4W30013'

  it('fusionne par identifiant : les deux critères d’abord, puis nom, puis téléphone seul', () => {
    const report = mergeSuggestions({
      byName: page([summary(B), summary(A)]),
      byPhone: page([summary(C), summary(A)]),
      birthDate: null,
    })

    expect(
      report.suggestions.map((suggestion) => [suggestion.patient.id, suggestion.reasons]),
    ).toEqual([
      [A, ['name', 'phone']],
      [B, ['name']],
      [C, ['phone']],
    ])
    expect(report.more).toBe(false)
  })

  it('signale la même date de naissance et les fiches proches non listées', () => {
    const report = mergeSuggestions({
      byName: page(
        [summary(A, { birth_date: '1990-05-17' }), summary(B, { birth_date: '1991-01-01' })],
        40,
      ),
      byPhone: null,
      birthDate: '1990-05-17',
    })

    expect(report.suggestions.map((suggestion) => suggestion.sameBirthDate)).toEqual([true, false])
    expect(report.more).toBe(true)
  })

  it('une naissance inconnue n’est jamais « la même naissance »', () => {
    const report = mergeSuggestions({
      byName: page([summary(A, { birth_date: null })]),
      byPhone: null,
      birthDate: null,
    })
    expect(report.suggestions[0]?.sameBirthDate).toBe(false)
  })

  it('aucun résultat : aucune suggestion', () => {
    expect(mergeSuggestions({ byName: page([]), byPhone: null, birthDate: null })).toEqual({
      suggestions: [],
      more: false,
    })
  })
})
