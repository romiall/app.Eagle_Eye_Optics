<script setup lang="ts">
/**
 * Relevé d'une session de caisse en PDF (B05 ; fichier 03 « relevé de caisse »), produit SUR CE POSTE à
 * partir du relevé lu sur le serveur (`GET /cash-sessions/{id}/report`) : tiroir espèces, mouvements,
 * reçus de la session, autres modes rapprochés à part. Une session ouverte donne un relevé PROVISOIRE.
 * Le relevé n'est pas une pièce numérotée : il est régénéré à chaque demande, jamais « conservé ».
 * La caissière lit le relevé de SA session ; la gérante celui de toute session (le serveur tranche).
 */
import { ref, shallowRef } from 'vue'

import { getCashSessionReport } from '@/app/services/billing'
import type { RenderedDocument } from '@/app/services/documents'
import { cashSessionReportDocument } from '@/app/services/salesDocuments'
import DocumentPreview from '@/components/documents/DocumentPreview.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import AppButton from '@/components/ui/AppButton.vue'

const props = withDefaults(defineProps<{ sessionId: string; label?: string; context?: string }>(), {
  label: 'Relevé de la session (PDF)',
  context: '',
})

const rendered = shallowRef<RenderedDocument | null>(null)
const generating = ref(false)
const problem = ref<string | null>(null)

async function generate(): Promise<void> {
  if (generating.value) return
  generating.value = true
  problem.value = null
  try {
    const report = await getCashSessionReport(props.sessionId)
    rendered.value = await cashSessionReportDocument(report.data)
  } catch (error) {
    rendered.value = null
    problem.value =
      error instanceof Error
        ? `Le relevé n’a pas pu être produit : ${error.message}`
        : 'Le relevé n’a pas pu être produit (serveur indisponible ou refus).'
  } finally {
    generating.value = false
  }
}
</script>

<template>
  <div class="space-y-3">
    <AppButton
      variant="secondary"
      :pending="generating"
      pending-label="Préparation du relevé…"
      :accessible-name="props.context === '' ? props.label : `${props.label} ${props.context}`"
      @click="generate"
      >{{ props.label }}</AppButton
    >
    <StateMessage v-if="problem" state="error" title="Relevé non produit">
      {{ problem }}
    </StateMessage>
    <DocumentPreview
      v-if="rendered"
      :document="rendered"
      :heading="props.context === '' ? 'Relevé de caisse' : `Relevé de caisse ${props.context}`"
    />
  </div>
</template>
