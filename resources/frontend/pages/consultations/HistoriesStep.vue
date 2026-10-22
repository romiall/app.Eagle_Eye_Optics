<script setup lang="ts">
/**
 * Étape « Antécédents » (DP §5 ; fichier 02) : trois groupes (oculaires, médicaux, familiaux
 * oculaires) en sélection multiple, « Autre » à préciser, un commentaire par groupe et « Aucun
 * connu » exclusif des autres valeurs du groupe. Un groupe jamais ouvert reste « non renseigné » : il
 * n'est pas envoyé, et surtout pas comme « Aucun connu ». L'état de chaque groupe est écrit en toutes
 * lettres (non renseigné, aucun connu, renseigné) : ce n'est jamais la couleur seule.
 */
import TextAreaField from '@/components/form/TextAreaField.vue'
import { CONTROL_IDS } from '@/domain/clinical/controls'
import { HISTORY_GROUP_LABELS, HISTORY_GROUPS, type HistoryGroup } from '@/domain/clinical/labels'
import {
  type HistoriesDraft,
  type HistoryGroupDraft,
  isHistoryGroupFilled,
  TEXT_MAX_LENGTH,
  type TermRow,
} from '@/domain/clinical/steps'

import TermChecklist from './TermChecklist.vue'

const model = defineModel<HistoriesDraft>({ required: true })
const props = withDefaults(
  defineProps<{ errors: Readonly<Record<string, string>>; disabled?: boolean }>(),
  { disabled: false },
)

function update(group: HistoryGroup, patch: Partial<HistoryGroupDraft>): void {
  model.value = { ...model.value, [group]: { ...model.value[group], ...patch } }
}

function error(controlId: string): string | null {
  return props.errors[controlId] ?? null
}

function status(group: HistoryGroup): { symbol: string; text: string } {
  const value = model.value[group]
  if (!isHistoryGroupFilled(value)) return { symbol: '○', text: 'Non renseigné' }
  if (value.knownNone) return { symbol: '✓', text: 'Aucun connu' }
  return { symbol: '●', text: 'Renseigné' }
}

function setTerms(group: HistoryGroup, rows: TermRow[]): void {
  update(group, { terms: rows })
}
</script>

<template>
  <div class="space-y-6">
    <p class="max-w-prose text-muted">
      Un groupe laissé sans case cochée ni commentaire reste « non renseigné » : ce n’est pas «
      Aucun connu ».
    </p>
    <section
      v-for="group in HISTORY_GROUPS"
      :key="group"
      class="space-y-3 rounded-lg border-2 border-line bg-surface p-3"
    >
      <TermChecklist
        :group="group"
        :legend="HISTORY_GROUP_LABELS[group]"
        :id-base="CONTROL_IDS.historyGroup(group)"
        :rows="model[group].terms"
        :known-none="model[group].knownNone"
        :error="error(CONTROL_IDS.historyGroup(group)) ?? error(CONTROL_IDS.historyNone(group))"
        :other-error="error(CONTROL_IDS.historyOther(group))"
        :disabled="props.disabled"
        @update:rows="setTerms(group, $event)"
        @update:known-none="update(group, { knownNone: $event })"
      />
      <TextAreaField
        :model-value="model[group].comments"
        :label="`Commentaires sur les ${HISTORY_GROUP_LABELS[group].toLowerCase()}`"
        :control-id="CONTROL_IDS.historyComments(group)"
        :rows="2"
        :maxlength="TEXT_MAX_LENGTH"
        :error="error(CONTROL_IDS.historyComments(group))"
        :disabled="props.disabled"
        @update:model-value="update(group, { comments: $event })"
      />
      <p class="text-sm font-medium text-ink">
        <span class="sr-only">{{ HISTORY_GROUP_LABELS[group] }}, </span
        ><span aria-hidden="true">{{ status(group).symbol }} </span>État : {{ status(group).text }}
      </p>
    </section>
  </div>
</template>
