<script setup lang="ts">
/**
 * États d'une lecture patient (fichier 04) : chargement, serveur indisponible, droits insuffisants,
 * session expirée, erreur. Le message dit ce qui est su, pas davantage : une recherche qui n'a pas
 * pu interroger le serveur est « Recherche serveur indisponible », jamais « Aucun résultat » ; une
 * fiche inconnue (404) est une erreur de l'application, pas une absence affirmée. Les états
 * « prêt » et « vide » sont rendus par l'écran lui-même.
 */
import { computed } from 'vue'

import type { ApiError } from '@/app/api'
import type { ReadState } from '@/app/services/reads'
import StateMessage, { type ScreenState } from '@/components/feedback/StateMessage.vue'
import AppButton from '@/components/ui/AppButton.vue'

const props = defineProps<{
  state: ReadState
  subject: 'search' | 'record'
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
  const search = props.subject === 'search'
  switch (props.state) {
    case 'loading':
      return {
        state: 'loading',
        title: search ? 'Recherche en cours…' : 'Chargement de la fiche…',
        text: '',
        retry: false,
      }
    case 'offline':
      return search
        ? {
            state: 'offline',
            title: 'Recherche serveur indisponible',
            text: 'Le serveur n’a pas répondu à cette recherche. Cela ne prouve pas qu’aucun patient ne correspond : réessayez avant de créer une nouvelle fiche.',
            retry: true,
          }
        : {
            state: 'offline',
            title: 'Fiche indisponible',
            text: 'Le serveur n’a pas répondu : la fiche n’a pas pu être lue. Aucune ancienne copie n’est présentée comme à jour.',
            retry: true,
          }
    case 'forbidden':
      return {
        state: 'forbidden',
        title: 'Droits insuffisants',
        text: search
          ? 'Ce compte n’a pas le droit de rechercher des patients.'
          : 'Ce compte n’a pas le droit de consulter cette fiche.',
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
      if (!search && props.error?.status === 404) {
        return {
          state: 'error',
          title: 'Fiche introuvable',
          text: 'Ce dossier n’existe pas ou n’est plus accessible. Vérifiez le numéro, ou recherchez le patient dans la liste.',
          retry: false,
        }
      }
      return {
        state: 'error',
        title: search ? 'La recherche a échoué' : 'La fiche n’a pas pu être lue',
        text:
          props.error?.fields['query']?.[0] ??
          props.error?.message ??
          'Une erreur inattendue est survenue.',
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
