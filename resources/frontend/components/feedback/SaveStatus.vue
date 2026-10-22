<script setup lang="ts">
/**
 * État d'enregistrement d'une saisie (fichier 04) : « Enregistré sur ce poste à 10h42 »,
 * « Synchronisé », « Enregistré sur le serveur à 10h42 », « Échec de l'enregistrement ». Annoncé
 * poliment aux lecteurs d'écran, jamais par la seule couleur. Une saisie n'est dite enregistrée qu'après
 * un enregistrement réussi : ce composant affiche l'état qu'on lui donne, il ne le déduit pas.
 * `saved-server` : la réponse du serveur a confirmé l'enregistrement (écrans en ligne, sans coffre local).
 */
import { computed } from 'vue'

export type SaveState = 'idle' | 'saving' | 'saved-local' | 'saved-server' | 'synced' | 'failed'

const props = withDefaults(
  defineProps<{
    state: SaveState
    /** Instant UTC de l'enregistrement (« 2026-10-15T09:42:00.000Z »). */
    at?: string | null
    timeZone?: string
    message?: string | null
  }>(),
  { at: null, timeZone: 'Africa/Douala', message: null },
)

function clock(instant: string): string {
  const parts = new Intl.DateTimeFormat('fr-FR', {
    timeZone: props.timeZone,
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(instant))
  const read = (type: Intl.DateTimeFormatPartTypes): string =>
    parts.find((part) => part.type === type)?.value ?? '00'
  return `${read('hour')}h${read('minute')}`
}

const view = computed<{ icon: string; text: string; tone: string } | null>(() => {
  switch (props.state) {
    case 'saving':
      return { icon: '…', text: 'Enregistrement en cours…', tone: 'text-muted' }
    case 'saved-local':
      return {
        icon: '✓',
        text: props.at ? `Enregistré sur ce poste à ${clock(props.at)}` : 'Enregistré sur ce poste',
        tone: 'text-ink',
      }
    case 'saved-server':
      return {
        icon: '✓',
        text: props.at
          ? `Enregistré sur le serveur à ${clock(props.at)}`
          : 'Enregistré sur le serveur',
        tone: 'text-accent',
      }
    case 'synced':
      return { icon: '✓', text: 'Synchronisé avec le serveur', tone: 'text-accent' }
    case 'failed':
      return {
        icon: '⚠',
        text: props.message ?? 'Échec de l’enregistrement : vos saisies restent affichées.',
        tone: 'text-danger',
      }
    default:
      return null
  }
})
</script>

<template>
  <p
    role="status"
    aria-live="polite"
    :class="['flex items-center gap-1 text-sm font-medium', view?.tone ?? '']"
  >
    <template v-if="view">
      <span aria-hidden="true">{{ view.icon }}</span
      ><span>{{ view.text }}</span>
    </template>
  </p>
</template>
