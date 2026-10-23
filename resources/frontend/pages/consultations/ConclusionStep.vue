<script setup lang="ts">
/**
 * Étape « Conclusion et orientation » (DP §10 ; fichier 02) : conclusion en texte libre, orientation
 * vers un ophtalmologiste oui / non / non renseigné et, si oui, le niveau d'urgence (OBLIGATOIRE :
 * l'écran refuse l'envoi sans lui) et les motifs d'orientation (« Autre » à préciser). Si
 * l'orientation n'est pas « Oui », le niveau et les motifs sont masqués et jamais envoyés : l'écran
 * le dit si des réponses restent en mémoire. Aucun diagnostic n'est déduit des saisies.
 */
import { computed } from 'vue'

import SelectField from '@/components/form/SelectField.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import YesNoField from '@/components/form/YesNoField.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import { CONTROL_IDS } from '@/domain/clinical/controls'
import { isUrgency, URGENCY_LABELS, URGENCY_LEVELS } from '@/domain/clinical/labels'
import {
  type ConclusionDraft,
  referralAnswersIgnored,
  TEXT_MAX_LENGTH,
} from '@/domain/clinical/steps'

import TermChecklist from './TermChecklist.vue'

const model = defineModel<ConclusionDraft>({ required: true })
const props = withDefaults(
  defineProps<{ errors: Readonly<Record<string, string>>; disabled?: boolean }>(),
  { disabled: false },
)

function update<K extends keyof ConclusionDraft>(key: K, value: ConclusionDraft[K]): void {
  model.value = { ...model.value, [key]: value }
}

const urgencyOptions = URGENCY_LEVELS.map((level) => ({
  value: level,
  label: URGENCY_LABELS[level],
}))

const ignored = computed(() => referralAnswersIgnored(model.value))

function chooseUrgency(value: string | null): void {
  update('urgency', value !== null && isUrgency(value) ? value : null)
}

function error(controlId: string): string | null {
  return props.errors[controlId] ?? null
}
</script>

<template>
  <div class="space-y-6">
    <TextAreaField
      :model-value="model.conclusion"
      label="Conclusion"
      :control-id="CONTROL_IDS.conclusion"
      :rows="5"
      :maxlength="TEXT_MAX_LENGTH"
      :error="error(CONTROL_IDS.conclusion)"
      :disabled="props.disabled"
      @update:model-value="update('conclusion', $event)"
    />

    <SectionCard flat>
      <div class="space-y-6">
        <YesNoField
          :model-value="model.referral"
          legend="Orientation vers un ophtalmologiste"
          :control-id="CONTROL_IDS.referral"
          :error="error(CONTROL_IDS.referral)"
          :disabled="props.disabled"
          @update:model-value="update('referral', $event)"
        />

        <template v-if="model.referral === true">
          <SelectField
            :model-value="model.urgency"
            label="Niveau d’urgence"
            placeholder="Choisir un niveau…"
            hint="Obligatoire quand une orientation est proposée."
            :control-id="CONTROL_IDS.urgency"
            :options="urgencyOptions"
            :error="error(CONTROL_IDS.urgency)"
            :disabled="props.disabled"
            required
            class="max-w-md"
            @update:model-value="chooseUrgency"
          />
          <TermChecklist
            group="REFERRAL_REASON"
            legend="Motifs de l’orientation"
            hint="Plusieurs motifs peuvent être cochés."
            :id-base="CONTROL_IDS.reasons"
            :rows="model.reasons"
            :error="error(CONTROL_IDS.reasons)"
            :other-error="error(CONTROL_IDS.reasonOther)"
            :disabled="props.disabled"
            @update:rows="update('reasons', $event)"
          />
        </template>
        <p v-if="ignored" class="text-sm font-medium text-warning">
          <span aria-hidden="true">⚠ </span>Le niveau d’urgence et les motifs saisis ne sont pas
          enregistrés tant que l’orientation n’est pas « Oui ».
        </p>
      </div>
    </SectionCard>
  </div>
</template>
