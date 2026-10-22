<script setup lang="ts">
/**
 * Personnel du cabinet (F05, écran F09) : comptes nominatifs par ordre alphabétique, 50 par page, avec
 * leurs profils et leur état (actif, désactivé). Un compte n'est jamais supprimé : il est désactivé et
 * reste l'auteur de ses actions passées. Gérante seulement (`users.manage`). Une liste qui n'a pas pu être
 * lue est « indisponible », jamais « aucun compte ». Cartes plutôt qu'un tableau large (360 px).
 */
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'

import { clinicTimeZone } from '@/app/clinicTime'
import { listStaff } from '@/app/services/staff'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import ReadNotice from '@/components/feedback/ReadNotice.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import PaginationControls from '@/components/ui/PaginationControls.vue'
import { rolesLabel } from '@/domain/access/staff'
import type { StaffMemberPage } from '@/domain/contracts/generated'
import { useServerRead } from '@/pages/today/useServerRead'

import { takeSettingsFlash } from './flash'

const flash = takeSettingsFlash()
const timeZone = clinicTimeZone()
const page = ref(1)

const read = useServerRead<StaffMemberPage>(() => listStaff(page.value), {
  isEmpty: (result) => result.data.length === 0,
})

const result = computed(() => read.data.value)

function goToPage(next: number): void {
  page.value = next
  void read.reload()
}
</script>

<template>
  <section aria-labelledby="titre-personnel" class="max-w-3xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="space-y-1">
        <h1 id="titre-personnel" class="text-2xl font-semibold tracking-tight">Personnel</h1>
        <p class="text-muted">
          <RouterLink :to="{ name: 'parametres' }" class="text-primary underline underline-offset-4"
            >Paramètres</RouterLink
          >
        </p>
      </div>
      <LinkButton :to="{ name: 'parametres-personnel-nouveau' }" variant="primary"
        >Créer un compte</LinkButton
      >
    </div>

    <StateMessage v-if="flash" state="success" :title="flash" />

    <ReadNotice
      v-if="read.state.value !== 'ready' && read.state.value !== 'empty'"
      :state="read.state.value"
      subject="la liste du personnel"
      :error="read.error.value"
      @retry="read.reload()"
    />

    <StateMessage v-else-if="read.state.value === 'empty'" state="empty" title="Aucun compte">
      Le serveur n’a aucun compte pour ce cabinet.
    </StateMessage>

    <template v-else-if="result !== null">
      <ul class="space-y-3">
        <li
          v-for="member in result.data"
          :key="member.id"
          class="space-y-1 rounded-lg border-2 border-line bg-surface p-3"
        >
          <p class="flex flex-wrap items-baseline justify-between gap-x-3">
            <RouterLink
              :to="{ name: 'parametres-compte', params: { id: member.id } }"
              class="inline-flex min-h-11 items-center font-semibold break-words text-primary underline underline-offset-4"
              >{{ member.display_name }}</RouterLink
            >
            <span class="text-base">
              <template v-if="member.active"
                ><span aria-hidden="true">● </span>Actif</template
              >
              <template v-else><span aria-hidden="true">○ </span>Désactivé</template>
            </span>
          </p>
          <p class="text-sm break-words text-muted">Identifiant : {{ member.login }}</p>
          <p class="text-base">
            {{ rolesLabel(member.roles) }}
          </p>
        </li>
      </ul>

      <PaginationControls
        :current-page="result.meta.current_page"
        :last-page="result.meta.last_page"
        :total="result.meta.total"
        label="Pagination du personnel"
        unit="comptes"
        :busy="read.state.value === 'loading'"
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
