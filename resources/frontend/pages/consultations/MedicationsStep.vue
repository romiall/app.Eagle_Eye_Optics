<script setup lang="ts">
/**
 * Étape « Médicaments » (C04 ; DP §9 ; fichier 02, « Médicaments et orientation ») : plusieurs lignes de
 * médicaments en SAISIE LIBRE CONTRÔLÉE — aucune base de médicaments n'est utilisée. Nom et forme (collyre,
 * pommade, comprimé, autre) obligatoires ; dosage ou concentration, posologie, fréquence, durée et instructions
 * facultatifs et conservés tels que saisis. AUCUNE posologie n'est déduite ou complétée : l'écran signale
 * seulement une ligne sans aucune indication. La durée structurée (nombre et unité) sert au calcul d'un
 * contrôle choisi par le professionnel. Prescrire ne crée ni vente ni mouvement de stock.
 *
 * L'ordre des lignes est celui de l'ordonnance : on monte et descend une ligne. Le formulaire est tenu par
 * l'écran ; ce composant affiche les erreurs reçues par identifiant de contrôle.
 */
import { computed, nextTick } from 'vue'

import ClinicalHint from '@/components/clinical/ClinicalHint.vue'
import SelectField from '@/components/form/SelectField.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import TextField from '@/components/form/TextField.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { CONTROL_IDS } from '@/domain/clinical/controls'
import { TEXT_MAX_LENGTH } from '@/domain/clinical/fields'
import { DURATION_UNIT_LABELS, MEDICATION_FORM_LABELS } from '@/domain/clinical/labels'
import {
  blankOrder,
  DURATION_UNITS,
  DURATION_TEXT_MAX,
  type DurationUnit,
  FORM_OTHER_MAX,
  FREQUENCY_MAX,
  type MedicationForm,
  MEDICATION_FORMS,
  type MedicationsDraft,
  missingPosology,
  moveOrder,
  NAME_MAX,
  ORDERS_MAX,
  type OrderDraft,
  orderTitle,
  STRENGTH_MAX,
} from '@/domain/clinical/medications'
import { newUlid } from '@/domain/values/ulid'

const model = defineModel<MedicationsDraft>({ required: true })
const props = withDefaults(
  defineProps<{ errors: Readonly<Record<string, string>>; disabled?: boolean }>(),
  { disabled: false },
)

const formOptions = MEDICATION_FORMS.map((form) => ({
  value: form,
  label: MEDICATION_FORM_LABELS[form],
}))
const unitOptions = DURATION_UNITS.map((unit) => ({
  value: unit,
  label: DURATION_UNIT_LABELS[unit],
}))
const withoutPosology = computed(() => new Set(missingPosology(model.value)))
const canAdd = computed(() => model.value.orders.length < ORDERS_MAX)

function error(index: number, field: string): string | null {
  return props.errors[CONTROL_IDS.order(index, field)] ?? null
}

function patch(index: number, changes: Partial<OrderDraft>): void {
  model.value = {
    orders: model.value.orders.map((order, position) =>
      position === index ? { ...order, ...changes } : order,
    ),
  }
}

async function add(): Promise<void> {
  model.value = { orders: [...model.value.orders, blankOrder(newUlid)] }
  await nextTick()
  document.getElementById(CONTROL_IDS.order(model.value.orders.length - 1, 'nom'))?.focus()
}

function remove(index: number): void {
  model.value = { orders: model.value.orders.filter((_order, position) => position !== index) }
}

async function move(index: number, direction: -1 | 1): Promise<void> {
  model.value = moveOrder(model.value, index, direction)
  await nextTick()
  // Le focus suit la ligne déplacée (le bouton de la même action sur sa nouvelle position).
  document
    .getElementById(`${CONTROL_IDS.order(index + direction, 'deplacer')}-${direction}`)
    ?.focus()
}

function chooseForm(index: number, value: string | null): void {
  const form = MEDICATION_FORMS.find((candidate) => candidate === value) ?? null
  patch(index, { form: form as MedicationForm | null })
}

function chooseUnit(index: number, value: string | null): void {
  const unit = DURATION_UNITS.find((candidate) => candidate === value) ?? null
  patch(index, { durationUnit: unit as DurationUnit | null })
}
</script>

