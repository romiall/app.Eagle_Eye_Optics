<script setup lang="ts">
/**
 * États d'une lecture de la file du jour (fichier 04) : chargement, serveur indisponible, droits
 * insuffisants, session expirée, erreur. Le message dit ce qui est su, pas davantage : une file qui
 * n'a pas pu être lue est « File du jour indisponible », jamais « Aucune visite ce jour » (cela ne
 * prouve pas que personne ne soit arrivé). Les états « prêt » et « vide » sont rendus par la liste.
 */
import { computed } from 'vue'

import type { ApiError } from '@/app/api'
import type { ReadState } from '@/app/services/reads'
import StateMessage, { type ScreenState } from '@/components/feedback/StateMessage.vue'
import AppButton from '@/components/ui/AppButton.vue'

const props = defineProps<{
  state: ReadState
  error?: ApiError | null | undefined
}>()

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
      return { state: 'loading', title: 'Chargement de la file du jour…', text: '', retry: false }
    case 'offline':
      return {
        state: 'offline',
        title: 'File du jour indisponible',
        text: 'Le serveur n’a pas répondu : la file n’a pas pu être lue. Cela ne prouve pas qu’aucun patient ne soit arrivé, et aucune ancienne copie n’est présentée comme à jour.',
        retry: true,
      }
    case 'forbidden':
      return {
        state: 'forbidden',
        title: 'Droits insuffisants',
        text: 'Ce compte n’a pas le droit de consulter la file du jour.',
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
      return {
        state: 'error',
        title: 'La file du jour n’a pas pu être lue',
        text:
          props.error?.fields['date']?.[0] ??
          props.error?.fields['page']?.[0] ??
          props.error?.message ??
          'Une erreur inattendue est survenue.',
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
