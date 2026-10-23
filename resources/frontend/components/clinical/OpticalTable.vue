<script setup lang="ts">
/**
 * Mesures OD/OG (OU pour l'acuité seulement) du fichier 04 : OD toujours avant OG, libellés et unités
 * répétés. Une seule structure accessible : un groupe par œil et des champs libellés. Dès la
 * tablette, un tableau encadré : en-têtes de colonnes sur fond doré léger, une ligne par œil repérée
 * par sa pastille (OD, OG, OU) ; sur téléphone, des blocs OD puis OG, sans défilement horizontal.
 * Aucune mesure n'est recopiée automatiquement d'un œil à l'autre ni d'une réfraction vers une
 * prescription.
 *
 * Une cellule est par défaut un champ dioptrie exact (F08). Une colonne d'une autre nature (axe en
 * degrés, acuité selon l'échelle du cabinet, hauteur de montage) fournit sa cellule par la fente
 * `cellule-<clé>` : ses règles appartiennent à la tâche clinique qui la livre (C02, C04), pas à ce
 * composant de présentation. La fente `intro` se place sous le titre, avant le tableau (consigne,
 * choix de l'échelle).
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
/** Nom affiché à côté de la pastille (le groupe garde son nom complet `EYE_LABELS`). */
const EYE_NAMES: Record<Eye, string> = {
  OD: 'Œil droit',
  OG: 'Œil gauche',
  OU: 'Les deux yeux',
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
    <h3 class="flex items-center gap-2 text-base font-semibold text-ink">
      <span aria-hidden="true" class="h-4 w-1 shrink-0 rounded-full bg-gold"></span
      >{{ props.caption }}
    </h3>
    <slot name="intro" />
    <div
      class="space-y-3 sm:space-y-0 sm:rounded-xl sm:border sm:border-line sm:bg-surface sm:shadow-card"
    >
      <div
        class="hidden gap-x-2 rounded-t-xl bg-primary-soft px-3 py-2 text-sm font-semibold text-ink sm:grid sm:grid-cols-[3rem_repeat(var(--eeo-optical-columns),minmax(0,1fr))] lg:grid-cols-[8.5rem_repeat(var(--eeo-optical-columns),minmax(0,1fr))] lg:gap-x-3"
        aria-hidden="true"
      >
        <span>Œil</span>
        <span v-for="column in props.columns" :key="column.key" class="break-words">
          {{ column.label
          }}<span v-if="column.unit" class="font-normal text-muted"> ({{ column.unit }})</span>
        </span>
      </div>
      <fieldset
        v-for="eye in orderedEyes"
        :key="eye"
        class="grid min-w-0 grid-cols-1 gap-3 rounded-xl border border-line bg-canvas p-3 sm:grid-cols-[3rem_repeat(var(--eeo-optical-columns),minmax(0,1fr))] sm:items-start sm:gap-x-2 sm:rounded-none sm:border-x-0 sm:border-b-0 sm:bg-transparent lg:grid-cols-[8.5rem_repeat(var(--eeo-optical-columns),minmax(0,1fr))] lg:gap-x-3"
      >
        <legend class="sr-only">{{ EYE_LABELS[eye] }}</legend>
        <span class="flex items-center gap-2 font-semibold text-ink sm:min-h-11" aria-hidden="true">
          <span
            class="inline-flex min-w-9 items-center justify-center rounded-md bg-gold-soft px-1.5 py-0.5 text-sm font-semibold tracking-wide text-gold-ink"
            >{{ eye }}</span
          ><span class="sm:max-lg:hidden">{{ EYE_NAMES[eye] }}</span>
        </span>
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
    </div>
  </section>
</template>
