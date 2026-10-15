<script setup lang="ts">
/**
 * Date civile : saisie « JJ/MM/AAAA », ISO, ou 8 chiffres « JJMMAAAA » (le pavé numérique de
 * l'iPhone n'a pas de « / » : « 15102026 » est mis en forme « 15/10/2026 » avant lecture), lue par
 * les règles F08 à la sortie du champ ou à Entrée ; une date inexistante (29/02/2026) est refusée.
 * Vide → null : une date inconnue n'est jamais remplacée par une date inventée (le motif d'absence
 * est un autre champ). `commit()` est exposé pour qu'un formulaire lise le champ avant son envoi.
 */
import { ref, watch } from 'vue'

import { RuleViolation } from '@/domain/errors'
import { CivilDate } from '@/domain/values/civilDate'

import { controlClasses } from './classes'
import FieldShell from './FieldShell.vue'
import { formatDateFr } from './format'

const props = withDefaults(
  defineProps<{
    label: string
    /** Date ISO « 2026-10-15 » ou null. */
    modelValue: string | null
    field?: string
    hint?: string
    error?: string | null
    required?: boolean
    disabled?: boolean
    controlId?: string | undefined
  }>(),
  {
    field: 'date',
    hint: 'Format JJ/MM/AAAA ou 8 chiffres (15102026)',
    error: null,
    required: false,
    disabled: false,
    controlId: undefined,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string | null]; invalid: [rule: string] }>()

const display = (iso: string | null): string => (iso === null ? '' : formatDateFr(iso))
const text = ref(display(props.modelValue))
const localError = ref<string | null>(null)

watch(
  () => props.modelValue,
  (value) => {
    if (localError.value === null) text.value = display(value)
  },
)

/** « 15102026 » → « 15/10/2026 » ; toute autre saisie est lue telle quelle par la règle F08. */
function withSlashes(value: string): string {
  const digits = value.trim()
  return /^[0-9]{8}$/.test(digits)
    ? `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`
    : value
}

/**
 * Saisie refusée : le parent ne garde pas l'ancienne valeur valide, qui partirait sinon à la place
 * de ce que l'écran affiche ; la saisie refusée reste affichée pour être corrigée.
 */
function clearParentValue(): void {
  if (props.modelValue !== null) emit('update:modelValue', null)
}

/** Lit la saisie ; rend false si elle est refusée (le message est alors affiché). */
function commit(): boolean {
  try {
    const date = CivilDate.parse(withSlashes(text.value), props.field)
    if (date === null && props.required) {
      localError.value = 'Ce champ est obligatoire.'
      emit('invalid', 'required')
      clearParentValue()
      return false
    }
    localError.value = null
    const iso = date === null ? null : date.toString()
    text.value = display(iso)
    if (iso !== props.modelValue) emit('update:modelValue', iso)
    return true
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    localError.value = error.message
    emit('invalid', error.rule)
    clearParentValue()
    return false
  }
}

defineExpose({ commit })
</script>

<template>
  <FieldShell
    v-slot="{ id, describedBy, invalid }"
    :label="props.label"
    :hint="props.hint"
    :error="props.error ?? localError"
    :required="props.required"
    :control-id="props.controlId"
  >
    <input
      :id="id"
      v-model="text"
      type="text"
      inputmode="numeric"
      autocomplete="off"
      placeholder="JJ/MM/AAAA"
      :required="props.required"
      :disabled="props.disabled"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-describedby="describedBy"
      :class="controlClasses(invalid, 'tabular-nums')"
      @blur="commit"
      @keydown.enter="commit"
    />
  </FieldShell>
</template>
