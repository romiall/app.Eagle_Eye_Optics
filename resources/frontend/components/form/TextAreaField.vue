<script setup lang="ts">
import { controlClasses } from './classes'
import FieldShell from './FieldShell.vue'

/**
 * Champ texte sur plusieurs lignes (adresse, quartier, notes administratives) : la saisie est
 * conservée telle quelle, les retours à la ligne compris ; la validation vient des règles.
 */
const props = withDefaults(
  defineProps<{
    label: string
    modelValue: string
    hint?: string | undefined
    error?: string | null
    required?: boolean
    disabled?: boolean
    rows?: number
    autocomplete?: string
    maxlength?: number | undefined
    controlId?: string | undefined
  }>(),
  {
    hint: undefined,
    error: null,
    required: false,
    disabled: false,
    rows: 3,
    autocomplete: 'off',
    maxlength: undefined,
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
    :control-id="props.controlId"
  >
    <textarea
      :id="id"
      :value="props.modelValue"
      :rows="props.rows"
      :autocomplete="props.autocomplete"
      :maxlength="props.maxlength"
      :required="props.required"
      :disabled="props.disabled"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-describedby="describedBy"
      :class="controlClasses(invalid, 'resize-y')"
      @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
      @blur="emit('blur')"
    ></textarea>
  </FieldShell>
</template>
