<script setup lang="ts">
/**
 * Fermeture exceptionnelle datée d'une ressource (congés, absence) : du jour de début au jour de fin inclus, en
 * journées entières du cabinet, motif obligatoire. Refusée si des rendez-vous existent sur la période (ils se
 * déplacent ou s'annulent d'abord).
 */
import { ref, useId } from 'vue'

import DateField from '@/components/form/DateField.vue'
import TextAreaField from '@/components/form/TextAreaField.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { SchedulingResource } from '@/domain/contracts/generated'
import { CivilDate } from '@/domain/values/civilDate'
import { newUlid } from '@/domain/values/ulid'

import { localMidnight } from '../localTime'
import { useAgendaWrite } from './useAgendaWrite'

const props = defineProps<{ resource: SchedulingResource }>()
const emit = defineEmits<{ saved: [message: string] }>()

const ids = useId()
const write = useAgendaWrite()
const exceptionId = ref(newUlid())
const from = ref<string | null>(null)
const to = ref<string | null>(null)
const reason = ref('')
const localError = ref<string | null>(null)

async function save(): Promise<void> {
  localError.value = null
  let start: CivilDate | null
  let end: CivilDate | null
  try {
    start = from.value === null ? null : CivilDate.parse(from.value)
    end = to.value === null ? null : CivilDate.parse(to.value)
  } catch {
    localError.value = 'Dates invalides : JJ/MM/AAAA.'
    return
  }
  if (start === null || end === null || end.compare(start) < 0) {
    localError.value = 'Indiquez le premier et le dernier jour de la fermeture.'
    return
  }
  const done = await write.send({
    name: 'AddResourceException',
    route: { method: 'POST', path: `/agenda/resources/${props.resource.id}/exceptions` },
    payload: {
      exception_id: exceptionId.value,
      starts_at: localMidnight(start),
      ends_at: localMidnight(end.addDays(1)),
      is_available: false,
      reason: reason.value.trim(),
    },
  })
  if (done) {
    emit('saved', `Fermeture de « ${props.resource.name} » enregistrée.`)
    exceptionId.value = newUlid()
    reason.value = ''
  }
}
</script>

<template>
  <form
    class="space-y-4 rounded-xl border border-line bg-canvas p-4 sm:p-5"
    novalidate
    @submit.prevent="save"
  >
    <div class="form-grid">
      <DateField v-model="from" label="Premier jour fermé" :control-id="`${ids}-du`" />
      <DateField v-model="to" label="Dernier jour fermé" :control-id="`${ids}-au`" />
      <TextAreaField
        v-model="reason"
        class="col-span-full"
        label="Motif (obligatoire)"
        :control-id="`${ids}-motif`"
        :rows="2"
        :maxlength="1000"
        :error="write.fields.value['reason'] ?? null"
      />
    </div>
    <p v-if="localError ?? write.error.value" role="alert" class="text-sm font-medium text-danger">
      {{ localError ?? write.error.value }}
    </p>
    <div class="form-actions">
      <AppButton
        type="submit"
        variant="secondary"
        :pending="write.saving.value"
        pending-label="Enregistrement…"
      >
        <AppIcon name="calendar" />
        Enregistrer la fermeture
      </AppButton>
    </div>
  </form>
</template>
