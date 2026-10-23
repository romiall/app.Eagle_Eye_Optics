<script setup lang="ts">
/**
 * Une demande d'approbation dans la liste de la gérante (B06) : nature, cible (patient, facture, visite,
 * paiement), contenu exact VÉRIFIÉ par le serveur, motif, demandeuse, échéance, décision. La gérante
 * accorde ou refuse (un refus exige un motif) ; la décision exige une réauthentification récente : sur 423,
 * la confirmation du mot de passe s'ouvre, puis la MÊME commande est reprise avec la même clé
 * d'idempotence (jamais deux décisions). Une demande ne se décide pas par la personne qui l'a faite, ni
 * après son échéance : le serveur revérifie tout.
 */
import { computed, ref } from 'vue'

import { formatInstantFr } from '@/app/clinicTime'
import type { MutationOutcome } from '@/app/services/mutations'
import { useMutation } from '@/app/services/useMutation'
import { canOnScreen, knownAccountId, revalidateSession } from '@/app/session'
import TextAreaField from '@/components/form/TextAreaField.vue'
import StatusChip, { type ChipTone } from '@/components/layout/StatusChip.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import {
  buildDecision,
  DECISION_REASON_MAX_LENGTH,
  describeSubject,
  isDecidable,
  kindLabel,
  statusLabel,
} from '@/domain/approvals/approvals'
import type { Approval, ApprovalsStatus } from '@/domain/contracts/generated'
import { patientDisplayName } from '@/domain/patients/identity'
import { formatMoney, paymentMethodLabel } from '@/pages/sales/format'

const props = defineProps<{ approval: Approval }>()

/**
 * `decided` : la liste est à relire. Le message de réussite est rendu par la page : la relecture
 * remplace la liste (et cette carte) ; une demande décidée sort d'ailleurs de « En attente ».
 * `stale` : refus d'état (409 : demande échue, déjà décidée ou utilisée) ; la page DIT ce refus puis relit la
 * liste, car la relecture recrée cette carte et effacerait un message tenu ici.
 */
const emit = defineEmits<{ decided: [notice: string | null]; stale: [message: string] }>()

const mutation = useMutation()
const reason = ref('')
const error = ref<string | null>(null)
const askingPassword = ref(false)
const choice = ref<'APPROVED' | 'REFUSED' | null>(null)

const fieldId = computed(() => `decision-${props.approval.id}`)
const saving = computed(() => mutation.state.value === 'saving')
const own = computed(() => props.approval.requested_by === knownAccountId())
const canDecide = computed(
  () => canOnScreen('approval.decide') && isDecidable(props.approval) && !own.value,
)

const subjectLines = computed(() =>
  describeSubject(props.approval.subject, formatMoney, paymentMethodLabel),
)

/**
 * Puce du statut effectif : teinte et icône accompagnent le libellé du domaine (la couleur n'est
 * jamais le seul signal ; l'icône tient lieu du symbole écrit). Un statut inconnu du contrat garde
 * la puce neutre à point (valeurs par défaut de `StatusChip`) et le libellé « Statut inconnu ».
 */
const STATUS_TONES: Readonly<Record<ApprovalsStatus, ChipTone>> = {
  REQUESTED: 'warning',
  APPROVED: 'success',
  REFUSED: 'danger',
  EXPIRED: 'neutral',
  CONSUMED: 'info',
}
const STATUS_ICONS: Readonly<Record<ApprovalsStatus, string>> = {
  REQUESTED: 'clock',
  APPROVED: 'check',
  REFUSED: 'x',
  EXPIRED: 'history',
  CONSUMED: 'check-circle',
}

const patient = computed(() => {
  const target = props.approval.target.patient
  return target === null
    ? null
    : `${patientDisplayName(target.last_name, target.first_names)} (dossier ${target.record_number})`
})

async function afterOutcome(outcome: MutationOutcome): Promise<void> {
  switch (outcome.kind) {
    case 'synced':
      reason.value = ''
      mutation.reset()
      emit(
        'decided',
        choice.value === 'APPROVED'
          ? 'Approbation accordée et enregistrée sur le serveur.'
          : 'Refus enregistré sur le serveur.',
      )
      return
    case 'reauthentication-required':
      askingPassword.value = true
      return
    case 'refused':
    case 'conflict':
      if (outcome.error.status === 409) {
        emit('stale', `${kindLabel(props.approval.kind)} : ${outcome.error.message}`)
        return
      }
      error.value = outcome.error.fields['reason']?.[0] ?? outcome.error.message
      return
    case 'session-expired':
      void revalidateSession().catch(() => undefined)
      error.value = mutation.failureMessage.value
      return
    default:
      error.value = mutation.failureMessage.value
  }
}

async function decide(decision: 'APPROVED' | 'REFUSED'): Promise<void> {
  if (saving.value) return
  const checked = buildDecision(props.approval.id, decision, reason.value)
  if (!checked.ok) {
    error.value = checked.error
    document.getElementById(fieldId.value)?.focus()
    return
  }
  error.value = null
  choice.value = decision
  await afterOutcome(
    await mutation.submit({
      name: 'DecideApproval',
      path: { id: props.approval.id },
      payload: checked.payload,
    }),
  )
}

