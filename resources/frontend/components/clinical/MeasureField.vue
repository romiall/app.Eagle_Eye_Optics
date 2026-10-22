<script setup lang="ts">
/**
 * Saisie d'une mesure clinique (sphère, cylindre, addition, axe, écart pupillaire, hauteur de montage) ou d'une
 * valeur lue (acuité) : la saisie est conservée TELLE QUELLE dans le brouillon (virgule ou point décimal, signe
 * facultatif) ; la conversion en nombre exact et le refus d'une valeur invalide sont faits par les règles pures
 * du domaine (`domain/clinical/fields`), jamais ici. Vide = non mesuré, jamais « 0 ».
 *
 * Clavier du téléphone : clavier texte (le pavé décimal de l'iPhone n'a pas de signe « - »). L'unité est
 * annoncée dans le libellé. `hideLabelWide` masque le libellé sur grand écran seulement, quand l'en-tête de
 * colonne du tableau optique le montre déjà (il reste lu par les lecteurs d'écran).
 */
import { controlClasses } from '@/components/form/classes'
import FieldShell from '@/components/form/FieldShell.vue'

const props = withDefaults(
  defineProps<{
    label: string
    modelValue: string
    hint?: string | undefined
    error?: string | null
    disabled?: boolean
    hideLabelWide?: boolean
    controlId?: string | undefined
    /** Identifiant d'un `<datalist>` de suggestions (saisie libre conservée). */
    list?: string | undefined
    inputmode?: 'text' | 'numeric' | 'decimal'
    maxlength?: number | undefined
  }>(),
  {
    hint: undefined,
    error: null,
    disabled: false,
    hideLabelWide: false,
    controlId: undefined,
    list: undefined,
    inputmode: 'text',
    maxlength: undefined,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <FieldShell
    v-slot="{ id, describedBy, invalid }"
    :label="props.label"
    :hint="props.hint"
    :error="props.error"
    :hide-label-wide="props.hideLabelWide"
    :control-id="props.controlId"
  >
    <input
      :id="id"
      :value="props.modelValue"
      type="text"
      autocomplete="off"
      :inputmode="props.inputmode"
      :list="props.list"
      :maxlength="props.maxlength"
      :disabled="props.disabled"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-describedby="describedBy"
      :class="controlClasses(invalid, 'tabular-nums')"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
  </FieldShell>
</template>