<template>
  <div class="space-y-6">
    <ClinicalHint>
      Saisie libre : aucune base de médicaments n’est utilisée et aucune posologie n’est complétée à
      votre place. Prescrire ne crée ni vente ni mouvement de stock.
    </ClinicalHint>

    <p
      v-if="props.errors[CONTROL_IDS.orders]"
      :id="CONTROL_IDS.orders"
      tabindex="-1"
      class="text-sm font-medium text-danger"
    >
      <span aria-hidden="true">⚠ </span>{{ props.errors[CONTROL_IDS.orders] }}
    </p>
    <EmptyState
      v-if="model.orders.length === 0"
      title="Aucun médicament prescrit à cette consultation."
      icon="file-plus"
      flat
    />

    <ol class="space-y-4">
      <li
        v-for="(order, index) in model.orders"
        :key="order.id"
        class="rounded-xl border border-line bg-canvas p-4 sm:p-5"
      >
        <fieldset class="m-0 min-w-0 space-y-4 border-0 p-0">
          <legend class="mb-1 p-0 text-lg font-semibold break-words">
            Médicament {{ index + 1
            }}<template v-if="order.name.trim() !== ''"> : {{ order.name.trim() }}</template>
          </legend>

          <div class="form-grid">
            <TextField
              :model-value="order.name"
              label="Nom du médicament"
              :control-id="CONTROL_IDS.order(index, 'nom')"
              :maxlength="NAME_MAX"
              :error="error(index, 'nom')"
              :disabled="props.disabled"
              required
              class="col-span-full"
              @update:model-value="patch(index, { name: $event })"
            />
            <SelectField
              :model-value="order.form"
              label="Forme"
              placeholder="Choisir une forme…"
              :options="formOptions"
              :control-id="CONTROL_IDS.order(index, 'forme')"
              :error="error(index, 'forme')"
              :disabled="props.disabled"
              required
              @update:model-value="chooseForm(index, $event)"
            />
            <TextField
              v-if="order.form === 'OTHER'"
              :model-value="order.formOther"
              label="Autre forme : précisez"
              :control-id="CONTROL_IDS.order(index, 'forme-precision')"
              :maxlength="FORM_OTHER_MAX"
              :error="error(index, 'forme-precision')"
              :disabled="props.disabled"
              required
              @update:model-value="patch(index, { formOther: $event })"
            />
            <TextField
              :model-value="order.strength"
              label="Dosage ou concentration"
              hint="Texte libre, par exemple la concentration inscrite sur le produit."
              :control-id="CONTROL_IDS.order(index, 'dosage')"
              :maxlength="STRENGTH_MAX"
              :error="error(index, 'dosage')"
              :disabled="props.disabled"
              class="col-span-full"
              @update:model-value="patch(index, { strength: $event })"
            />
            <TextAreaField
              :model-value="order.dosage"
              label="Posologie"
              :control-id="CONTROL_IDS.order(index, 'posologie')"
              :rows="2"
              :maxlength="TEXT_MAX_LENGTH"
              :error="error(index, 'posologie')"
              :disabled="props.disabled"
              class="col-span-full"
              @update:model-value="patch(index, { dosage: $event })"
            />
            <TextField
              :model-value="order.frequency"
              label="Fréquence"
              :control-id="CONTROL_IDS.order(index, 'frequence')"
              :maxlength="FREQUENCY_MAX"
              :error="error(index, 'frequence')"
              :disabled="props.disabled"
              @update:model-value="patch(index, { frequency: $event })"
            />
            <TextField
              :model-value="order.durationText"
              label="Durée (texte)"
              :control-id="CONTROL_IDS.order(index, 'duree')"
              :maxlength="DURATION_TEXT_MAX"
              :error="error(index, 'duree')"
              :disabled="props.disabled"
              @update:model-value="patch(index, { durationText: $event })"
            />
            <TextField
              :model-value="order.durationValue"
              label="Durée structurée : nombre"
              hint="Facultatif ; sert à calculer un contrôle choisi par le professionnel."
              inputmode="numeric"
              :control-id="CONTROL_IDS.order(index, 'duree-nombre')"
              :maxlength="10"
              :error="error(index, 'duree-nombre')"
              :disabled="props.disabled"
              @update:model-value="patch(index, { durationValue: $event })"
            />
            <SelectField
              :model-value="order.durationUnit"
              label="Durée structurée : unité"
              placeholder="Choisir l’unité…"
              :options="unitOptions"
              :control-id="CONTROL_IDS.order(index, 'duree-unite')"
              :error="error(index, 'duree-unite')"
              :disabled="props.disabled"
              @update:model-value="chooseUnit(index, $event)"
            />
            <TextAreaField
              :model-value="order.instructions"
              label="Instructions"
              :control-id="CONTROL_IDS.order(index, 'instructions')"
              :rows="2"
              :maxlength="TEXT_MAX_LENGTH"
              :error="error(index, 'instructions')"
              :disabled="props.disabled"
              class="col-span-full"
              @update:model-value="patch(index, { instructions: $event })"
            />
          </div>

          <p v-if="withoutPosology.has(index)" class="text-sm font-medium text-warning">
            <span aria-hidden="true">⚠ </span>Aucune posologie, fréquence, durée ni instruction
            saisie pour ce médicament. Rien n’est complété à votre place.
          </p>

          <div v-if="!props.disabled" class="flex flex-wrap gap-3 border-t border-line pt-4">
            <AppButton
              :id="`${CONTROL_IDS.order(index, 'deplacer')}--1`"
              variant="secondary"
              :disabled="index === 0"
              :accessible-name="`Monter ${orderTitle(order, index)}`"
              @click="move(index, -1)"
              ><AppIcon name="chevron-up" :size="18" />Monter</AppButton
            >
            <AppButton
              :id="`${CONTROL_IDS.order(index, 'deplacer')}-1`"
              variant="secondary"
              :disabled="index === model.orders.length - 1"
              :accessible-name="`Descendre ${orderTitle(order, index)}`"
              @click="move(index, 1)"
              ><AppIcon name="chevron-down" :size="18" />Descendre</AppButton
            >
            <AppButton
              variant="ghost"
              :accessible-name="`Retirer ${orderTitle(order, index)}`"
              @click="remove(index)"
              ><AppIcon name="trash" :size="18" />Retirer</AppButton
            >
          </div>
        </fieldset>
      </li>
    </ol>

    <div v-if="!props.disabled || !canAdd" class="flex flex-wrap items-center gap-x-4 gap-y-2">
      <AppButton v-if="!props.disabled" variant="secondary" :disabled="!canAdd" @click="add"
        ><AppIcon name="plus" :size="18" />Ajouter un médicament</AppButton
      >
      <p v-if="!canAdd" class="text-sm text-muted">Vingt médicaments au plus par consultation.</p>
    </div>
  </div>
</template>
