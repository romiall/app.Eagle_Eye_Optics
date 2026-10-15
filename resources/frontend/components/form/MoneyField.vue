<script setup lang="ts">
/**
 * Montant en FCFA entiers (règle F08 : chiffres seuls ou groupes de trois, ni virgule ni point).
 * Lu à la sortie du champ ou à Entrée. Vide → null, jamais 0 ; une saisie refusée est conservée
 * pour correction. L'unité affichée à côté est aussi annoncée par les lecteurs d'écran.
 * `commit()` est exposé pour qu'un formulaire lise le champ avant son envoi.
 */
import { ref, watch } from 'vue'

import { RuleViolation } from '@/domain/errors'
import { parseMoney } from '@/domain/values/money'

import { controlClasses } from './classes'
import FieldShell from './FieldShell.vue'
import { formatAmountInput } from './format'

const props = withDefaults(
  defineProps<{
    label: string
    modelValue: number | null
    field?: string
    hint?: string | undefined
    error?: string | null
    required?: boolean
    disabled?: boolean
    controlId?: string | undefined
  }>(),
  {
    field: 'amount',
    hint: undefined,
    error: null,
    required: false,
    disabled: false,
    controlId: undefined,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: number | null]; invalid: [rule: string] }>()

const display = (amount: number | null): string =>
  amount === null ? '' : formatAmountInput(amount)
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
    const amount = parseMoney(text.value, props.field)
    if (amount === null && props.required) {
      localError.value = 'Ce champ est obligatoire.'
      emit('invalid', 'required')
      return false
    }
    localError.value = null
    text.value = display(amount)
    if (amount !== props.modelValue) emit('update:modelValue', amount)
    return true
  } catch (error) {
    if (!(error instanceof RuleViolation)) throw error
    localError.value = error.message
    emit('invalid', error.rule)
    return false
  }
}

function describedWithUnit(describedBy: string | undefined, id: string): string {
  return [describedBy, `${id}-unite`].filter((part) => part !== undefined).join(' ')
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
    <div class="flex items-center gap-2">
      <input
        :id="id"
        v-model="text"
        type="text"
        inputmode="numeric"
        autocomplete="off"
        :required="props.required"
        :disabled="props.disabled"
        :aria-invalid="invalid ? 'true' : undefined"
        :aria-describedby="describedWithUnit(describedBy, id)"
        :class="controlClasses(invalid, 'text-right tabular-nums')"
        @blur="commit"
        @keydown.enter="commit"
      />
      <span :id="`${id}-unite`" class="text-base font-medium text-muted">FCFA</span>
    </div>
  </FieldShell>
</template>
