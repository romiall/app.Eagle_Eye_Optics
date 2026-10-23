<script setup lang="ts">
/**
 * Annonce d'une nouvelle version de l'application (O06) : jamais installée d'office. Le contrôle du poste dit ce qui
 * bloque (pièces en attente que la nouvelle version ne reprendrait plus, serveur injoignable avec des pièces en attente)
 * ou ce qui demande une confirmation (pièces en attente, profil verrouillé, export ancien, stockage presque plein).
 * « Installer » n'est possible que sans blocage ; « Plus tard » masque l'annonce. Rien n'est purgé.
 */
import { computed, ref } from 'vue'

import {
  appUpdateState,
  applyUpdate,
  postponeUpdate,
  refreshUpdateReadiness,
} from '@/app/services/appUpdate'
import AppButton from '@/components/ui/AppButton.vue'

const state = appUpdateState
const confirming = ref(false)
const message = ref<string | null>(null)

const visible = computed(() => state.value.available && !state.value.postponed)
const readiness = computed(() => state.value.readiness)
const blocked = computed(() => readiness.value?.decision === 'BLOCKED')
const blocking = computed(() =>
  (readiness.value?.reasons ?? []).filter((reason) => reason.blocking),
)
const warnings = computed(() =>
  (readiness.value?.reasons ?? []).filter((reason) => !reason.blocking),
)

async function install(): Promise<void> {
  message.value = null
  const outcome = await applyUpdate(confirming.value)
  if (outcome === 'NEEDS_CONFIRMATION') {
    confirming.value = true
    message.value = 'Lisez les points ci-dessous, puis confirmez l’installation.'
  } else if (outcome === 'BLOCKED') {
    message.value =
      'L’installation est bloquée tant que les pièces en attente ne sont pas synchronisées.'
  } else if (outcome === 'UNAVAILABLE') {
    message.value =
      'La nouvelle version n’est pas prête dans ce navigateur : rechargez la page plus tard.'
  }
}
</script>

<template>
  <section
    v-if="visible"
    aria-labelledby="titre-mise-a-jour"
    class="space-y-3 rounded-xl border border-gold bg-gold-soft p-4 text-ink shadow-card"
    role="region"
  >
    <h2 id="titre-mise-a-jour" class="font-semibold">
      Nouvelle version de l’application disponible
    </h2>
    <p v-if="state.checking" class="text-sm">Vérification des pièces de ce poste…</p>
    <template v-else-if="readiness">
      <p v-if="readiness.decision === 'FREE'" class="text-sm">
        Aucune pièce n’attend sur ce poste : vous pouvez installer la nouvelle version.
      </p>
      <ul
        v-if="blocking.length > 0"
        class="list-disc space-y-1 pl-5 text-sm font-medium text-danger"
      >
        <li v-for="reason in blocking" :key="reason.code">{{ reason.message }}</li>
      </ul>
      <ul v-if="warnings.length > 0" class="list-disc space-y-1 pl-5 text-sm">
        <li v-for="reason in warnings" :key="reason.code">{{ reason.message }}</li>
      </ul>
    </template>
    <p v-if="message" role="status" class="text-sm font-medium">{{ message }}</p>
    <div class="flex flex-wrap gap-3">
      <AppButton
        variant="primary"
        :disabled="blocked || state.checking"
        :pending="state.applying"
        pending-label="Installation…"
        @click="install"
        >{{ confirming ? 'Confirmer l’installation' : 'Installer la nouvelle version' }}</AppButton
      >
      <AppButton variant="secondary" :disabled="state.checking" @click="refreshUpdateReadiness()"
        >Vérifier de nouveau</AppButton
      >
      <AppButton variant="ghost" @click="postponeUpdate()">Plus tard</AppButton>
    </div>
  </section>
</template>
