<script setup lang="ts">
/**
 * « Demander une dispense des frais d'examen » depuis la file du jour (P03 avec B06 ; fichier 02 : « Le
 * démarrage clinique nécessite une facture examen soldée ou une dérogation gérante enregistrée avec
 * motif »). Proposé seulement quand le serveur contrôle RÉELLEMENT le paiement de l'examen et dit que
 * les frais de CETTE visite sont à encaisser (`exam_fee_control = UNPAID`), au compte qui peut demander
 * une approbation (`approval.request`), module « settings » ouvert (écran de décision de la gérante).
 *
 * La demande porte le contenu exact (cette visite, ce patient) ; la gérante décide sur son écran
 * « Approbations ». Une dispense accordée n'est pas une gratuité : elle autorise seulement le démarrage de
 * l'examen de cette visite, une fois. Après l'envoi, la file affiche un avis et se relit (`requested`) : la
 * visite passe « Dispense … demandée ».
 */
import { computed, ref } from 'vue'

import { formatTimeFr } from '@/app/clinicTime'
import { canOnScreen, moduleOpenOnScreen } from '@/app/session'
import ApprovalRequestDialog from '@/components/approvals/ApprovalRequestDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { examFeeWaiverSubject } from '@/domain/approvals/approvals'
import type { Visit } from '@/domain/contracts/generated'
import { patientDisplayName } from '@/domain/patients/identity'

const props = defineProps<{ visit: Visit }>()

const emit = defineEmits<{ requested: [message: string] }>()

const open = ref(false)

const name = computed(() =>
  patientDisplayName(props.visit.patient.last_name, props.visit.patient.first_names),
)

/** Visites encore avant l'examen : une visite en consultation, terminée ou annulée n'en propose pas. */
const BEFORE_EXAM: readonly string[] = ['ARRIVED', 'AWAITING_EXAM_FEE']

const offered = computed(
  () =>
    props.visit.exam_fee_control === 'UNPAID' &&
    BEFORE_EXAM.includes(props.visit.status) &&
    canOnScreen('approval.request') &&
    moduleOpenOnScreen('settings'),
)

const subject = computed(() => examFeeWaiverSubject(props.visit.id, props.visit.patient_id))
const summary = computed(
  () =>
    `Dispense des frais d’examen pour ${name.value}, visite arrivée à ${formatTimeFr(props.visit.occurred_at)}. Elle autorise seulement le démarrage de cet examen.`,
)

function requested(): void {
  open.value = false
  emit(
    'requested',
    `Demande de dispense des frais d’examen envoyée à la gérante pour ${name.value} : elle n’est pas encore accordée.`,
  )
}
</script>

<template>
  <div v-if="offered" class="space-y-2">
    <AppButton
      variant="secondary"
      :accessible-name="`Demander une dispense des frais d’examen pour ${name}`"
      @click="open = true"
      >Demander une dispense des frais d’examen</AppButton
    >
    <ApprovalRequestDialog
      :open="open"
      :subject="subject"
      :summary="summary"
      @requested="requested"
      @cancel="open = false"
    />
  </div>
</template>
