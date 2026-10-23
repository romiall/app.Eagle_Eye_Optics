<script setup lang="ts">
/**
 * Lecture d'une prescription optique enregistrée (C04) : tableau OD / OG avec unités et signes lisibles, zéro
 * visible (« 0,00 »), valeur non saisie indiquée par un tiret — jamais zéro par défaut. Une prescription brouillon
 * porte BROUILLON ; une version validée est immuable. Les valeurs sont celles du document imprimé
 * (`prescriptionScreenModel` : même adaptateur, mêmes libellés et mêmes mises en forme que le moteur de
 * documents) : l'écran et le document disent la même chose.
 *
 * Carte de version : titre et puce d'état (« BROUILLON » ou « Validée le … »), tableau de correction
 * OD / OG aux valeurs alignées à droite en chiffres tabulaires, puis écarts pupillaires et verres en
 * liste de détails (deux colonnes dès la tablette).
 */
import { computed } from 'vue'

import { formatInstantFr } from '@/app/clinicTime'
import { prescriptionScreenModel } from '@/app/services/clinicalDocuments'
import StatusChip from '@/components/layout/StatusChip.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
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
  <article class="card space-y-4 p-4 sm:p-5">
    <header class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
      <h3 class="flex items-center gap-2 text-lg font-semibold">
        <AppIcon name="glasses" class="text-gold" />Version {{ model.versionNo }}
      </h3>
      <StatusChip v-if="model.status !== 'VALIDATED'" tone="warning">BROUILLON</StatusChip>
      <StatusChip v-else tone="success" icon="check"
        >Validée<template v-if="validatedAt"> le {{ validatedAt }}</template></StatusChip
      >
    </header>
    <p v-if="model.external" class="text-sm text-muted">
      Ordonnance externe de {{ model.external.prescriber
      }}<template v-if="model.external.prescribedOn"> du {{ model.external.prescribedOn }}</template
      >.
    </p>

    <div class="data-table-wrap shadow-none">
      <table class="data-table">
        <caption class="sr-only">
          Correction par œil
        </caption>
        <thead>
          <tr>
            <th scope="col">Œil</th>
            <th scope="col" class="num">Sphère (D)</th>
            <th scope="col" class="num">Cylindre (D)</th>
            <th scope="col" class="num">Axe (°)</th>
            <th scope="col" class="num">Addition (D)</th>
            <th scope="col" class="num">Hauteur</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="eye in model.eyes" :key="eye.eye">
            <th scope="row" class="font-semibold">{{ eye.label }}</th>
            <template v-if="eye.noCorrection">
              <td colspan="5">Aucune correction</td>
            </template>
            <template v-else>
              <td class="num">{{ eye.sphere }}</td>
              <td class="num">{{ eye.cylinder }}</td>
              <td class="num">{{ eye.axis }}</td>
              <td class="num">{{ eye.addition }}</td>
              <td class="num">{{ eye.fittingHeight }}</td>
            </template>
          </tr>
        </tbody>
      </table>
    </div>

    <dl class="detail-list">
      <div>
        <dt>Écart pupillaire total</dt>
        <dd class="tabular-nums">{{ model.pd.total }}</dd>
      </div>
      <div>
        <dt>Écart pupillaire OD</dt>
        <dd class="tabular-nums">{{ model.pd.od }}</dd>
      </div>
      <div>
        <dt>Écart pupillaire OG</dt>
        <dd class="tabular-nums">{{ model.pd.og }}</dd>
      </div>
      <div v-if="model.pdJustification">
        <dt>Justification</dt>
        <dd>{{ model.pdJustification }}</dd>
      </div>
      <div>
        <dt>Usage</dt>
        <dd>{{ model.usage ?? MISSING }}</dd>
      </div>
      <div>
        <dt>Type de verre</dt>
        <dd>{{ model.lensType ?? MISSING }}</dd>
      </div>
      <div>
        <dt>Indice</dt>
        <dd>{{ model.lensIndex ?? MISSING }}</dd>
      </div>
      <div>
        <dt>Traitements</dt>
        <dd>{{ model.treatments.length > 0 ? model.treatments.join(', ') : MISSING }}</dd>
      </div>
      <div v-if="model.notes" class="sm:col-span-2">
        <dt>Observations</dt>
        <dd class="break-words">{{ model.notes }}</dd>
      </div>
    </dl>
  </article>
</template>
