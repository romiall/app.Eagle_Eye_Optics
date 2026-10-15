<script setup lang="ts">
/**
 * Enveloppe commune des champs : vrai libellé, aide et erreur reliées au contrôle par
 * aria-describedby, état invalide annoncé (aria-invalid) et jamais signalé par la seule couleur.
 * Le contrôle reçoit ses attributs par la fente : `{ id, describedBy, invalid }`.
 */
import { computed, useId } from 'vue'

const props = defineProps<{
  label: string
  hint?: string | undefined
  error?: string | null | undefined
  required?: boolean | undefined
  /** Libellé visuellement masqué, toujours lu par les lecteurs d'écran. */
  hideLabel?: boolean | undefined
  /** Libellé masqué sur grand écran seulement (tableau optique : l'en-tête de colonne le montre). */
  hideLabelWide?: boolean | undefined
  /** Identifiant imposé du contrôle (cible d'un résumé d'erreurs) ; sinon un identifiant unique. */
  controlId?: string | undefined
}>()

const id = props.controlId ?? useId()
const hintId = `${id}-aide`
const errorId = `${id}-erreur`
const describedBy = computed(
  () =>
    [props.hint ? hintId : null, props.error ? errorId : null].filter(Boolean).join(' ') ||
    undefined,
)
</script>

<template>
  <div class="flex flex-col gap-1">
    <label
      :for="id"
      :class="[
        'text-base font-medium text-ink',
        props.hideLabel ? 'sr-only' : props.hideLabelWide ? 'sm:sr-only' : '',
      ]"
    >
      {{ props.label }}<span v-if="props.required" class="text-danger"> (obligatoire)</span>
    </label>
    <p v-if="props.hint" :id="hintId" class="text-sm text-muted">{{ props.hint }}</p>
    <slot :id="id" :described-by="describedBy" :invalid="Boolean(props.error)" />
    <p v-if="props.error" :id="errorId" class="text-sm font-medium text-danger">
      <span aria-hidden="true">⚠ </span>{{ props.error }}
    </p>
  </div>
</template>
