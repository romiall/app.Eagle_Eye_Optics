<script setup lang="ts">
/**
 * En-tête patient de la consultation (fichier 04 : « en-tête patient fixe sur écran large, compact sur
 * mobile » ; fichier 02 : « identité, dossier, date/heure de consultation et professionnel »).
 * L'identité est lue sur le serveur (fiche du patient de la consultation). Tant qu'elle n'est pas lue,
 * l'écran n'ouvre pas la saisie : on ne remplit jamais un dossier sous un en-tête qui ne dit pas de
 * qui il s'agit. Le professionnel est nommé quand le compte connecté est celui de la consultation
 * (la consultation ne porte que son identifiant).
 */
import { computed, watch } from 'vue'

import { formatInstantFr } from '@/app/clinicTime'
import { getPatient } from '@/app/services/patients'
import { session } from '@/app/session'
import PatientIdentityHeader from '@/components/patients/PatientIdentityHeader.vue'
import type { Consultation, Patient } from '@/domain/contracts/generated'

import ConsultationReadMessage from './ConsultationReadMessage.vue'
import { useClinicalRead } from './useClinicalRead'

const props = defineProps<{
  consultation: Pick<Consultation, 'patient_id' | 'effective_at' | 'clinician_id'>
}>()

const emit = defineEmits<{ identity: [patient: Patient | null] }>()

const read = useClinicalRead<Patient>(() => getPatient(props.consultation.patient_id))

/** Fiche lue pour CE patient : une lecture d'un autre patient ne s'affiche jamais à sa place. */
const patient = computed(() =>
  read.state.value === 'ready' && read.data.value?.id === props.consultation.patient_id
    ? read.data.value
    : null,
)

watch(patient, (value) => emit('identity', value), { immediate: true })

const clinician = computed(() =>
  session.account !== null && session.account.id === props.consultation.clinician_id
    ? session.account.display_name
    : null,
)
</script>

<template>
  <div
    role="group"
    aria-label="Patient et consultation"
    class="space-y-2 rounded-lg border-2 border-line bg-surface p-3 lg:sticky lg:top-0 lg:z-30"
  >
    <PatientIdentityHeader v-if="patient" :patient="patient" />
    <ConsultationReadMessage
      v-else
      :state="read.state.value === 'ready' ? 'error' : read.state.value"
      subject="identity"
      :error="read.state.value === 'ready' ? null : read.error.value"
      @retry="read.reload()"
    />
    <p class="text-sm text-muted">
      Consultation du
      <strong class="font-semibold text-ink">{{
        formatInstantFr(props.consultation.effective_at)
      }}</strong
      ><template v-if="clinician">
        <span aria-hidden="true"> · </span><span class="sr-only">, </span>Professionnel :
        {{ clinician }}</template
      >
    </p>
    <slot />
  </div>
</template>
