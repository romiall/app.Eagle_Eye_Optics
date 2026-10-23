<script setup lang="ts">
/**
 * État vide illustré (liste sans élément, premier usage d'un écran) : pastille d'icône dorée sobre,
 * titre, texte et action facultative, centrés dans un cadre en pointillés. Il dit seulement ce qui
 * est su (« Aucun rendez-vous ce jour ») : une lecture en échec ou impossible hors ligne reste un
 * `StateMessage` (« Recherche serveur indisponible », jamais « aucun dossier »). Aucun rôle ARIA :
 * un résultat à annoncer passe par la zone d'annonce de l'écran ou par `StateMessage`. Présentation
 * seule : aucun appel serveur.
 *
 * Fentes : par défaut (texte d'explication), `action` (bouton ou lien vers la suite).
 */
import AppIcon from '@/components/ui/AppIcon.vue'

const props = withDefaults(
  defineProps<{
    title: string
    /** Nom d'une icône d'`AppIcon`, décorative. */
    icon?: string
    /** Élément du titre : `p` par défaut, vrai titre quand l'état vide tient lieu de section. */
    titleAs?: 'p' | 'h2' | 'h3'
    /** Fond crème, pour un état vide posé dans une carte blanche. */
    flat?: boolean
  }>(),
  { icon: 'folder', titleAs: 'p', flat: false },
)
</script>

<template>
  <div
    :class="[
      'flex flex-col items-center gap-3 rounded-xl border-2 border-dashed border-line px-4 py-8 text-center sm:px-8 sm:py-10',
      props.flat ? 'bg-canvas' : 'bg-surface',
    ]"
  >
    <div
      aria-hidden="true"
      class="flex size-14 items-center justify-center rounded-full bg-gold-soft text-gold-ink"
    >
      <AppIcon :name="props.icon" :size="28" />
    </div>
    <component :is="props.titleAs" class="text-lg font-semibold text-ink">
      {{ props.title }}
    </component>
    <div v-if="$slots.default" class="max-w-prose text-base text-muted">
      <slot />
    </div>
    <div v-if="$slots.action" class="mt-1 flex flex-wrap justify-center gap-3">
      <slot name="action" />
    </div>
  </div>
</template>
