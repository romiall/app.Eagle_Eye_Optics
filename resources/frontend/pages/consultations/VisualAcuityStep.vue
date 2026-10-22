<script setup lang="ts">
/**
 * Étape « Acuité visuelle » (C02 ; DP §6 et §7 ; fichier 02) : acuité de loin et de près, sans et avec
 * correction, pour OD, OG et OU (douze valeurs), puis acuité après correction OD, OG, OU (trois valeurs, sans
 * distinction loin/près). Toutes les valeurs sont facultatives : une case vide est « non mesuré », jamais zéro ;
 * « 0 » est une valeur.
 *
 * L'échelle est choisie explicitement pour chaque groupe (loin, près, après correction) ; la valeur lue est
 * conservée telle que saisie (« 10/10 », « 6/6 », « P2 »). AUCUNE conversion entre échelles n'est faite : des
 * échelles différentes dans un même groupe sont signalées, leurs valeurs ne sont pas comparables. Les valeurs
 * usuelles proposées sont celles d'échelles de DÉMONSTRATION, non validées cliniquement ; la saisie libre reste
 * toujours possible. Le formulaire est tenu par l'écran ; ce composant affiche les erreurs reçues par
 * identifiant de contrôle.
 */
import { computed } from 'vue'

import MeasureField from '@/components/clinical/MeasureField.vue'
import OpticalTable, {
  type OpticalColumn,
  type OpticalMeasures,
} from '@/components/clinical/OpticalTable.vue'
import SelectField from '@/components/form/SelectField.vue'
import {
  ACUITY_GROUPS,
  type AcuityCorrection,
  type AcuityEye,
  type AcuityGroup,
  acuitySlotKey,
  CORRECTION_TITLES,
  groupScale,
  mixedScaleGroups,
  setAcuityValue,
  setGroupScale,
  type VisualAcuityDraft,
} from '@/domain/clinical/acuity'
import { acuitySuggestions, isDemoScale } from '@/domain/clinical/acuityScales'
import { CONTROL_IDS } from '@/domain/clinical/controls'
import type { VisualAcuityScale } from '@/domain/contracts/generated'

const model = defineModel<VisualAcuityDraft>({ required: true })
const props = withDefaults(
  defineProps<{
    errors: Readonly<Record<string, string>>
    disabled?: boolean
    scales: readonly VisualAcuityScale[]
    /** Les échelles ont été lues sur le serveur (sinon la saisie des échelles est impossible). */
    scalesReady?: boolean
  }>(),
  { disabled: false, scalesReady: true },
)

const EYES: readonly AcuityEye[] = ['OD', 'OG', 'OU']

const activeScales = computed(() => props.scales.filter((scale) => scale.active))
const mixed = computed(() => new Set(mixedScaleGroups(model.value).map((group) => group.key)))
const hasDemo = computed(() => props.scales.some((scale) => isDemoScale(scale.code)))

function scaleOptions(group: AcuityGroup): { value: string; label: string }[] {
  const options = activeScales.value.map((scale) => ({ value: scale.id, label: scale.label }))
  // Une échelle désactivée depuis reste visible tant qu'une valeur l'utilise : elle n'est jamais retirée en silence.
  const used = groupScale(model.value, group.key)
  const inactive = props.scales.find((scale) => scale.id === used && !scale.active)
  return inactive === undefined
    ? options
    : [...options, { value: inactive.id, label: `${inactive.label} (désactivée)` }]
}

function columns(group: AcuityGroup): OpticalColumn[] {
  return group.corrections.map((correction) => ({ key: correction, label: CORRECTION_TITLES[correction] }))
}

function slotKey(group: AcuityGroup, correction: string, eye: AcuityEye): string {
  return acuitySlotKey(group.context, group.distance, correction as AcuityCorrection, eye)
}

function measures(group: AcuityGroup): OpticalMeasures {
  const result: Record<string, Record<string, string>> = {}
  for (const eye of EYES) {
    const row: Record<string, string> = {}
    for (const correction of group.corrections) {
      row[correction] = model.value[slotKey(group, correction, eye)]?.value ?? ''
    }
    result[eye] = row
  }
  return result
}

function errorsOf(group: AcuityGroup): Record<string, string> {
  const result: Record<string, string> = {}
  for (const eye of EYES) {
    for (const correction of group.corrections) {
      const message = props.errors[CONTROL_IDS.acuityValue(slotKey(group, correction, eye))]
      if (message !== undefined) result[`${eye}.${correction}`] = message
    }
  }
  return result
}

function scaleCode(group: AcuityGroup): string | null {
  const id = groupScale(model.value, group.key)
  return props.scales.find((scale) => scale.id === id)?.code ?? null
}

