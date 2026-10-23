<script setup lang="ts">
/**
 * Fiche d'un compte du personnel (F05 côté serveur, écran F09) : identité du compte, profils, droits
 * effectifs (profils et dérogations, tels que le serveur les calcule), puis modification, dérogation et
 * réinitialisation du mot de passe. La gérante ne se modifie ni profils ni activation, ne se déroge pas et ne
 * réinitialise pas son propre mot de passe ici ; un compte gérante ne se réinitialise pas depuis l'écran
 * (procédure de secours sur le serveur). L'écran ne propose que ce que le serveur accepte, et le dit.
 * Présentation : une carte de synthèse, puis une carte par opération, dans l'ordre de lecture.
 */
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import { clinicTimeZone } from '@/app/clinicTime'
import { getStaffMember } from '@/app/services/staff'
import { session } from '@/app/session'
import StaffRoleChips from '@/components/admin/StaffRoleChips.vue'
import StaffStateChip from '@/components/admin/StaffStateChip.vue'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import ReadNotice from '@/components/feedback/ReadNotice.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { permissionLabel } from '@/domain/access/staff'
import type { StaffMember } from '@/domain/contracts/generated'
import { useServerRead } from '@/pages/today/useServerRead'

import { takeSettingsFlash } from './flash'
import StaffOverrideForm from './StaffOverrideForm.vue'
import StaffPasswordReset from './StaffPasswordReset.vue'
import StaffProfileForm from './StaffProfileForm.vue'

const route = useRoute()
const id = String(route.params['id'] ?? '')
const timeZone = clinicTimeZone()
const flash = takeSettingsFlash()

const read = useServerRead<StaffMember>(() => getStaffMember(id))
const member = computed(() => read.data.value)

const self = computed(() => member.value !== null && member.value.id === session.account?.id)
const isManager = computed(
  () => member.value !== null && (member.value.roles as readonly string[]).includes('MANAGER'),
)

function updated(next: StaffMember): void {
  read.data.value = next
}
</script>

<template>
  <section aria-labelledby="titre-compte" class="page-stack max-w-3xl">
    <PageHeader
      title-id="titre-compte"
      :title="member?.display_name ?? 'Compte du personnel'"
      icon="user"
      :back="{ to: { name: 'parametres-personnel' }, label: 'Personnel' }"
    />

    <StateMessage v-if="flash" state="success" :title="flash" />

    <ReadNotice
      v-if="member === null || (read.state.value !== 'ready' && read.state.value !== 'loading')"
      :state="read.state.value"
      subject="ce compte"
      :error="read.error.value"
      not-found-title="Compte introuvable"
      @retry="read.reload()"
    />

    <template v-if="member !== null">
      <SectionCard>
        <dl class="detail-list">
          <div>
            <dt>Identifiant</dt>
            <dd class="break-all">{{ member.login }}</dd>
          </div>
          <div>
            <dt>Adresse électronique</dt>
            <dd class="break-all">{{ member.email ?? 'Non renseignée' }}</dd>
          </div>
          <div>
            <dt>Profils</dt>
            <dd class="pt-1">
              <StaffRoleChips :roles="member.roles" />
            </dd>
          </div>
          <div>
            <dt>État</dt>
            <dd class="pt-1">
              <StaffStateChip :active="member.active" />
            </dd>
          </div>
        </dl>

        <details class="group mt-6 rounded-xl border border-line bg-canvas">
          <summary
            class="flex min-h-11 cursor-pointer list-none items-center gap-3 rounded-xl px-4 py-2 font-medium hover:bg-primary-soft"
          >
            <AppIcon name="key" :size="18" class="text-gold" />
            <span class="min-w-0 flex-1">Droits effectifs ({{ member.permissions.length }})</span>
            <AppIcon name="chevron-down" :size="18" class="text-muted group-open:rotate-180" />
          </summary>
          <div class="space-y-3 border-t border-line px-4 pt-3 pb-4">
            <p class="text-sm text-muted">
              Calculés par le serveur à partir des profils et des dérogations en cours.
            </p>
            <ul role="list" class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
              <li v-for="code in member.permissions" :key="code" class="flex min-w-0 gap-2">
                <span
                  aria-hidden="true"
                  class="mt-2.5 size-1.5 shrink-0 rounded-full bg-gold"
                ></span>
                <span class="min-w-0 break-words">
                  {{ permissionLabel(code) }} <span class="text-sm text-muted">({{ code }})</span>
                </span>
              </li>
            </ul>
          </div>
        </details>
      </SectionCard>

      <FreshnessNote
        v-if="read.freshness.value"
        :freshness="read.freshness.value"
        :time-zone="timeZone"
      />

      <StaffProfileForm :member="member" :self="self" @updated="updated" />

      <StaffOverrideForm v-if="!self" :member="member" @updated="updated" />
      <p
        v-else
        class="flex max-w-prose items-start gap-3 rounded-xl border border-line bg-surface p-4 text-muted"
      >
        <AppIcon name="info" :size="18" class="mt-1 text-gold" />
        <span>Vous ne vous appliquez pas de dérogation : une autre gérante le fait.</span>
      </p>

      <StaffPasswordReset v-if="!self && !isManager" :member="member" @reset="read.reload()" />
      <p
        v-else-if="self"
        class="flex max-w-prose items-start gap-3 rounded-xl border border-line bg-surface p-4 text-muted"
      >
        <AppIcon name="key" :size="18" class="mt-1 text-gold" />
        <span>
          Votre propre mot de passe se change avec votre mot de passe actuel :
          <RouterLink :to="{ name: 'mot-de-passe' }" class="text-link font-medium"
            >Changer mon mot de passe</RouterLink
          >.
        </span>
      </p>
      <p
        v-else
        class="flex max-w-prose items-start gap-3 rounded-xl border border-line bg-surface p-4 text-muted"
      >
        <AppIcon name="lock" :size="18" class="mt-1 text-gold" />
        <span>
          Le mot de passe d’une gérante ne se réinitialise pas depuis l’application : la procédure
          de secours se fait sur le serveur (voir le guide d’exploitation).
        </span>
      </p>
    </template>
  </section>
</template>
