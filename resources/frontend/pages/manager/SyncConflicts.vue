<script setup lang="ts">
/**
 * Conflits de synchronisation des postes (O05 ; contrat 23 « Push, ACK et résolution »), gérante, module `offline`.
 * Chaque conflit montre la pièce du poste (nature, poste, rang dans son journal, auteur) et le motif du refus du
 * serveur ; la gérante décide, avec un motif obligatoire et une réauthentification. Une décision ne réécrit jamais la
 * pièce du poste, qui reste conservée ; un reçu imprimé en litige reste une pièce locale.
 */
import { computed, onMounted, ref } from 'vue'

import {
  type ConflictDecision,
  DECISION_LABELS,
  listSyncConflicts,
  resolveSyncConflict,
} from '@/app/services/syncConflicts'
import StateMessage from '@/components/feedback/StateMessage.vue'
import SelectField from '@/components/form/SelectField.vue'
import TextField from '@/components/form/TextField.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import type { Conflict } from '@/domain/contracts/generated'
import { newUlid } from '@/domain/values/ulid'

import { useAdminWrite } from '../settings/useAdminWrite'

const COMMAND_LABELS: Record<string, string> = {
  CreatePatient: 'Création d’un patient',
  UpdatePatient: 'Modification d’un patient',
  CreateInvoiceDraft: 'Brouillon de facture',
  UpdateDraft: 'Modification d’un brouillon',
  IssueInvoice: 'Émission d’une facture',
  RegisterPayment: 'Encaissement',
  OpenCashSession: 'Ouverture de caisse',
}

const writer = useAdminWrite()
const status = ref<'OPEN' | 'RESOLVED'>('OPEN')
const conflicts = ref<Conflict[]>([])
const loading = ref(true)
const problem = ref<string | null>(null)
const openFor = ref<string | null>(null)
const decision = ref<string | null>(null)
const reason = ref('')
const correction = ref('')
const key = ref(newUlid())

const decisionOptions = computed(() =>
  (Object.keys(DECISION_LABELS) as ConflictDecision[]).map((value) => ({
    value,
    label: DECISION_LABELS[value],
  })),
)

function clock(instant: string): string {
  return new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'short',
    timeStyle: 'short',
    timeZone: 'Africa/Douala',
  }).format(new Date(instant))
}

async function load(): Promise<void> {
  loading.value = true
  problem.value = null
  try {
    conflicts.value = (await listSyncConflicts(status.value)).data
  } catch {
    problem.value = 'Les conflits n’ont pas pu être lus : vérifiez la connexion puis réessayez.'
  } finally {
    loading.value = false
  }
}

onMounted(() => void load())

function show(next: 'OPEN' | 'RESOLVED'): void {
  status.value = next
  void load()
}

function begin(conflict: Conflict): void {
  openFor.value = conflict.id
  decision.value = null
  reason.value = ''
  correction.value = ''
  key.value = newUlid()
}

function decide(conflict: Conflict): Promise<void> {
  const chosen = decision.value as ConflictDecision | null
  if (chosen === null || !(chosen in DECISION_LABELS)) {
    problem.value = 'Choisissez une décision.'
    return Promise.resolve()
  }
  const input = {
    decision: chosen,
    reason: reason.value.trim(),
    correction_command_id: chosen === 'CORRECT' ? correction.value.trim() : null,
  }
  return writer.run(
    () => resolveSyncConflict(conflict.id, input, key.value),
    async () => {
      openFor.value = null
      await load()
    },
  )
}
</script>

