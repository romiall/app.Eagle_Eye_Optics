<script setup lang="ts">
/**
 * Impression des documents cliniques (D02, C04 ; fichier 11) : un bouton d'aperçu par document proposé,
 * puis l'aperçu du PDF préparé SUR CE POSTE (`DocumentPreview` : « Ouvrir le PDF », « Imprimer »,
 * « Télécharger le PDF »). Le composant ne décide rien : l'écran lui donne les documents permis, déjà
 * filtrés selon l'état (examen terminé, prescription validée) et les droits.
 *
 * Accessibilité : section nommée, boutons natifs de 44 px, préparation et résultat annoncés (`role=status`),
 * échec dit en texte ; à 360 px, les boutons s'empilent.
 */
import { computed } from 'vue'

import { clinicalDocumentsPersistent } from '@/app/services/clinicalDocuments'
import DocumentPreview from '@/components/documents/DocumentPreview.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import AppButton from '@/components/ui/AppButton.vue'

import { type PrintAction, useClinicalPrint } from './useClinicalPrint'

const props = defineProps<{
  titleId: string
  title: string
  actions: readonly PrintAction[]
}>()

const { status, rendered, heading, failure, active, announcement, prepare, close } =
  useClinicalPrint()
const loading = computed(() => status.value === 'loading')
const persistent = clinicalDocumentsPersistent()
</script>

<template>
  <section
    :aria-labelledby="props.titleId"
    class="space-y-3 rounded-xl border border-line bg-surface shadow-card p-4"
  >
    <h2 :id="props.titleId" class="text-lg font-semibold">{{ props.title }}</h2>
    <div class="max-w-prose space-y-1 text-base text-ink">
      <slot />
      <p class="text-sm text-muted">
        Le PDF est préparé sur ce poste ; vérifiez l’aperçu, puis imprimez ou téléchargez.
        <template v-if="!persistent">
          Il n’est gardé que pendant cette session, rien n’est conservé sur le poste après la
          fermeture de l’onglet.</template
        >
      </p>
    </div>

    <div v-if="props.actions.length > 0" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
      <AppButton
        v-for="action in props.actions"
        :key="action.id"
        variant="secondary"
        :pending="loading && active === action.id"
        pending-label="Préparation du PDF…"
        :disabled="loading"
        @click="prepare(action)"
        >{{ action.label }}</AppButton
      >
    </div>
    <p v-else class="text-base text-ink">
      <span aria-hidden="true">○ </span>Aucun document à imprimer avec ces droits.
    </p>

    <p role="status" aria-live="polite" class="sr-only">{{ announcement }}</p>

    <StateMessage
      v-if="status === 'loading'"
      state="loading"
      title="Préparation du PDF sur ce poste…"
    />
    <StateMessage v-else-if="status === 'error' && failure" state="error" :title="failure.title">
      {{ failure.message }}
    </StateMessage>
    <div v-else-if="status === 'ready' && rendered" class="space-y-3">
      <DocumentPreview :document="rendered" :heading="heading" />
      <AppButton variant="ghost" @click="close">Fermer l’aperçu</AppButton>
    </div>
  </section>
</template>
