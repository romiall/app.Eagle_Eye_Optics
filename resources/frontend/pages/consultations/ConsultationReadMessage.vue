<script setup lang="ts">
/**
 * États d'une lecture clinique (fichier 04) : chargement, serveur indisponible, droits insuffisants,
 * session expirée, erreur. Le message dit ce qui est su, pas davantage : une consultation qui n'a
 * pas pu être lue est « Consultation indisponible », jamais une consultation vide ni une ancienne
 * copie. Le sujet est la consultation ou l'identité du patient affichée dans son en-tête. Les états
 * « prêt » sont rendus par l'écran lui-même.
 */
import { computed } from 'vue'

import type { ApiError } from '@/app/api'
import type { ReadState } from '@/app/services/reads'
import StateMessage, { type ScreenState } from '@/components/feedback/StateMessage.vue'
import AppButton from '@/components/ui/AppButton.vue'

const props = defineProps<{
  state: ReadState
  subject: 'consultation' | 'identity'
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
  const consultation = props.subject === 'consultation'
  switch (props.state) {
    case 'loading':
      return {
        state: 'loading',
        title: consultation
          ? 'Chargement de la consultation…'
          : 'Chargement de l’identité du patient…',
        text: '',
        retry: false,
      }
    case 'offline':
      return consultation
        ? {
            state: 'offline',
            title: 'Consultation indisponible',
            text: 'Le serveur n’a pas répondu : la consultation n’a pas pu être lue. Aucune ancienne copie ni consultation vide n’est présentée.',
            retry: true,
          }
        : {
            state: 'offline',
            title: 'Identité du patient indisponible',
            text: 'Le serveur n’a pas répondu : l’examen ne s’affiche pas tant que le patient n’est pas identifié.',
            retry: true,
          }
    case 'forbidden':
      return {
        state: 'forbidden',
        title: 'Droits insuffisants',
        text: consultation
          ? 'Ce compte n’a pas le droit de consulter les dossiers cliniques.'
          : 'Ce compte n’a pas le droit de lire l’identité du patient : l’examen ne s’affiche pas sans elle.',
        retry: false,
      }
    case 'session-expired':
      return {
        state: 'offline',
        title: 'Session expirée',
        text: 'Reconnectez-vous avec le bouton « Se reconnecter » de l’en-tête, puis réessayez.',
        retry: true,
      }
    case 'error': {
      if (props.error?.status === 404) {
        return consultation
          ? {
              state: 'error',
              title: 'Consultation introuvable',
              text: 'Cette consultation n’existe pas ou n’est plus accessible. Retrouvez l’examen depuis la file du jour.',
              retry: false,
            }
          : {
              state: 'error',
              title: 'Fiche patient introuvable',
              text: 'La fiche de ce patient n’est pas accessible (module Patients fermé, ou fiche absente) : l’examen ne s’affiche pas sans elle.',
              retry: false,
            }
      }
      return {
        state: 'error',
        title: consultation
          ? 'La consultation n’a pas pu être lue'
          : 'L’identité du patient n’a pas pu être lue',
        text: props.error?.message ?? 'Une erreur inattendue est survenue.',
        retry: false,
      }
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
