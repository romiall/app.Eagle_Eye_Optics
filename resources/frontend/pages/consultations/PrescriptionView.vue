<script setup lang="ts">
/**
 * Lecture d'une prescription optique enregistrée (C04) : tableau OD / OG avec unités et signes lisibles, zéro
 * visible (« 0,00 »), valeur non saisie indiquée par un tiret — jamais zéro par défaut. Une prescription brouillon
 * porte BROUILLON ; une version validée est immuable. Les valeurs sont celles du document imprimé
 * (`prescriptionScreenModel` : même adaptateur, mêmes libellés et mêmes mises en forme que le moteur de
 * documents) : l'écran et le document disent la même chose.
 */
import { computed } from 'vue'

import { formatInstantFr } from '@/app/clinicTime'
import { prescriptionScreenModel } from '@/app/services/clinicalDocuments'
import type { Prescription } from '@/domain/contracts/generated'

const props = defineProps<{ prescription: Prescription }>()

const model = computed(() => prescriptionScreenModel(props.prescription))
const validatedAt = computed(() =>
  props.prescription.validated_at === null
    ? null
    : formatInstantFr(props.prescription.validated_at),
)
const MISSING = '—'
</script>

<template>
  <article class="space-y-3 rounded-lg border-2 border-line bg-surface p-4">
    <header class="flex flex-wrap items-center gap-2">
      <h3 class="text-lg font-semibold">Version {{ model.versionNo }}</h3>
      <span
        v-if="model.status !== 'VALIDATED'"
        class="inline-flex items-center gap-1 rounded-lg border-2 border-warning bg-warning-soft px-2 py-1 text-sm font-semibold"
        ><span aria-hidden="true">○</span>BROUILLON</span
      >
      <span
        v-else
        class="inline-flex items-center gap-1 rounded-lg border-2 border-accent bg-accent-soft px-2 py-1 text-sm font-semibold"
        ><span aria-hidden="true">✓</span>Validée<template v-if="validatedAt">
          le {{ validatedAt }}</template
        ></span
      >
    </header>
    <p v-if="model.external" class="text-sm text-muted">
      Ordonnance externe de {{ model.external.prescriber
      }}<template v-if="model.external.prescribedOn"> du {{ model.external.prescribedOn }}</template
      >.
    </p>

    <div class="overflow-x-auto">
      <table class="w-full border-collapse text-left text-base">
        <caption class="sr-only">
          Correction par œil
        </caption>
        <thead>
          <tr class="border-b-2 border-line">
            <th scope="col" class="py-1 pr-2">Œil</th>
            <th scope="col" class="py-1 pr-2">Sphère (D)</th>
            <th scope="col" class="py-1 pr-2">Cylindre (D)</th>
            <th scope="col" class="py-1 pr-2">Axe (°)</th>
            <th scope="col" class="py-1 pr-2">Addition (D)</th>
            <th scope="col" class="py-1">Hauteur</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="eye in model.eyes" :key="eye.eye" class="border-b border-line align-top">
            <th scope="row" class="py-1 pr-2 font-semibold">{{ eye.label }}</th>
            <template v-if="eye.noCorrection">
              <td colspan="5" class="py-1">Aucune correction</td>
            </template>
            <template v-else>
              <td class="py-1 pr-2 tabular-nums">{{ eye.sphere }}</td>
              <td class="py-1 pr-2 tabular-nums">{{ eye.cylinder }}</td>
              <td class="py-1 pr-2 tabular-nums">{{ eye.axis }}</td>
              <td class="py-1 pr-2 tabular-nums">{{ eye.addition }}</td>
              <td class="py-1 tabular-nums">{{ eye.fittingHeight }}</td>
            </template>
          </tr>
        </tbody>
      </table>
    </div>

    <dl class="grid gap-x-4 gap-y-1 text-base sm:grid-cols-[minmax(10rem,auto)_1fr]">
      <dt class="font-semibold">Écart pupillaire total</dt>
      <dd class="tabular-nums">{{ model.pd.total }}</dd>
      <dt class="font-semibold">Écart pupillaire OD</dt>
      <dd class="tabular-nums">{{ model.pd.od }}</dd>
      <dt class="font-semibold">Écart pupillaire OG</dt>
      <dd class="tabular-nums">{{ model.pd.og }}</dd>
      <template v-if="model.pdJustification">
        <dt class="font-semibold">Justification</dt>
        <dd>{{ model.pdJustification }}</dd>
      </template>
      <dt class="font-semibold">Usage</dt>
      <dd>{{ model.usage ?? MISSING }}</dd>
      <dt class="font-semibold">Type de verre</dt>
      <dd>{{ model.lensType ?? MISSING }}</dd>
      <dt class="font-semibold">Indice</dt>
      <dd>{{ model.lensIndex ?? MISSING }}</dd>
      <dt class="font-semibold">Traitements</dt>
      <dd>{{ model.treatments.length > 0 ? model.treatments.join(', ') : MISSING }}</dd>
      <template v-if="model.notes">
        <dt class="font-semibold">Observations</dt>
        <dd class="break-words">{{ model.notes }}</dd>
      </template>
    </dl>
  </article>
</template>
