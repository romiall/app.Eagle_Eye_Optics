<script setup lang="ts">
/**
 * Aperçu des documents (D01, D02) : écran de contrôle visuel des modèles de PDF, avec des données
 * FICTIVES de démonstration. Il n'écrit rien, n'envoie rien et n'invente aucun état : le PDF est
 * généré sur ce poste, sans réseau, puis montré page par page. Les modèles réels (reçu, facture,
 * prescription) s'ouvriront depuis leurs propres écrans avec le même composant d'aperçu.
 *
 * Droits : seuls les documents que le compte a le droit d'ouvrir sont proposés (un document clinique
 * demande le droit clinique, un reçu ne l'ouvre jamais). Aucune règle métier ici.
 */
import { computed, onMounted, ref, shallowRef, watch } from 'vue'

import {
  DEMO_DOCUMENTS,
  demoDocumentById,
  mayOpen,
  renderDocument,
  type RenderedDocument,
} from '@/app/services/documents'
import DocumentPreview from '@/components/documents/DocumentPreview.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import SelectField from '@/components/form/SelectField.vue'
import { DocumentDataError } from '@/documents/engine/errors'

type Status = 'idle' | 'loading' | 'ready' | 'error'

const available = computed(() => DEMO_DOCUMENTS.filter((entry) => mayOpen(entry.kind)))
const options = computed(() => available.value.map((entry) => ({ value: entry.id, label: entry.label })))
const selected = ref<string | null>(null)
const status = ref<Status>('idle')
const rendered = shallowRef<RenderedDocument | null>(null)
const failure = ref('')
const heading = computed(() => (selected.value === null ? '' : (demoDocumentById(selected.value)?.label ?? '')))
let generation = 0

async function generate(id: string | null): Promise<void> {
  generation += 1
  const current = generation
  rendered.value = null
  failure.value = ''
  const entry = id === null ? undefined : demoDocumentById(id)
  if (entry === undefined || !mayOpen(entry.kind)) {
    status.value = 'idle'
    return
  }
  status.value = 'loading'
  try {
    const result = await renderDocument(entry.build())
    if (current !== generation) return
    rendered.value = result
    status.value = 'ready'
  } catch (error) {
    if (current !== generation) return
    // Le message d'une erreur de données ne contient aucune donnée personnelle ; toute autre erreur
    // reste générique (jamais un détail technique ou une valeur du document à l'écran).
    failure.value =
      error instanceof DocumentDataError
        ? error.message
        : 'Le PDF n’a pas pu être généré sur ce poste.'
    status.value = 'error'
  }
}

watch(selected, (id) => {
  void generate(id)
})

onMounted(() => {
  selected.value = options.value[0]?.value ?? null
})
</script>

<template>
  <section aria-labelledby="titre-apercu-documents" class="space-y-6">
    <h1 id="titre-apercu-documents" class="text-2xl font-semibold tracking-tight">
      Aperçu des documents
    </h1>

    <p class="max-w-prose text-muted">
      <span aria-hidden="true">⚠ </span>Données fictives de démonstration : aucun patient réel. Le PDF
      est généré sur ce poste, sans réseau ; rien n’est envoyé ni imprimé automatiquement. Les pièces
      ne sont conservées nulle part tant que le coffre chiffré du poste n’est pas livré.
    </p>

    <StateMessage
      v-if="options.length === 0"
      state="forbidden"
      title="Aucun document de démonstration n’est accessible avec ces droits"
    >
      Un reçu ou une facture demande un droit de caisse ; une prescription demande le droit clinique.
    </StateMessage>

    <template v-else>
      <div class="max-w-xl">
        <SelectField
          v-model="selected"
          label="Document à afficher"
          :options="options"
          placeholder="Choisir un document…"
          control-id="apercu-document"
        />
      </div>

      <StateMessage v-if="status === 'loading'" state="loading" title="Génération du PDF…" />
      <StateMessage
        v-else-if="status === 'error'"
        state="error"
        title="Le document n’a pas pu être généré"
      >
        {{ failure }}
      </StateMessage>
      <StateMessage
        v-else-if="status === 'idle'"
        state="empty"
        title="Choisissez un document pour l’afficher"
      />
      <DocumentPreview v-else-if="rendered !== null" :document="rendered" :heading="heading" />
    </template>
  </section>
</template>
