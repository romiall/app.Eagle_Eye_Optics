<script setup lang="ts">
/**
 * Personnel du cabinet (F05, écran F09) : comptes nominatifs par ordre alphabétique, 50 par page, avec
 * leurs profils et leur état (actif, désactivé). Un compte n'est jamais supprimé : il est désactivé et
 * reste l'auteur de ses actions passées. Gérante seulement (`users.manage`). Une liste qui n'a pas pu être
 * lue est « indisponible », jamais « aucun compte ». Tableau lisible à 360 px : sur téléphone, deux colonnes
 * (compte avec son identifiant et ses profils, état) ; dès la tablette, identifiant et profils ont chacun la
 * leur.
 */
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'

import { clinicTimeZone } from '@/app/clinicTime'
import { listStaff } from '@/app/services/staff'
import StaffRoleChips from '@/components/admin/StaffRoleChips.vue'
import StaffStateChip from '@/components/admin/StaffStateChip.vue'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import ReadNotice from '@/components/feedback/ReadNotice.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import PaginationControls from '@/components/ui/PaginationControls.vue'
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
  <section aria-labelledby="titre-personnel" class="page-stack max-w-5xl">
    <PageHeader
      title-id="titre-personnel"
      title="Personnel"
      icon="users"
      subtitle="Comptes nominatifs du cabinet. Un compte n’est jamais supprimé : il est désactivé et reste l’auteur de ses actions passées."
      :back="{ to: { name: 'parametres' }, label: 'Paramètres' }"
    >
      <template #actions>
        <LinkButton :to="{ name: 'parametres-personnel-nouveau' }" variant="primary">
          <AppIcon name="user-plus" :size="20" />
          Créer un compte
        </LinkButton>
      </template>
    </PageHeader>

    <StateMessage v-if="flash" state="success" :title="flash" />

    <ReadNotice
      v-if="read.state.value !== 'ready' && read.state.value !== 'empty'"
      :state="read.state.value"
      subject="la liste du personnel"
      :error="read.error.value"
      @retry="read.reload()"
    />

    <div v-else-if="read.state.value === 'empty'" role="status">
      <EmptyState title="Aucun compte" icon="users">
        Le serveur n’a aucun compte pour ce cabinet.
      </EmptyState>
    </div>

    <template v-else-if="result !== null">
      <div class="data-table-wrap">
        <table class="data-table">
          <caption class="sr-only">
            Comptes du personnel, par ordre alphabétique
          </caption>
          <thead>
            <tr>
              <th scope="col">Compte</th>
              <th scope="col" class="hidden sm:table-cell">Identifiant</th>
              <th scope="col" class="hidden sm:table-cell">Profils</th>
              <th scope="col">État</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in result.data" :key="member.id">
              <th scope="row">
                <RouterLink
                  :to="{ name: 'parametres-compte', params: { id: member.id } }"
                  class="text-link inline-flex min-h-11 items-center font-semibold break-words"
                  >{{ member.display_name }}</RouterLink
                >
                <span class="block text-sm font-normal break-all text-muted sm:hidden"
                  >Identifiant : {{ member.login }}</span
                >
                <div class="mt-2 font-normal sm:hidden">
                  <StaffRoleChips :roles="member.roles" />
                </div>
              </th>
              <td class="hidden break-all text-muted sm:table-cell">{{ member.login }}</td>
              <td class="hidden sm:table-cell">
                <StaffRoleChips :roles="member.roles" />
              </td>
              <td>
                <StaffStateChip :active="member.active" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationControls
        :current-page="result.meta.current_page"
        :last-page="result.meta.last_page"
        :total="result.meta.total"
        label="Pagination du personnel"
        unit="comptes"
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
