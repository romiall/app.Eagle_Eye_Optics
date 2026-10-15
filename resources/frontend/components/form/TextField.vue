<script setup lang="ts">
import { controlClasses } from './classes'
import FieldShell from './FieldShell.vue'

/** Champ texte : la valeur saisie est conservée telle quelle ; la validation vient des règles. */
const props = withDefaults(
  defineProps<{
    label: string
    modelValue: string
    hint?: string | undefined
    error?: string | null
    required?: boolean
    disabled?: boolean
    type?: 'text' | 'password' | 'email' | 'tel' | 'search'
    autocomplete?: string
    inputmode?: 'text' | 'numeric' | 'decimal' | 'tel' | 'email' | 'search'
    placeholder?: string | undefined
    maxlength?: number | undefined
    hideLabel?: boolean
    controlId?: string | undefined
  }>(),
  {
    hint: undefined,
    error: null,
    required: false,
    disabled: false,
    type: 'text',
    autocomplete: 'off',
    inputmode: 'text',
    placeholder: undefined,
    maxlength: undefined,
    hideLabel: false,
    controlId: undefined,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string]; blur: [] }>()
</script>

<template>
  <FieldShell
    v-slot="{ id, describedBy, invalid }"
    :label="props.label"
    :hint="props.hint"
    :error="props.error"
    :required="props.required"
    :hide-label="props.hideLabel"
    :control-id="props.controlId"
  >
    <input
      :id="id"
      :value="props.modelValue"
      :type="props.type"
      :autocomplete="props.autocomplete"
      :inputmode="props.inputmode"
      :placeholder="props.placeholder"
      :maxlength="props.maxlength"
      :required="props.required"
      :disabled="props.disabled"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-describedby="describedBy"
      :class="controlClasses(invalid)"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
      @blur="emit('blur')"
    />
  </FieldShell>
</template>
