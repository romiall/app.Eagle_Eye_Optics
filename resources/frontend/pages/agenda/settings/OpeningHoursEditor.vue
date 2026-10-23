<script setup lang="ts">
/**
 * Créneaux hebdomadaires d'une ressource (gérante) : la liste enregistrée REMPLACE l'ancienne (version de la
 * ressource attendue). Jour, ouverture, fermeture en heure locale du cabinet ; aucun chevauchement le même jour. Une
 * liste vide ferme la ressource : aucun horaire n'est jamais proposé par défaut.
 */
import { computed, ref, useId } from 'vue'

import SelectField from '@/components/form/SelectField.vue'
import TextField from '@/components/form/TextField.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { openingHourErrors, type OpeningHourRow, WEEKDAY_LABELS } from '@/domain/agenda/agenda'
import type { SchedulingResource } from '@/domain/contracts/generated'
import { newUlid } from '@/domain/values/ulid'

import { useAgendaWrite } from './useAgendaWrite'

const props = defineProps<{ resource: SchedulingResource }>()
const emit = defineEmits<{ saved: [message: string] }>()

const ids = useId()
const write = useAgendaWrite()
const rows = ref<OpeningHourRow[]>(
  props.resource.opening_hours.map((hour) => ({
    id: hour.id,
    weekday: hour.weekday,
    opens_at: hour.opens_at,
    closes_at: hour.closes_at,
  })),
)
const dayOptions = [1, 2, 3, 4, 5, 6, 7].map((day) => ({
  value: String(day),
  label: WEEKDAY_LABELS[day]!,
}))
const errors = computed(() => openingHourErrors(rows.value))
const tried = ref(false)

function add(): void {
  rows.value = [...rows.value, { id: newUlid(), weekday: 1, opens_at: '', closes_at: '' }]
}

function remove(index: number): void {
  rows.value = rows.value.filter((_, position) => position !== index)
}

function setDay(index: number, value: string | null): void {
  rows.value = rows.value.map((row, position) =>
    position === index ? { ...row, weekday: Number(value ?? '1') } : row,
  )
}

async function save(): Promise<void> {
  tried.value = true
  if (Object.keys(errors.value).length > 0) return
  const done = await write.send({
    name: 'ReplaceOpeningHours',
    route: {
      method: 'POST',
      path: `/agenda/resources/${props.resource.id}/opening-hours`,
      expectedVersion: 'entity',
    },
    payload: { hours: rows.value.map((row) => ({ ...row })) },
    expectedVersion: props.resource.version,
  })
  if (done) emit('saved', `Horaires de « ${props.resource.name} » enregistrés.`)
}
</script>

<template>
  <form
    class="space-y-4 rounded-xl border border-line bg-canvas p-4 sm:p-5"
    novalidate
    @submit.prevent="save"
  >
    <p v-if="rows.length === 0" class="flex items-center gap-2 text-muted">
      <AppIcon name="clock" class="text-gold" />
      Aucun créneau : la ressource n’est proposée à aucun rendez-vous.
    </p>
    <fieldset
      v-for="(row, index) in rows"
      :key="row.id"
      class="rounded-xl border border-line bg-surface p-3 sm:p-4"
    >
      <legend class="sr-only">Créneau {{ index + 1 }}</legend>
      <div class="grid grid-cols-2 items-end gap-3 lg:grid-cols-[minmax(0,1fr)_8rem_8rem_auto]">
        <div class="col-span-2 min-w-0 lg:col-span-1">
          <SelectField
            :model-value="String(row.weekday)"
            label="Jour"
            :options="dayOptions"
            :control-id="`${ids}-jour-${index}`"
            @update:model-value="setDay(index, $event)"
          />
        </div>
        <div class="min-w-0">
          <TextField
            v-model="row.opens_at"
            label="Ouverture"
            placeholder="08:00"
            :control-id="`${ids}-ouv-${index}`"
          />
        </div>
        <div class="min-w-0">
          <TextField
            v-model="row.closes_at"
            label="Fermeture"
            placeholder="12:00"
            :control-id="`${ids}-ferm-${index}`"
          />
        </div>
        <AppButton
          variant="ghost"
          class="col-span-2 lg:col-span-1"
          :accessible-name="`Retirer le créneau ${index + 1}`"
          @click="remove(index)"
        >
          <AppIcon name="trash" />
          Retirer
        </AppButton>
        <p
          v-if="tried && errors[index]"
          role="alert"
          class="col-span-full text-sm font-medium text-danger"
        >
          {{ errors[index] }}
        </p>
      </div>
    </fieldset>
    <p v-if="write.error.value" role="alert" class="text-sm font-medium text-danger">
      {{ write.error.value }}
    </p>
    <div class="form-actions">
      <AppButton variant="secondary" @click="add">
        <AppIcon name="plus" />
        Ajouter un créneau
      </AppButton>
      <AppButton
        type="submit"
        variant="primary"
        :pending="write.saving.value"
        pending-label="Enregistrement…"
      >
        <AppIcon name="check" />
        Enregistrer les horaires
      </AppButton>
    </div>
  </form>
</template>