async function passwordConfirmed(): Promise<void> {
  askingPassword.value = false
  await afterOutcome(await mutation.retry())
}
</script>

<template>
  <article class="card space-y-4 p-4 sm:p-6">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div class="flex min-w-0 items-start gap-3">
        <span
          aria-hidden="true"
          class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-gold-soft text-gold-ink"
        >
          <AppIcon name="shield" :size="18" />
        </span>
        <h3 class="pt-1 text-lg font-semibold break-words">
          {{ kindLabel(props.approval.kind) }}
        </h3>
      </div>
      <StatusChip
        as="p"
        :tone="STATUS_TONES[props.approval.effective_status]"
        :icon="STATUS_ICONS[props.approval.effective_status]"
        class="self-start sm:shrink-0"
        ><span class="sr-only">Statut :</span>
        {{ statusLabel(props.approval.effective_status) }}</StatusChip
      >
    </header>

    <dl class="detail-list border-t border-line pt-4">
      <div v-if="patient">
        <dt>Patient</dt>
        <dd class="break-words">{{ patient }}</dd>
      </div>
      <div v-if="props.approval.target.invoice_number">
        <dt>Facture</dt>
        <dd>{{ props.approval.target.invoice_number }}</dd>
      </div>
      <div v-if="props.approval.target.visit_occurred_at">
        <dt>Visite</dt>
        <dd>Arrivée le {{ formatInstantFr(props.approval.target.visit_occurred_at) }}</dd>
      </div>
      <div v-if="props.approval.target.payment">
        <dt>Paiement visé</dt>
        <dd>
          {{ formatMoney(props.approval.target.payment.amount) }},
          {{ paymentMethodLabel(props.approval.target.payment.method) }}, le
          {{ formatInstantFr(props.approval.target.payment.effective_at) }}
        </dd>
      </div>
      <div v-for="line in subjectLines" :key="line.label">
        <dt>{{ line.label }}</dt>
        <dd class="break-words">{{ line.value }}</dd>
      </div>
      <div class="col-span-full">
        <dt>Motif</dt>
        <dd class="break-words whitespace-pre-line">{{ props.approval.reason }}</dd>
      </div>
      <div>
        <dt>Demandée par</dt>
        <dd>
          {{ props.approval.requested_by_name }}, le
          {{ formatInstantFr(props.approval.requested_at) }}
        </dd>
      </div>
      <div v-if="props.approval.expires_at">
        <dt>Échéance</dt>
        <dd>{{ formatInstantFr(props.approval.expires_at) }}</dd>
      </div>
      <div v-if="props.approval.decided_at">
        <dt>Décision</dt>
        <dd>
          {{ props.approval.decided_by_name ?? 'Compte inconnu' }}, le
          {{ formatInstantFr(props.approval.decided_at) }}
        </dd>
      </div>
      <div v-if="props.approval.decision_reason" class="col-span-full">
        <dt>Motif de la décision</dt>
        <dd class="break-words whitespace-pre-line">{{ props.approval.decision_reason }}</dd>
      </div>
    </dl>

    <p
      v-if="own && isDecidable(props.approval)"
      class="flex items-start gap-2 rounded-lg border border-line bg-canvas p-3 text-sm text-muted"
    >
      <AppIcon name="info" :size="16" class="mt-0.5 text-gold" />
      <span>
        Vous avez fait cette demande : une autre personne habilitée la décide. Pour agir vous-même,
        exécutez directement l’opération.
      </span>
    </p>

    <form v-if="canDecide" novalidate class="space-y-4 border-t border-line pt-4" @submit.prevent>
      <TextAreaField
        v-model="reason"
        :control-id="fieldId"
        label="Motif de la décision (obligatoire pour un refus)"
        :maxlength="DECISION_REASON_MAX_LENGTH"
        :error="error"
      />
      <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
        <AppButton
          variant="primary"
          :pending="saving && choice === 'APPROVED'"
          :disabled="saving"
          pending-label="Accord en cours…"
          :accessible-name="`Accorder : ${kindLabel(props.approval.kind)}`"
          @click="decide('APPROVED')"
        >
          <AppIcon name="check" :size="18" />
          Accorder
        </AppButton>
        <AppButton
          variant="danger"
          :pending="saving && choice === 'REFUSED'"
          :disabled="saving"
          pending-label="Refus en cours…"
          :accessible-name="`Refuser : ${kindLabel(props.approval.kind)}`"
          @click="decide('REFUSED')"
        >
          <AppIcon name="x" :size="18" />
          Refuser
        </AppButton>
      </div>
    </form>
    <p
      v-else-if="error"
      role="alert"
      class="flex items-start gap-2 text-sm font-medium text-danger"
    >
      <AppIcon name="alert" :size="16" class="mt-0.5" />
      <span>{{ error }}</span>
    </p>

    <PasswordConfirmDialog
      :open="askingPassword"
      action="La décision d’une approbation"
      @confirmed="passwordConfirmed"
      @cancel="askingPassword = false"
    />
  </article>
</template>
