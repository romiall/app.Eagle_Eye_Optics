<script setup lang="ts">
/**
 * Révisions figées d'un devis (L1D07), de la plus récente à la plus ancienne : numéro de révision, date, auteur,
 * motif, total et PDF. Chaque PDF est généré SUR CE POSTE depuis le snapshot figé de SA révision (jamais depuis les
 * prix ou l'identité actuels) : une ancienne révision se réimprime à l'identique. L'original conservé est déposé sur
 * le serveur ; l'état de son document est dit sans jamais inviter à recommencer une émission.
 */
import { ref, shallowRef } from 'vue'

import { formatInstantFr } from '@/app/clinicTime'
import type { RenderedDocument } from '@/app/services/documents'
import { quoteRevisionDocument } from '@/app/services/salesDocuments'
import DocumentPreview from '@/components/documents/DocumentPreview.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import AppButton from '@/components/ui/AppButton.vue'
import type { Quote, QuoteRevision } from '@/domain/contracts/generated'

import { formatMoney } from './format'

const props = defineProps<{ quote: Quote }>()

const rendered = shallowRef<{ revisionNo: number; document: RenderedDocument } | null>(null)
const generating = ref<number | null>(null)
const problem = ref<string | null>(null)

function documentStatus(revision: QuoteRevision): string | null {
  switch (revision.document?.document_status ?? null) {
    case 'READY':
      return 'PDF déposé sur le serveur.'
    case 'FAILED':
      return 'PDF du serveur à régénérer : la révision existe déjà.'
    case 'PENDING':
      return 'PDF du serveur en attente : imprimez la révision pour le déposer.'
    default:
      return null
  }
}

async function print(revision: QuoteRevision): Promise<void> {
  if (generating.value !== null) return
  generating.value = revision.revision_no
  problem.value = null
  try {
    rendered.value = {
      revisionNo: revision.revision_no,
      document: await quoteRevisionDocument(props.quote.id, revision),
    }
  } catch (error) {
    problem.value =
      error instanceof Error
        ? `Le PDF de la révision n’a pas pu être généré : ${error.message}`
        : 'Le PDF de la révision n’a pas pu être généré.'
  } finally {
    generating.value = null
  }
}
</script>

<template>
  <section aria-labelledby="titre-revisions-devis" class="space-y-3">
    <h2 id="titre-revisions-devis" class="text-lg font-semibold">Révisions figées</h2>
    <p v-if="props.quote.revisions.length === 0" class="text-base text-muted">
      Aucune révision : le devis n’est pas encore émis.
    </p>
    <ul v-else class="space-y-3">
      <li
        v-for="revision in props.quote.revisions"
        :key="revision.id"
        class="space-y-1 rounded-xl border border-line bg-surface shadow-card p-3"
      >
        <p class="font-semibold">
          Révision {{ revision.revision_no }}
          <span
            v-if="revision.revision_no === props.quote.current_revision"
            class="font-normal text-muted"
            >(actuelle)</span
          >
        </p>
        <p class="text-sm text-muted">
          {{ formatInstantFr(revision.recorded_at) }} · par
          {{ revision.snapshot.authored_by.display_name }}
        </p>
        <p class="text-base break-words">{{ revision.reason }}</p>
        <p class="text-base">
          Total :
          <span class="tabular-nums">{{ formatMoney(revision.snapshot.total_gross) }}</span> ·
          {{ revision.snapshot.lines.length }} ligne{{
            revision.snapshot.lines.length > 1 ? 's' : ''
          }}
        </p>
        <p v-if="revision.snapshot.clinic.test_document" class="text-sm font-medium">
          <span aria-hidden="true">⚠ </span>Document de test : numéro et mentions de test (données
          fictives).
        </p>
        <p v-if="documentStatus(revision)" class="text-sm text-muted">
          {{ documentStatus(revision) }}
        </p>
        <AppButton
          variant="secondary"
          :pending="generating === revision.revision_no"
          pending-label="Génération du PDF…"
          :accessible-name="`PDF de la révision ${revision.revision_no} du devis ${props.quote.number ?? ''}`"
          @click="print(revision)"
          >{{
            rendered?.revisionNo === revision.revision_no ? 'Réimprimer' : 'Aperçu du devis (PDF)'
          }}</AppButton
        >
      </li>
    </ul>
    <StateMessage v-if="problem" state="error" title="PDF non généré">{{ problem }}</StateMessage>
    <DocumentPreview
      v-if="rendered"
      :document="rendered.document"
      :heading="`Devis ${props.quote.number ?? ''} – révision ${rendered.revisionNo}`"
    />
  </section>
</template>
