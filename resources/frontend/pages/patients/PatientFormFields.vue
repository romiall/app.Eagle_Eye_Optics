<script setup lang="ts">
/**
 * Champs d'identité d'un patient, communs à la création et à la modification (fichier 02, EE001) :
 * nom (obligatoire), prénoms, sexe, date de naissance avec sa raison d'absence, téléphones avec la
 * raison d'absence du principal, adresse et profession. Le formulaire est tenu par l'écran : ce
 * composant affiche les erreurs qu'on lui donne, sous chaque champ, et expose `commit()` pour lire
 * la date de naissance avant l'envoi. Aucune règle n'est jouée ici (domain/patients/form).
 */
import { computed, ref } from 'vue'

import { clinicToday } from '@/app/clinicTime'
import DateField from '@/components/form/DateField.vue'
import SelectField from '@/components/form/SelectField.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import TextField from '@/components/form/TextField.vue'
import type { PatientFormErrors, PatientFormField, PatientFormState } from '@/domain/patients/form'
import { formatAge, patientAge, SEX_LABELS } from '@/domain/patients/identity'
import { trimBlanks } from '@/domain/values/text'

import { FIELD_IDS } from './formFields'

const model = defineModel<PatientFormState>({ required: true })
const props = withDefaults(defineProps<{ errors: PatientFormErrors; disabled?: boolean }>(), {
  disabled: false,
})

const dateField = ref<{ commit: () => boolean } | null>(null)

function update<K extends PatientFormField>(field: K, value: PatientFormState[K]): void {
  model.value = { ...model.value, [field]: value }
}

/** « Non renseigné » est le choix vide de la liste : il vaut UNKNOWN. */
const SEX_OPTIONS = [
  { value: 'F', label: SEX_LABELS.F },
  { value: 'M', label: SEX_LABELS.M },
]
const sexChoice = computed(() => (model.value.sex === 'UNKNOWN' ? null : model.value.sex))

function chooseSex(value: string | null): void {
  update('sex', value === 'F' || value === 'M' ? value : 'UNKNOWN')
}

const birthHint = computed(() => {
  const base = 'Format JJ/MM/AAAA ou 8 chiffres (15102026).'
  const age = patientAge(model.value.birthDate, clinicToday())
  return age.kind === 'years' || age.kind === 'months'
    ? `${base} Âge calculé : ${formatAge(age)}.`
    : base
})

const birthDateMissing = computed(() => model.value.birthDate === null)
const phoneMissing = computed(() => trimBlanks(model.value.phonePrimary) === '')

defineExpose({
  /** Lit la date de naissance saisie ; faux si elle est refusée (le champ en dit la raison). */
  commit: (): boolean => dateField.value?.commit() ?? true,
})
</script>

<template>
  <div class="space-y-8">
    <fieldset class="m-0 min-w-0 space-y-4 border-0 p-0">
      <legend class="mb-2 p-0 text-lg font-semibold">Identité</legend>
      <div class="grid gap-4 sm:grid-cols-2">
        <TextField
          :model-value="model.lastName"
          label="Nom"
          :control-id="FIELD_IDS.lastName"
          :error="props.errors.lastName ?? null"
          :disabled="props.disabled"
          required
          @update:model-value="update('lastName', $event)"
        />
        <TextField
          :model-value="model.firstNames"
          label="Prénom(s)"
          :control-id="FIELD_IDS.firstNames"
          hint="Facultatif : un prénom peut être inconnu."
          :error="props.errors.firstNames ?? null"
          :disabled="props.disabled"
          @update:model-value="update('firstNames', $event)"
        />
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <SelectField
          :model-value="sexChoice"
          label="Sexe"
          :control-id="FIELD_IDS.sex"
          :options="SEX_OPTIONS"
          :placeholder="SEX_LABELS.UNKNOWN"
          :error="props.errors.sex ?? null"
          :disabled="props.disabled"
          @update:model-value="chooseSex"
        />
        <DateField
          ref="dateField"
          :model-value="model.birthDate"
          label="Date de naissance"
          field="birth_date"
          :control-id="FIELD_IDS.birthDate"
          :hint="birthHint"
          :error="props.errors.birthDate ?? null"
          :disabled="props.disabled"
          @update:model-value="update('birthDate', $event)"
        />
      </div>
      <TextField
        v-if="birthDateMissing"
        :model-value="model.birthDateMissingReason"
        label="Raison de l’absence de date de naissance"
        :control-id="FIELD_IDS.birthDateMissingReason"
        hint="Obligatoire tant que la date est inconnue. Aucune date n’est inventée."
        :error="props.errors.birthDateMissingReason ?? null"
        :disabled="props.disabled"
        @update:model-value="update('birthDateMissingReason', $event)"
      />
    </fieldset>

    <fieldset class="m-0 min-w-0 space-y-4 border-0 p-0">
      <legend class="mb-2 p-0 text-lg font-semibold">Contact</legend>
      <div class="grid gap-4 sm:grid-cols-2">
        <TextField
          :model-value="model.phonePrimary"
          label="Téléphone principal"
          :control-id="FIELD_IDS.phonePrimary"
          type="tel"
          inputmode="tel"
          hint="Un même téléphone peut servir à plusieurs patients."
          :error="props.errors.phonePrimary ?? null"
          :disabled="props.disabled"
          @update:model-value="update('phonePrimary', $event)"
        />
        <TextField
          :model-value="model.phoneSecondary"
          label="Téléphone secondaire"
          :control-id="FIELD_IDS.phoneSecondary"
          type="tel"
          inputmode="tel"
          :error="props.errors.phoneSecondary ?? null"
          :disabled="props.disabled"
          @update:model-value="update('phoneSecondary', $event)"
        />
      </div>
      <TextField
        v-if="phoneMissing"
        :model-value="model.phoneMissingReason"
        label="Raison de l’absence de téléphone"
        :control-id="FIELD_IDS.phoneMissingReason"
        hint="Obligatoire tant que le téléphone principal est vide."
        :error="props.errors.phoneMissingReason ?? null"
        :disabled="props.disabled"
        @update:model-value="update('phoneMissingReason', $event)"
      />
    </fieldset>

    <fieldset class="m-0 min-w-0 space-y-4 border-0 p-0">
      <legend class="mb-2 p-0 text-lg font-semibold">Autres informations</legend>
      <TextAreaField
        :model-value="model.address"
        label="Adresse ou quartier"
        :control-id="FIELD_IDS.address"
        :maxlength="4000"
        :error="props.errors.address ?? null"
        :disabled="props.disabled"
        @update:model-value="update('address', $event)"
      />
      <TextField
        :model-value="model.profession"
        label="Profession"
        :control-id="FIELD_IDS.profession"
        :error="props.errors.profession ?? null"
        :disabled="props.disabled"
        @update:model-value="update('profession', $event)"
      />
    </fieldset>
  </div>
</template>
