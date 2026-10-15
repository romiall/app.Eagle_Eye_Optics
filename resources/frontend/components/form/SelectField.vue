<script setup lang="ts">
import { controlClasses } from './classes'
import FieldShell from './FieldShell.vue'

/** Liste native (clavier et lecteurs d'écran natifs) ; l'option vide est explicite et vaut null. */
const props = withDefaults(
  defineProps<{
    label: string
    modelValue: string | null
    options: readonly { value: string; label: string }[]
    placeholder?: string | undefined
    hint?: string | undefined
    error?: string | null
    required?: boolean
    disabled?: boolean
    controlId?: string | undefined
  }>(),
  {
    placeholder: 'Choisir…',
    hint: undefined,
    error: null,
    required: false,
    disabled: false,
    controlId: undefined,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>()

function select(value: string): void {
  emit('update:modelValue', value === '' ? null : value)
}
</script>

<template>
  <FieldShell
    v-slot="{ id, describedBy, invalid }"
    :label="props.label"
    :hint="props.hint"
    :error="props.error"
    :required="props.required"
    :control-id="props.controlId"
  >
    <select
      :id="id"
      :value="props.modelValue ?? ''"
      :required="props.required"
      :disabled="props.disabled"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-describedby="describedBy"
      :class="controlClasses(invalid)"
      @change="select(($event.target as HTMLSelectElement).value)"
    >
      <option value="">{{ props.placeholder }}</option>
      <option v-for="option in props.options" :key="option.value" :value="option.value">
        {{ option.label }}
      </option>
    </select>
  </FieldShell>
</template>
