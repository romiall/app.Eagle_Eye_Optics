<script setup lang="ts">
/**
 * Résultats d'une recherche de patients (fichier 02 : nom, numéro, âge et téléphone ; fichier 04 :
 * chargement, vide, hors ligne, erreur). Une instance lit UNE page d'UNE recherche : l'écran la
 * recrée pour chaque recherche ou page, si bien qu'une réponse tardive d'une recherche précédente
 * ne peut jamais s'afficher à la place de la courante. « Aucun résultat » n'est dit que si le
 * serveur a répondu à cette recherche (`useRead` : état « vide ») ; sans réponse, c'est « Recherche
 * serveur indisponible ». Identité administrative seulement : aucun résumé médical.
 */
import { computed, nextTick, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import { clinicToday } from '@/app/clinicTime'
import { searchPatients } from '@/app/services/patients'
import { can } from '@/app/session'
import StateMessage from '@/components/feedback/StateMessage.vue'
import AppButton from '@/components/ui/AppButton.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import PaginationControls from '@/components/ui/PaginationControls.vue'
import type { PatientPage } from '@/domain/contracts/generated'
import { formatAge, patientAge, patientDisplayName } from '@/domain/patients/identity'

import ReadStateMessage from './ReadStateMessage.vue'
import { usePatientRead } from './usePatientRead'

const props = withDefaults(
  defineProps<{
    query: string
    page: number
    /** Vrai après un changement de page : le focus va au titre des résultats une fois chargés. */
    focusOnLoad?: boolean
  }>(),
  { focusOnLoad: false },
)

const emit = defineEmits<{ page: [page: number]; summary: [text: string] }>()

const read = usePatientRead<PatientPage>(() => searchPatients(props.query, props.page), {
  isEmpty: (result) => result.data.length === 0,
})

const heading = ref<HTMLElement | null>(null)
const today = clinicToday()
const canCreate = computed(() => can('patient.create'))

const result = computed(() => read.data.value)
const total = computed(() => result.value?.meta.total ?? 0)
/** Vide « certain » mais le serveur annonce des résultats : la page demandée n'existe plus. */
const pastTheEnd = computed(() => read.state.value === 'empty' && total.value > 0)

function announcement(): string {
  switch (read.state.value) {
    case 'ready': {
      const meta = result.value?.meta
      const count = meta?.total === 1 ? '1 patient trouvé' : `${meta?.total ?? 0} patients trouvés`
      return meta !== undefined && meta.last_page > 1
        ? `${count}, page ${meta.current_page} sur ${meta.last_page}`
        : count
    }
    case 'empty':
      return pastTheEnd.value ? 'Cette page ne contient plus de résultats' : 'Aucun résultat'
    case 'offline':
      return 'Recherche serveur indisponible'
    case 'forbidden':
      return 'Droits insuffisants'
    case 'session-expired':
      return 'Session expirée'
    case 'error':
      return 'La recherche a échoué'
    default:
      return ''
  }
}

watch(
  read.state,
  async (state) => {
    if (state === 'loading') return
    emit('summary', announcement())
    if (props.focusOnLoad) {
      await nextTick()
      heading.value?.focus()
    }
  },
  { immediate: true },
)

function line(patient: PatientPage['data'][number]): string {
  return [
    `Dossier ${patient.record_number}`,
    formatAge(patientAge(patient.birth_date, today)),
    patient.phone_primary ?? 'téléphone non renseigné',
  ].join(' · ')
}
</script>

<template>
  <section aria-labelledby="titre-resultats" class="space-y-4">
    <h2
      id="titre-resultats"
      ref="heading"
      tabindex="-1"
      class="text-lg font-semibold focus:outline-none"
    >
      Résultats
    </h2>

    <ReadStateMessage
      v-if="read.state.value !== 'ready' && read.state.value !== 'empty'"
      :state="read.state.value"
      subject="search"
      :error="read.error.value"
      @retry="read.reload()"
    />

    <StateMessage
      v-else-if="pastTheEnd"
      state="empty"
      title="Cette page ne contient plus de résultats"
    >
      Les résultats ont changé depuis l’affichage précédent.
      <template #action>
        <AppButton variant="secondary" @click="emit('page', 1)"
          >Revenir à la première page</AppButton
        >
      </template>
    </StateMessage>

    <StateMessage v-else-if="read.state.value === 'empty'" state="empty" title="Aucun résultat">
      Le serveur n’a trouvé aucun patient pour cette recherche. Vérifiez l’orthographe, ou cherchez
      par téléphone ou par numéro de dossier avant de créer une nouvelle fiche.
      <template v-if="canCreate" #action>
        <LinkButton to="/patients/nouveau" variant="primary">Créer un patient</LinkButton>
      </template>
    </StateMessage>

    <template v-else-if="result !== null">
      <ul class="space-y-2">
        <li v-for="patient in result.data" :key="patient.id">
          <RouterLink
            :to="{ name: 'patient', params: { id: patient.id } }"
            class="block min-h-11 space-y-1 rounded-lg border-2 border-line bg-surface p-3 hover:border-primary"
          >
            <span class="block font-semibold break-words text-ink">{{
              patientDisplayName(patient.last_name, patient.first_names)
            }}</span>
            <span class="block text-sm break-words text-muted">{{ line(patient) }}</span>
            <span
              v-if="patient.archived"
              class="inline-flex items-center gap-1 rounded-lg border-2 border-warning bg-warning-soft px-2 py-0.5 text-sm font-medium text-ink"
              ><span aria-hidden="true">⚠</span>Fiche archivée</span
            >
            <span
              v-if="patient.merged_into_patient_id !== null"
              class="inline-flex items-center gap-1 rounded-lg border-2 border-warning bg-warning-soft px-2 py-0.5 text-sm font-medium text-ink"
              ><span aria-hidden="true">⚠</span>Fiche fusionnée</span
            >
          </RouterLink>
        </li>
      </ul>

      <PaginationControls
        :current-page="result.meta.current_page"
        :last-page="result.meta.last_page"
        :total="result.meta.total"
        @page="emit('page', $event)"
      />

      <p v-if="canCreate" class="text-sm text-muted">
        Le patient n’est pas dans la liste ?
        <RouterLink
          to="/patients/nouveau"
          class="inline-flex min-h-11 items-center font-semibold text-primary underline underline-offset-4"
          >Créer un nouveau patient</RouterLink
        >
      </p>
    </template>
  </section>
</template>
