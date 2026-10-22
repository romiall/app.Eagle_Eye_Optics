<script setup lang="ts">
/**
 * En-tête patient de la consultation (fichier 04 : « en-tête patient fixe sur écran large, compact sur
 * mobile » ; fichier 02 : « identité, dossier, date/heure de consultation et professionnel »).
 *
 * L'identité vient de la CONSULTATION elle-même (`patient`, identité administrative de base rendue avec
 * `clinical.read`) : l'écran ne dépend ni du module « Patients » ni du droit `patient.read`, et ne remplit jamais
 * un dossier sous un en-tête qui ne dit pas de qui il s'agit. L'état « archivée » ou « fusionnée » de la fiche
 * n'est pas porté par la consultation : il n'est pas affiché ici. Le professionnel est nommé quand le compte
 * connecté est celui de la consultation (la consultation ne porte que son identifiant).
 */
import { computed } from 'vue'

import { formatInstantFr } from '@/app/clinicTime'
import { session } from '@/app/session'
import PatientIdentityHeader from '@/components/patients/PatientIdentityHeader.vue'
import type { Consultation } from '@/domain/contracts/generated'

const props = defineProps<{
  consultation: Pick<Consultation, 'patient' | 'effective_at' | 'clinician_id'>
}>()

const patient = computed(() => ({
  ...props.consultation.patient,
  archived: false,
  merged_into_patient_id: null,
}))

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
    <PatientIdentityHeader :patient="patient" />
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
