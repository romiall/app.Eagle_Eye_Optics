<script setup lang="ts">
/**
 * Champ décimal exact (quantité, taux, mesure optique) : virgule ou point, lecture par les règles
 * pures de F08 à la sortie du champ ou à Entrée. Trop de décimales ou de chiffres : message
 * d'erreur, jamais de troncature ni d'arrondi. Vide → null (non saisi, non mesuré), jamais « 0 ».
 * La saisie refusée est conservée pour être corrigée. `commit()` est exposé pour qu'un formulaire
 * lise le champ avant son envoi.
 *
 * Clavier du téléphone : pavé décimal pour les valeurs positives ; clavier texte pour une mesure
 * optique signée, car le pavé décimal de l'iPhone n'a pas de signe « - ».
 */
import { ref, watch } from 'vue'

import { RuleViolation } from '@/domain/errors'

import { controlClasses } from './classes'
import FieldShell from './FieldShell.vue'
import { decimalFromCanonical, type DecimalKind, formatDecimalFr, parseDecimal } from './format'

const props = withDefaults(
  defineProps<{
    label: string
    /** Forme canonique (« 1.250 », « -0.75 ») ou null. */
    modelValue: string | null
    kind: DecimalKind
    /** Nom du champ dans les erreurs (ex. `sphere`). */
    field: string
    hint?: string | undefined
    error?: string | null
    required?: boolean
    disabled?: boolean
    hideLabel?: boolean
    hideLabelWide?: boolean
    controlId?: string | undefined
  }>(),
  {
    hint: undefined,
    error: null,
    required: false,
    disabled: false,
    hideLabel: false,
    hideLabelWide: false,
    controlId: undefined,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string | null]; invalid: [rule: string] }>()

const explicitSign = props.kind === 'diopter'
const inputmode = props.kind === 'diopter' ? 'text' : 'decimal'
const display = (canonical: string | null): string =>
  canonical === null
    ? ''
    : formatDecimalFr(decimalFromCanonical(props.kind, canonical), explicitSign)

const text = ref(display(props.modelValue))
const localError = ref<string | null>(null)

watch(
  () => props.modelValue,
  (value) => {
    if (localError.value === null) text.value = display(value)
  },
)

/** Lit la saisie ; rend false si elle est refusée (le message est alors affiché). */
function commit(): boolean {
  try {
    const parsed = parseDecimal(props.kind, text.value, props.field)
    if (parsed === null && props.required) {
      localError.value = 'Ce champ est obligatoire.'
      emit('invalid', 'required')
      return false
    }
    localError.value = null
    text.value = parsed === null ? '' : formatDecimalFr(parsed, explicitSign)
    const value = parsed === null ? null : parsed.canonical()
    if (value !== props.modelValue) emit('update:modelValue', value)
    return true
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    localError.value = error.message
    emit('invalid', error.rule)
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
    :hide-label="props.hideLabel"
    :hide-label-wide="props.hideLabelWide"
    :control-id="props.controlId"
  >
    <input
      :id="id"
      v-model="text"
      type="text"
      :inputmode="inputmode"
      autocomplete="off"
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