<template>
  <section aria-labelledby="titre-conflits" class="max-w-3xl space-y-6">
    <h1 id="titre-conflits" class="text-2xl font-semibold tracking-tight">
      Conflits de synchronisation
    </h1>
    <p class="max-w-prose text-muted">
      Pièces enregistrées sur un poste sans Internet que le serveur n’a pas pu appliquer telles
      quelles. Rien n’est écrasé : décidez, motif à l’appui, après avoir vérifié les faits
      (encaissement réellement reçu, pièce imprimée).
    </p>

    <div class="flex flex-wrap gap-3" role="group" aria-label="Conflits affichés">
      <AppButton :variant="status === 'OPEN' ? 'primary' : 'secondary'" @click="show('OPEN')"
        >À revoir</AppButton
      >
      <AppButton
        :variant="status === 'RESOLVED' ? 'primary' : 'secondary'"
        @click="show('RESOLVED')"
        >Résolus</AppButton
      >
    </div>

    <p v-if="problem" role="alert" class="font-medium text-danger">
      <span aria-hidden="true">⚠ </span>{{ problem }}
    </p>
    <p v-if="writer.message.value" role="alert" class="font-medium text-danger">
      <span aria-hidden="true">⚠ </span>{{ writer.message.value }}
    </p>

    <StateMessage v-if="loading" state="loading" title="Lecture des conflits…" />
    <StateMessage
      v-else-if="conflicts.length === 0"
      state="empty"
      :title="status === 'OPEN' ? 'Aucun conflit à revoir' : 'Aucun conflit résolu'"
    />

    <ul v-else class="space-y-3">
      <li
        v-for="conflict in conflicts"
        :key="conflict.id"
        class="space-y-2 rounded-lg border-2 border-line p-3"
      >
        <p class="font-medium">
          <span aria-hidden="true">{{ conflict.status === 'OPEN' ? '⚠' : '✓' }} </span>
          {{ COMMAND_LABELS[conflict.command_name] ?? conflict.command_name }} —
          {{ conflict.device_name }}, n° {{ conflict.device_sequence }} du journal
        </p>
        <p class="text-sm">
          Par {{ conflict.actor_name }} ; conflit ouvert le {{ clock(conflict.created_at) }}.
        </p>
        <p class="text-sm">
          Motif du serveur : {{ conflict.reason ?? conflict.conflict_type }}
          <span class="text-muted">({{ conflict.conflict_type }})</span>
        </p>
        <p v-if="conflict.status === 'RESOLVED'" class="text-sm">
          Décision : {{ conflict.decision === null ? '—' : DECISION_LABELS[conflict.decision] }}
          <template v-if="conflict.resolved_by_name">
            — par {{ conflict.resolved_by_name }}</template
          >
          <template v-if="conflict.resolved_at"> le {{ clock(conflict.resolved_at) }}</template>
          <template v-if="conflict.resolution"> : « {{ conflict.resolution }} »</template>
        </p>

        <template v-if="conflict.status === 'OPEN'">
          <AppButton v-if="openFor !== conflict.id" variant="secondary" @click="begin(conflict)"
            >Décider</AppButton
          >
          <div v-else class="space-y-2">
            <SelectField
              v-model="decision"
              label="Décision"
              placeholder="Choisir une décision…"
              :control-id="`conflit-decision-${conflict.id}`"
              :options="decisionOptions"
            />
            <TextField
              v-model="reason"
              label="Motif (obligatoire, conservé avec la décision)"
              :control-id="`conflit-motif-${conflict.id}`"
              :error="writer.fieldError('reason')"
            />
            <TextField
              v-if="decision === 'CORRECT'"
              v-model="correction"
              label="Identifiant de la commande correctrice"
              :control-id="`conflit-correction-${conflict.id}`"
              :error="writer.fieldError('correction_command_id')"
            />
            <div class="flex flex-wrap gap-3">
              <AppButton
                variant="primary"
                :pending="writer.state.value === 'saving'"
                @click="decide(conflict)"
                >Enregistrer la décision</AppButton
              >
              <AppButton variant="ghost" @click="openFor = null">Annuler</AppButton>
            </div>
          </div>
        </template>
      </li>
    </ul>

    <PasswordConfirmDialog
      :open="writer.state.value === 'reauthentication'"
      action="La décision sur ce conflit"
      @confirmed="writer.resume()"
      @cancel="writer.cancel()"
    />
  </section>
</template>