function listId(group: AcuityGroup): string {
  return `acuite-valeurs-${group.key.replaceAll('|', '-')}`
}

function chooseScale(group: AcuityGroup, scaleId: string | null): void {
  model.value = setGroupScale(model.value, group.key, scaleId)
}

function setValue(group: AcuityGroup, correction: string, eye: AcuityEye, value: string): void {
  model.value = setAcuityValue(model.value, slotKey(group, correction, eye), value)
}

function valueOf(group: AcuityGroup, correction: string, eye: AcuityEye): string {
  return model.value[slotKey(group, correction, eye)]?.value ?? ''
}
</script>

<template>
  <div class="space-y-8">
    <p class="max-w-prose text-base text-muted">
      Saisissez les valeurs lues, telles que l’échelle les donne. Une case vide est « non mesuré » ;
      « 0 » est une valeur. Aucune conversion entre échelles n’est faite.
    </p>
    <p
      v-if="props.scalesReady && props.scales.length === 0"
      role="alert"
      class="rounded-lg border-2 border-warning bg-warning-soft p-3 text-base font-medium text-ink"
    >
      <span aria-hidden="true">⚠ </span>Aucune échelle d’acuité n’est configurée pour ce cabinet : les
      acuités ne peuvent pas être saisies. Demandez à l’exploitant de les créer (commande
      <code>eeo:clinical:scales</code>).
    </p>
    <p
      v-else-if="!props.scalesReady"
      role="alert"
      class="rounded-lg border-2 border-warning bg-warning-soft p-3 text-base font-medium text-ink"
    >
      <span aria-hidden="true">⚠ </span>Les échelles d’acuité n’ont pas pu être lues : le choix de
      l’échelle est indisponible. Aucune échelle n’est devinée.
    </p>
    <p v-if="hasDemo" class="max-w-prose text-sm text-muted">
      <span aria-hidden="true">○ </span>Échelles de démonstration : non validées cliniquement, à
      remplacer par celles du cabinet.
    </p>

    <section v-for="group in ACUITY_GROUPS" :key="group.key" class="space-y-3">
      <SelectField
        :model-value="groupScale(model, group.key)"
        :label="`${group.title} : échelle utilisée`"
        :placeholder="mixed.has(group.key) ? 'Plusieurs échelles : en choisir une' : 'Choisir l’échelle…'"
        :options="scaleOptions(group)"
        :control-id="CONTROL_IDS.acuityScale(group.key)"
        :error="props.errors[CONTROL_IDS.acuityScale(group.key)] ?? null"
        :disabled="props.disabled || !props.scalesReady || props.scales.length === 0"
        @update:model-value="chooseScale(group, $event)"
      />
      <p
        v-if="mixed.has(group.key)"
        class="rounded-lg border-2 border-warning bg-warning-soft p-3 text-sm font-medium text-ink"
      >
        <span aria-hidden="true">⚠ </span>Échelles différentes dans ce groupe : ses valeurs ne sont
        pas comparables entre elles. Choisissez une seule échelle pour tout le groupe, ou gardez-les
        séparées en connaissance de cause.
      </p>
      <datalist :id="listId(group)">
        <option v-for="suggestion in acuitySuggestions(scaleCode(group))" :key="suggestion" :value="suggestion" />
      </datalist>
      <OpticalTable
        :caption="group.title"
        :columns="columns(group)"
        :eyes="EYES"
        :model-value="measures(group)"
        :errors="errorsOf(group)"
      >
        <template #cellule-WITHOUT="cell">
          <MeasureField
            :label="`${group.title}, sans correction, ${cell.eye}`"
            :model-value="valueOf(group, 'WITHOUT', cell.eye)"
            :control-id="CONTROL_IDS.acuityValue(slotKey(group, 'WITHOUT', cell.eye))"
            :error="cell.error"
            :list="listId(group)"
            :maxlength="100"
            :disabled="props.disabled"
            hide-label-wide
            @update:model-value="setValue(group, 'WITHOUT', cell.eye, $event)"
          />
        </template>
        <template #cellule-WITH="cell">
          <MeasureField
            :label="`${group.title}, avec correction, ${cell.eye}`"
            :model-value="valueOf(group, 'WITH', cell.eye)"
            :control-id="CONTROL_IDS.acuityValue(slotKey(group, 'WITH', cell.eye))"
            :error="cell.error"
            :list="listId(group)"
            :maxlength="100"
            :disabled="props.disabled"
            hide-label-wide
            @update:model-value="setValue(group, 'WITH', cell.eye, $event)"
          />
        </template>
      </OpticalTable>
    </section>
  </div>
</template>
