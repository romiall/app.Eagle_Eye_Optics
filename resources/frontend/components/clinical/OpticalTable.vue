<script setup lang="ts">
/**
 * Mesures OD/OG (OU pour l'acuité seulement) du fichier 04 : OD toujours avant OG, libellés et unités
 * répétés. Une seule structure accessible : un groupe par œil et des champs libellés. Sur grand
 * écran, une grille sous des en-têtes de colonnes ; sur téléphone, des blocs OD puis OG, sans
 * défilement horizontal. Aucune mesure n'est recopiée automatiquement d'un œil à l'autre ni d'une
 * réfraction vers une prescription.
 *
 * Une cellule est par défaut un champ dioptrie exact (F08). Une colonne d'une autre nature (axe en
 * degrés, acuité selon l'échelle du cabinet, hauteur de montage) fournit sa cellule par la fente
 * `cellule-<clé>` : ses règles appartiennent à la tâche clinique qui la livre (C02, C04), pas à ce
 * composant de présentation.
 */
import { computed } from 'vue'

import DecimalField from '@/components/form/DecimalField.vue'

export type Eye = 'OD' | 'OG' | 'OU'

export interface OpticalColumn {
  key: string
  label: string
  unit?: string
}

export type OpticalValue = string | number | null
export type OpticalMeasures = Readonly<Record<string, Readonly<Record<string, OpticalValue>>>>

/** Propriétés passées à une cellule fournie par la fente `cellule-<clé>`. */
export interface OpticalCellProps {
  eye: Eye
  column: OpticalColumn
  /** Libellé complet à donner au champ (colonne, unité et œil). */
  label: string
  value: OpticalValue
  error: string | null
  update: (value: OpticalValue) => void
}

const props = withDefaults(
  defineProps<{
    caption: string
    columns: readonly OpticalColumn[]
    eyes?: readonly Eye[]
    modelValue: OpticalMeasures
    /** Erreurs par « œil.colonne » (ex. `OD.sphere`). */
    errors?: Readonly<Record<string, string>>
  }>(),
  { eyes: () => ['OD', 'OG'], errors: () => ({}) },
)

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, Record<string, OpticalValue>>]
}>()

const EYE_LABELS: Record<Eye, string> = {
  OD: 'Œil droit (OD)',
  OG: 'Œil gauche (OG)',
  OU: 'Les deux yeux (OU)',
}
const ORDER: readonly Eye[] = ['OD', 'OG', 'OU']

const orderedEyes = computed(() => ORDER.filter((eye) => props.eyes.includes(eye)))
const gridStyle = computed(() => ({ '--eeo-optical-columns': String(props.columns.length) }))

function label(column: OpticalColumn, eye: Eye): string {
  return `${column.label}${column.unit ? ` (${column.unit})` : ''}, ${EYE_LABELS[eye]}`
}

function valueOf(eye: Eye, key: string): OpticalValue {
  return props.modelValue[eye]?.[key] ?? null
}

function update(eye: Eye, key: string, value: OpticalValue): void {
  const next: Record<string, Record<string, OpticalValue>> = {}
  for (const [name, values] of Object.entries(props.modelValue)) next[name] = { ...values }
  next[eye] = { ...(next[eye] ?? {}), [key]: value }
  emit('update:modelValue', next)
}

function cell(eye: Eye, column: OpticalColumn): OpticalCellProps {
  return {
    eye,
    column,
    label: label(column, eye),
    value: valueOf(eye, column.key),
    error: props.errors[`${eye}.${column.key}`] ?? null,
    update: (value) => update(eye, column.key, value),
  }
}

function diopterValue(value: OpticalValue): string | null {
  return typeof value === 'string' ? value : null
}
</script>

<template>
  <section class="space-y-3" :style="gridStyle">
    <h3 class="text-base font-semibold text-ink">{{ props.caption }}</h3>
    <div
      class="hidden gap-3 text-sm font-semibold text-muted sm:grid sm:grid-cols-[minmax(6rem,8rem)_repeat(var(--eeo-optical-columns),minmax(0,1fr))]"
      aria-hidden="true"
    >
      <span>Œil</span>
      <span v-for="column in props.columns" :key="column.key">
        {{ column.label }}<template v-if="column.unit"> ({{ column.unit }})</template>
      </span>
    </div>
    <fieldset
      v-for="eye in orderedEyes"
      :key="eye"
      class="grid min-w-0 grid-cols-1 gap-3 rounded-lg border border-line bg-surface p-3 sm:grid-cols-[minmax(6rem,8rem)_repeat(var(--eeo-optical-columns),minmax(0,1fr))] sm:items-start sm:border-0 sm:bg-transparent sm:p-0"
    >
      <legend class="sr-only">{{ EYE_LABELS[eye] }}</legend>
      <span class="font-semibold text-ink sm:pt-3" aria-hidden="true">{{ EYE_LABELS[eye] }}</span>
      <template v-for="column in props.columns" :key="column.key">
        <slot :name="`cellule-${column.key}`" v-bind="cell(eye, column)">
          <DecimalField
            :label="label(column, eye)"
            :model-value="diopterValue(valueOf(eye, column.key))"
            kind="diopter"
            :field="column.key"
            :error="props.errors[`${eye}.${column.key}`] ?? null"
            hide-label-wide
            @update:model-value="update(eye, column.key, $event)"
          />
        </slot>
      </template>
    </fieldset>
  </section>
</template>
