<script setup lang="ts">
/**
 * Fiche d'un compte du personnel (F05 côté serveur, écran F09) : identité du compte, profils, droits
 * effectifs (profils et dérogations, tels que le serveur les calcule), puis modification, dérogation et
 * réinitialisation du mot de passe. La gérante ne se modifie ni profils ni activation, ne se déroge pas et ne
 * réinitialise pas son propre mot de passe ici ; un compte gérante ne se réinitialise pas depuis l'écran
 * (procédure de secours sur le serveur). L'écran ne propose que ce que le serveur accepte, et le dit.
 */
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import { clinicTimeZone } from '@/app/clinicTime'
import { getStaffMember } from '@/app/services/staff'
import { session } from '@/app/session'
import FreshnessNote from '@/components/feedback/FreshnessNote.vue'
import ReadNotice from '@/components/feedback/ReadNotice.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import { permissionLabel, rolesLabel } from '@/domain/access/staff'
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
  <section aria-labelledby="titre-compte" class="max-w-2xl space-y-8">
    <div class="space-y-1">
      <h1 id="titre-compte" class="text-2xl font-semibold tracking-tight break-words">
        {{ member?.display_name ?? 'Compte du personnel' }}
      </h1>
      <p class="text-muted">
        <RouterLink
          :to="{ name: 'parametres-personnel' }"
          class="text-primary underline underline-offset-4"
          >Personnel</RouterLink
        >
      </p>
    </div>

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
      <dl class="grid gap-x-4 gap-y-2 sm:grid-cols-[auto_1fr]">
        <dt class="font-medium">Identifiant</dt>
        <dd class="break-all">{{ member.login }}</dd>
        <dt class="font-medium">Adresse électronique</dt>
        <dd class="break-all">{{ member.email ?? 'Non renseignée' }}</dd>
        <dt class="font-medium">Profils</dt>
        <dd>
          {{ rolesLabel(member.roles) }}
        </dd>
        <dt class="font-medium">État</dt>
        <dd>
          <template v-if="member.active"><span aria-hidden="true">● </span>Actif</template>
          <template v-else><span aria-hidden="true">○ </span>Désactivé</template>
        </dd>
      </dl>

      <details class="rounded-lg border-2 border-line bg-surface p-3">
        <summary class="min-h-11 cursor-pointer py-2 font-medium">
          Droits effectifs ({{ member.permissions.length }})
        </summary>
        <p class="mb-2 text-sm text-muted">
          Calculés par le serveur à partir des profils et des dérogations en cours.
        </p>
        <ul class="list-disc space-y-1 pl-5">
          <li v-for="code in member.permissions" :key="code">
            {{ permissionLabel(code) }} <span class="text-sm text-muted">({{ code }})</span>
          </li>
        </ul>
      </details>

      <FreshnessNote
        v-if="read.freshness.value"
        :freshness="read.freshness.value"
        :time-zone="timeZone"
      />

      <StaffProfileForm :member="member" :self="self" @updated="updated" />

      <StaffOverrideForm v-if="!self" :member="member" @updated="updated" />
      <p v-else class="max-w-prose text-muted">
        Vous ne vous appliquez pas de dérogation : une autre gérante le fait.
      </p>

      <StaffPasswordReset v-if="!self && !isManager" :member="member" @reset="read.reload()" />
      <p v-else-if="self" class="max-w-prose text-muted">
        Votre propre mot de passe se change avec votre mot de passe actuel :
        <RouterLink :to="{ name: 'mot-de-passe' }" class="text-primary underline underline-offset-4"
          >Changer mon mot de passe</RouterLink
        >.
      </p>
      <p v-else class="max-w-prose text-muted">
        Le mot de passe d’une gérante ne se réinitialise pas depuis l’application : la procédure de
        secours se fait sur le serveur (voir le guide d’exploitation).
      </p>
    </template>
  </section>
</template>
