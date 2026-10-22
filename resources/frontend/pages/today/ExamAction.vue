<script setup lang="ts">
/**
 * Action d'examen d'une visite de la file du jour (C01) : « Démarrer l'examen » pour une visite
 * d'examen ou de suivi arrivée et sans consultation (droit clinical.write), « Reprendre l'examen »
 * quand une consultation existe (droit clinical.read), « Consulter l'examen » pour un examen terminé.
 * Seulement avec le module « consultations » ouvert par l'exploitant. Rien ne dit que l'examen est
 * réglé : le contrôle du paiement n'existe pas encore (B04, B06), et il ne bloque pas le démarrage.
 */
import { computed } from 'vue'

import { canOnScreen, moduleOpenOnScreen } from '@/app/session'
import LinkButton from '@/components/ui/LinkButton.vue'
import { consultationOfVisit, EXAM_ACTION_LABELS, examActionOf } from '@/domain/clinical/visitExam'
import type { Visit } from '@/domain/contracts/generated'
import { patientDisplayName } from '@/domain/patients/identity'

import StartExamButton from './StartExamButton.vue'

const props = defineProps<{ visit: Visit }>()

const emit = defineEmits<{ stale: [message: string] }>()

const action = computed(() =>
  examActionOf(props.visit, {
    canRead: canOnScreen('clinical.read'),
    canWrite: canOnScreen('clinical.write'),
    moduleOpen: moduleOpenOnScreen('consultations'),
  }),
)
const consultationId = computed(() => consultationOfVisit(props.visit).id)
const name = computed(() =>
  patientDisplayName(props.visit.patient.last_name, props.visit.patient.first_names),
)
</script>

<template>
  <StartExamButton v-if="action === 'start'" :visit="props.visit" @stale="emit('stale', $event)" />
  <LinkButton
    v-else-if="action !== null && consultationId !== null"
    :to="{ name: 'consultation', params: { id: consultationId } }"
    variant="secondary"
    :accessible-name="`${EXAM_ACTION_LABELS[action]} de ${name}`"
    >{{ EXAM_ACTION_LABELS[action] }}</LinkButton
  >
</template>
