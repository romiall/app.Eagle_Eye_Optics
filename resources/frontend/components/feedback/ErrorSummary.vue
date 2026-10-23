<script setup lang="ts">
/**
 * Résumé des erreurs en haut du formulaire (fichier 04) : focalisé quand de nouvelles erreurs
 * apparaissent, chaque erreur renvoie à son champ ; les saisies valides restent en place. Un
 * formulaire qui échoue deux fois avec les mêmes erreurs appelle `focus()` (exposé) pour que le
 * résumé soit de nouveau annoncé.
 */
import { computed, nextTick, ref, useId, watch } from 'vue'

export interface FieldErrorItem {
  /** Identifiant du contrôle à atteindre. */
  fieldId: string
  message: string
}

const props = withDefaults(defineProps<{ errors: readonly FieldErrorItem[]; title?: string }>(), {
  title: 'Certaines informations sont à corriger',
})

const titleId = `${useId()}-titre`
const box = ref<HTMLElement | null>(null)

const signature = computed(() =>
  props.errors.map((item) => `${item.fieldId}\u{1F}${item.message}`).join('\u{1E}'),
)

async function focus(): Promise<void> {
  await nextTick()
  box.value?.focus()
}

watch(
  signature,
  (current, previous) => {
    if (current !== '' && current !== previous) void focus()
  },
  { immediate: true },
)

function goTo(fieldId: string): void {
  document.getElementById(fieldId)?.focus()
}

defineExpose({ focus })
</script>

<template>
  <div
    v-if="props.errors.length > 0"
    ref="box"
    tabindex="-1"
    role="alert"
    :aria-labelledby="titleId"
    class="rounded-xl border border-l-4 border-danger bg-danger-soft p-4 text-ink shadow-card sm:p-5"
  >
    <h2 :id="titleId" class="text-base font-semibold text-danger">
      <span aria-hidden="true">⚠ </span>{{ props.title }}
    </h2>
    <ul class="mt-2 list-disc space-y-1 pl-5">
      <li v-for="item in props.errors" :key="`${item.fieldId}-${item.message}`">
        <a
          :href="`#${item.fieldId}`"
          class="text-ink underline underline-offset-2"
          @click.prevent="goTo(item.fieldId)"
          >{{ item.message }}</a
        >
      </li>
    </ul>
  </div>
</template>
