<script setup lang="ts">
/**
 * Approbations (B06 ; fichier 04 : « Gestion gérante : approbations, paramètres, audit… »), EN LIGNE,
 * module « settings ». La gérante (`approval.decide`) voit toutes les demandes du cabinet et les décide ;
 * un compte qui demande seulement (`approval.request`) voit ses propres demandes et leur décision (le
 * serveur filtre). « En attente » montre les demandes encore décidables (non échues) ; « Toutes »,
 * l'historique (accordées, refusées, échues, utilisées).
 *
 * « Aucune demande » n'est dit que si le serveur a répondu à CET appel ; sans réponse, l'écran le dit et
 * propose de réessayer. Aucune donnée clinique : identité administrative, pièces, montants, motifs.
 */
import { computed, ref } from 'vue'

import { clinicTimeZone } from '@/app/clinicTime'
import { listApprovals } from '@/app/services/approvals'
import { canOnScreen } from '@/app/session'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import AppButton from '@/components/ui/AppButton.vue'
import PaginationControls from '@/components/ui/PaginationControls.vue'
import type { ApprovalFilter } from '@/domain/approvals/approvals'
import type { ApprovalPage } from '@/domain/contracts/generated'
import SalesReadMessage from '@/pages/sales/SalesReadMessage.vue'
import { useServerRead } from '@/pages/today/useServerRead'

import ApprovalCard from './ApprovalCard.vue'

const timeZone = clinicTimeZone()
const filter = ref<ApprovalFilter>('REQUESTED')
const page = ref(1)
/** Décision enregistrée : dite ici, la relecture remplaçant la carte décidée. */
const notice = ref<string | null>(null)

const read = useServerRead<ApprovalPage>(
  () => listApprovals(filter.value === 'REQUESTED' ? 'REQUESTED' : null, page.value),
  { isEmpty: (result) => result.data.length === 0 },
)

const result = computed(() => read.data.value)
const manager = computed(() => canOnScreen('approval.decide'))
const heading = computed(() => (manager.value ? 'Approbations' : 'Mes demandes d’approbation'))

function choose(next: ApprovalFilter): void {
  if (filter.value === next) return
  notice.value = null
  filter.value = next
  page.value = 1
  void read.reload()
}

function goToPage(next: number): void {
  notice.value = null
  page.value = next
  void read.reload()
}

function decided(message: string | null): void {
  notice.value = message
  void read.reload()
}
</script>

<template>
  <section aria-labelledby="titre-approbations" class="mx-auto max-w-4xl space-y-4">
    <h1 id="titre-approbations" class="text-2xl font-semibold">{{ heading }}</h1>
    <p class="max-w-prose text-base text-muted">
      Une approbation autorise une seule opération précise, une fois, avant son échéance. Si son
      contenu change (montant, lignes, mode), une nouvelle demande est nécessaire.
      <template v-if="manager"> Décider exige de confirmer votre mot de passe. </template>
    </p>

    <div role="group" aria-label="Filtrer les demandes" class="flex flex-wrap gap-2">
      <AppButton
        :variant="filter === 'REQUESTED' ? 'primary' : 'secondary'"
        :pressed="filter === 'REQUESTED'"
        @click="choose('REQUESTED')"
        >En attente</AppButton
      >
      <AppButton
        :variant="filter === 'ALL' ? 'primary' : 'secondary'"
        :pressed="filter === 'ALL'"
        @click="choose('ALL')"
        >Toutes</AppButton
      >
    </div>

    <StateMessage v-if="notice" state="success" title="Décision enregistrée">{{
      notice
    }}</StateMessage>

    <SalesReadMessage
      v-if="read.state.value !== 'ready' && read.state.value !== 'empty'"
      :state="read.state.value"
      :error="read.error.value"
      subject="les demandes d’approbation"
      not-found-title="Approbations indisponibles"
      @retry="read.reload()"
    />

    <StateMessage
      v-else-if="read.state.value === 'empty'"
      state="empty"
      :title="filter === 'REQUESTED' ? 'Aucune demande en attente' : 'Aucune demande'"
    >
      Le serveur n’a {{ filter === 'REQUESTED' ? 'aucune demande en attente' : 'aucune demande' }}
      {{ manager ? 'dans ce cabinet' : 'à votre nom' }}.
    </StateMessage>

    <template v-else-if="result !== null">
      <ul class="space-y-3">
        <li v-for="approval in result.data" :key="approval.id">
          <ApprovalCard :approval="approval" @decided="decided" />
        </li>
      </ul>

      <PaginationControls
        :current-page="result.meta.current_page"
        :last-page="result.meta.last_page"
        :total="result.meta.total"
        label="Pagination des demandes d’approbation"
        unit="demandes"
        @page="goToPage"
      />

      <FreshnessNote
        v-if="read.freshness.value"
        :freshness="read.freshness.value"
        :time-zone="timeZone"
      />
    </template>
  </section>
</template>
