<script setup lang="ts">
/**
 * États d'une lecture des écrans de vente et de caisse (fichier 04) : chargement, serveur
 * indisponible, droits insuffisants, session expirée, erreur. Le message dit ce qui est su, pas
 * davantage : une liste qui n'a pas pu être lue n'est jamais « aucune facture », une pièce inconnue (404)
 * est « introuvable », et aucune ancienne copie n'est présentée comme à jour. Les états « prêt » et
 * « vide » sont rendus par l'écran lui-même.
 */
import { computed } from 'vue'

import type { ApiError } from '@/app/api'
import type { ReadState } from '@/app/services/reads'
import StateMessage, { type ScreenState } from '@/components/feedback/StateMessage.vue'
import AppButton from '@/components/ui/AppButton.vue'

const props = withDefaults(
  defineProps<{
    state: ReadState
    /** Ce qui est lu, au singulier ou au pluriel : « la facture », « les factures », « la caisse ». */
    subject: string
    error?: ApiError | null | undefined
    /** Titre d'une pièce inconnue (404) : « Facture introuvable ». */
    notFoundTitle?: string
  }>(),
  { error: null, notFoundTitle: 'Pièce introuvable' },
)

const emit = defineEmits<{ retry: [] }>()

interface View {
  state: ScreenState
  title: string
  text: string
  retry: boolean
}

const view = computed<View | null>(() => {
  switch (props.state) {
    case 'loading':
      return { state: 'loading', title: 'Chargement…', text: '', retry: false }
    case 'offline':
      return {
        state: 'offline',
        title: 'Serveur indisponible',
        text: `Le serveur n’a pas répondu (lecture de ${props.subject}) : rien n’est conclu, et aucune ancienne copie n’est présentée comme à jour.`,
        retry: true,
      }
    case 'forbidden':
      return {
        state: 'forbidden',
        title: 'Droits insuffisants',
        text: `Ce compte n’a pas le droit de consulter ${props.subject}.`,
        retry: false,
      }
    case 'session-expired':
      return {
        state: 'offline',
        title: 'Session expirée',
        text: 'Reconnectez-vous avec le bouton « Se reconnecter » de l’en-tête, puis réessayez.',
        retry: true,
      }
    case 'error':
      if (props.error?.status === 404) {
        return {
          state: 'error',
          title: props.notFoundTitle,
          text: 'Cette pièce n’existe pas dans ce cabinet ou n’est plus accessible.',
          retry: false,
        }
      }
      return {
        state: 'error',
        title: 'Lecture impossible',
        text: props.error?.message ?? 'Une erreur inattendue est survenue.',
        retry: false,
      }
    default:
      return null
  }
})
</script>

<template>
  <StateMessage v-if="view" :state="view.state" :title="view.title">
    {{ view.text }}
    <template v-if="view.retry" #action>
      <AppButton variant="secondary" @click="emit('retry')">Réessayer</AppButton>
    </template>
  </StateMessage>
</template>
