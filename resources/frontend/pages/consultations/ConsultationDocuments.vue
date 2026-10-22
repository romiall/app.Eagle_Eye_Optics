<script setup lang="ts">
/**
 * Documents d'un examen TERMINÉ (D02, C04 ; fichier 11) : fiche de consultation (`clinical.export`),
 * ordonnance de médicaments si la rubrique Médicaments est renseignée, lettre d'orientation si la
 * conclusion propose une orientation. Chaque document est préparé à partir de la révision FIGÉE relue sur
 * le serveur au moment de la demande, jamais de la saisie affichée. L'écran ne monte ce composant que
 * pour un examen terminé et hors correction : rien d'imprimable n'est proposé pour un examen en cours.
 * Aucune lecture n'a lieu tant que l'on ne demande pas un aperçu.
 */
import { computed } from 'vue'

import { consultationDocument, consultationPrintables } from '@/app/services/clinicalDocuments'
import type { Consultation, VisualAcuityScale } from '@/domain/contracts/generated'

import ClinicalPrintActions from './ClinicalPrintActions.vue'
import type { PrintAction } from './useClinicalPrint'

const props = defineProps<{
  consultation: Consultation
  scales: readonly VisualAcuityScale[]
}>()

const actions = computed<PrintAction[]>(() =>
  consultationPrintables(props.consultation).map((printable) => ({
    id: printable.kind,
    label: printable.label,
    heading: printable.heading,
    run: () => consultationDocument(printable.kind, props.consultation, props.scales),
  })),
)
</script>

<template>
  <ClinicalPrintActions
    title-id="titre-documents-examen"
    title="Documents de l’examen"
    :actions="actions"
  >
    <p>
      Préparés à partir de la révision {{ props.consultation.current_revision }} figée de l’examen,
      jamais d’une saisie en cours. La prescription optique s’imprime depuis son propre écran.
    </p>
  </ClinicalPrintActions>
</template>
