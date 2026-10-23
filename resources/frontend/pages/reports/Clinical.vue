<script setup lang="ts">
/**
 * Statistiques cliniques (R02, `statistics.clinical` : gérante, professionnel) : consultations de la
 * période, motifs, prescriptions de lunettes, ordonnances de médicaments, orientations par niveau
 * d'urgence et amétropies, lus sur le serveur. Comptages seulement : aucun patient identifiable, aucune
 * mesure additionnée ni moyennée. Une lecture qui échoue est « indisponible », jamais « aucun examen ».
 * Présentation : chiffres clés en tuiles, tableaux de comptage en cartes (deux colonnes sur ordinateur).
 */
import { computed, watch } from 'vue'

import { clinicTimeZone } from '@/app/clinicTime'
import { getClinicalReport } from '@/app/services/reports'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import StatTile from '@/components/layout/StatTile.vue'
import { urgencyLabel } from '@/domain/clinical/labels'
import { termLabel } from '@/domain/clinical/terms'
import type { ClinicalReport } from '@/domain/contracts/generated'
import { CLINICAL_DEFINITIONS } from '@/domain/reports/statistics'
import SalesReadMessage from '@/pages/sales/SalesReadMessage.vue'
import { useServerRead } from '@/pages/today/useServerRead'

import CountTable, { type CountRow } from './CountTable.vue'

const props = defineProps<{ from: string; to: string }>()

const timeZone = clinicTimeZone()
const read = useServerRead<ClinicalReport>(() => getClinicalReport(props.from, props.to))
watch(
  () => `${props.from}\u{1F}${props.to}`,
  () => void read.reload(),
)
const report = computed(() => read.data.value)

const complaints = computed<CountRow[]>(() =>
  report.value === null
    ? []
    : [
        ...report.value.complaints.map((row) => ({
          key: row.term_code,
          label: termLabel('COMPLAINT', row.term_code),
          count: row.count,
        })),
        {
          key: 'none',
          label: 'Motif non renseigné',
          count: report.value.consultations_without_complaint,
        },
      ],
)
const prescriptions = computed<CountRow[]>(() =>
  report.value === null
    ? []
    : [
        {
          key: 'glasses',
          label: 'Avec prescription de lunettes validée',
          count: report.value.prescriptions_validated,
        },
        {
          key: 'medications',
          label: 'Avec ordonnance de médicaments',
          count: report.value.medication_orders,
        },
      ],
)
const referrals = computed<CountRow[]>(() =>
  report.value === null
    ? []
    : [
        ...report.value.referrals_by_urgency.map((row) => ({
          key: row.urgency ?? 'sans-niveau',
          label:
            row.urgency === null
              ? 'Orientation sans niveau d’urgence'
              : `Orientation : ${urgencyLabel(row.urgency)}`,
          count: row.count,
        })),
        {
          key: 'unknown',
          label: 'Orientation non renseignée',
          count: report.value.referral_unknown,
        },
      ],
)
const ametropias = computed<CountRow[]>(() =>
  (report.value?.ametropias ?? []).map((row) => ({
    key: row.term_code,
    label: termLabel('AMETROPIA', row.term_code),
    count: row.count,
  })),
)
</script>

<template>
  <section aria-labelledby="titre-stat-clinique" class="space-y-5">
    <h2 id="titre-stat-clinique" class="section-title text-xl">Examens</h2>

    <SalesReadMessage
      v-if="read.state.value !== 'ready'"
      :state="read.state.value"
      subject="les statistiques des examens"
      :error="read.error.value"
      @retry="read.reload()"
    />

    <template v-if="report">
      <dl class="stat-grid">
        <StatTile
          label="Consultations"
          :value="report.consultations"
          icon="consultations"
          tone="gold"
        />
        <StatTile
          label="Examens terminés"
          :value="report.consultations_completed"
          icon="check-circle"
        />
        <StatTile label="Orientations proposées" :value="report.referrals" icon="send" />
      </dl>

      <div class="grid gap-4 lg:grid-cols-2">
        <CountTable
          caption="Motifs de consultation"
          :rows="complaints"
          :denominator="report.consultations"
          denominator-label="consultations"
          empty-text="Aucune consultation sur la période."
        />
        <CountTable
          caption="Prescriptions et ordonnances"
          :rows="prescriptions"
          :denominator="report.consultations"
          denominator-label="consultations"
        />
        <CountTable
          caption="Orientations vers un ophtalmologiste"
          :rows="referrals"
          :denominator="report.consultations"
          denominator-label="consultations"
        />
        <CountTable
          caption="Amétropies relevées"
          :rows="ametropias"
          :denominator="report.consultations"
          denominator-label="consultations"
          empty-text="Aucune amétropie relevée sur la période."
        />
      </div>

      <SectionCard flat title="Définitions" heading-level="h3" icon="info">
        <ul role="list" class="max-w-prose space-y-2 text-sm text-muted">
          <li v-for="definition in CLINICAL_DEFINITIONS" :key="definition" class="flex gap-2">
            <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-gold"></span>
            <span class="min-w-0">{{ definition }}</span>
          </li>
        </ul>
      </SectionCard>
      <FreshnessNote
        v-if="read.freshness.value"
        :freshness="read.freshness.value"
        :time-zone="timeZone"
      />
    </template>
  </section>
</template>
