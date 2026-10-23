<script setup lang="ts">
/**
 * Fusion manuelle de deux fiches d'un même patient (L1D08), réservée à la gérante (patient.merge). La fiche
 * de l'adresse est celle à ABSORBER ; la gérante cherche la fiche à conserver, compare les deux identités
 * côte à côte, voit ce qui est rattaché à la fiche absorbée et les garde-fous, puis confirme avec un motif et
 * son mot de passe (423). Jamais de fusion automatique, jamais de réécriture : visites, examens et pièces
 * gardent leur fiche d'origine et leurs snapshots ; la fiche absorbée pointe vers la fiche conservée.
 *
 * Présentation : en-tête d'écran avec retour à la fiche, puis côte à côte (sur ordinateur) la fiche à
 * absorber et le choix de la fiche à conserver, le tableau comparatif, les chiffres rattachés et la
 * confirmation au pied de l'écran.
 */
import { computed, ref, shallowRef, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import type { MutationOutcome } from '@/app/services/mutations'
import { getPatient, previewPatientMerge, searchPatients } from '@/app/services/patients'
import { useMutation } from '@/app/services/useMutation'
import { revalidateSession } from '@/app/session'
import ConfirmDialog from '@/components/feedback/ConfirmDialog.vue'
import StateMessage from '@/components/feedback/StateMessage.vue'
import ComboboxField, {
  type ComboboxOption,
  type ComboboxSearchResult,
} from '@/components/form/ComboboxField.vue'
import { formatDateFr } from '@/components/form/format'
import TextAreaField from '@/components/form/TextAreaField.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import StatTile from '@/components/layout/StatTile.vue'
import PatientInitials from '@/components/patients/PatientInitials.vue'
import PasswordConfirmDialog from '@/components/session/PasswordConfirmDialog.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { Patient, PatientMergePreview } from '@/domain/contracts/generated'
import { patientDisplayName } from '@/domain/patients/identity'
import {
  checkMergeReason,
  compareIdentities,
  differenceCount,
  MERGE_REASON_MAX,
} from '@/domain/patients/merge'

const REASON_ID = 'fusion-motif'

const route = useRoute()
const router = useRouter()
const sourceId = computed(() => String(route.params['id']))

const source = shallowRef<Patient | null>(null)
const sourceProblem = ref<string | null>(null)
const survivorId = ref<string | null>(null)
const preview = shallowRef<PatientMergePreview | null>(null)
const previewLoading = ref(false)
const previewProblem = ref<string | null>(null)
const reason = ref('')
const reasonError = ref<string | null>(null)
const confirming = ref(false)
const askingPassword = ref(false)
const problem = ref<string | null>(null)

const mutation = useMutation()
const saving = computed(() => mutation.state.value === 'saving')

async function loadSource(): Promise<void> {
  source.value = null
  sourceProblem.value = null
  try {
    const result = await getPatient(sourceId.value)
    if (result.data.id !== sourceId.value) {
      sourceProblem.value = 'Le serveur a répondu avec une autre fiche que celle demandée.'
      return
    }
    source.value = result.data
  } catch {
    sourceProblem.value =
      'La fiche à absorber n’a pas pu être lue (serveur indisponible, droits ou fiche inconnue).'
  }
}

async function loadPreview(): Promise<void> {
  preview.value = null
  previewProblem.value = null
  const into = survivorId.value
  if (into === null) return
  previewLoading.value = true
  try {
    const result = await previewPatientMerge(sourceId.value, into)
    if (result.data.source.id !== sourceId.value || result.data.survivor.id !== into) {
      previewProblem.value = 'Le serveur a répondu pour d’autres fiches : rien n’est affiché.'
      return
    }
    preview.value = result.data
  } catch {
    previewProblem.value =
      'L’aperçu de la fusion n’a pas pu être lu : aucune fusion n’est possible sans lui.'
  } finally {
    previewLoading.value = false
  }
}

watch(sourceId, () => void loadSource(), { immediate: true })
watch([sourceId, survivorId], () => void loadPreview())

/** Recherche de la fiche à conserver : jamais la fiche absorbée, ni une fiche archivée ou fusionnée. */
async function search(query: string): Promise<ComboboxSearchResult> {
  const result = await searchPatients(query)
  const options: ComboboxOption[] = result.data.data
    .filter(
      (summary) =>
        summary.id !== sourceId.value &&
        !summary.archived &&
        summary.merged_into_patient_id === null,
    )
    .map((summary) => ({
      value: summary.id,
      label: patientDisplayName(summary.last_name, summary.first_names),
      description: `Dossier ${summary.record_number}${summary.birth_date ? ` · né(e) le ${formatDateFr(summary.birth_date)}` : ''}`,
    }))
  return result.data.meta.last_page > 1
    ? { options, notice: 'D’autres patients correspondent : précisez la recherche.' }
    : options
}

const rows = computed(() =>
  preview.value === null
    ? []
    : compareIdentities(preview.value.source, preview.value.survivor, formatDateFr),
)
const differences = computed(() => differenceCount(rows.value))
const sourceName = computed(() =>
  source.value === null ? '' : patientDisplayName(source.value.last_name, source.value.first_names),
)
/** Retour à la fiche à absorber (lien au-dessus du titre). */
const back = computed(() => ({
  to: { name: 'patient', params: { id: sourceId.value } },
  label: 'Retour à la fiche',
}))

interface LinkedLine {
  label: string
  count: number
}

const linked = computed<LinkedLine[]>(() => {
  const counts = preview.value?.linked
  if (!counts) return []
  const lines: LinkedLine[] = [{ label: 'Visites', count: counts.visits }]
  if (counts.consultations !== null) lines.push({ label: 'Examens', count: counts.consultations })
  if (counts.prescriptions !== null)
    lines.push({ label: 'Prescriptions optiques', count: counts.prescriptions })
  if (counts.invoices !== null) lines.push({ label: 'Factures émises', count: counts.invoices })
  lines.push({ label: 'Rendez-vous à venir', count: counts.appointments_upcoming })
  return lines
})

function openConfirm(): void {
  problem.value = null
  const checked = checkMergeReason(reason.value)
  reasonError.value = checked.ok ? null : checked.message
  if (!checked.ok) return
  confirming.value = true
}

async function afterOutcome(outcome: MutationOutcome): Promise<void> {
  switch (outcome.kind) {
    case 'synced':
      confirming.value = false
      await router.replace({ name: 'patient', params: { id: survivorId.value ?? sourceId.value } })
      return
    case 'reauthentication-required':
      askingPassword.value = true
      return
    case 'conflict':
    case 'refused':
      confirming.value = false
      problem.value = Object.values(outcome.error.fields).flat()[0] ?? outcome.error.message
      // Une fiche a changé ou un garde-fou est apparu : l'aperçu est relu.
      await loadSource()
      await loadPreview()
      return
    case 'session-expired':
      void revalidateSession().catch(() => undefined)
      return
    default:
      problem.value = mutation.failureMessage.value
      return
  }
}

async function merge(): Promise<void> {
  const checked = checkMergeReason(reason.value)
  if (!checked.ok || source.value === null || survivorId.value === null) return
  mutation.reset()
  await afterOutcome(
    await mutation.submit({
      name: 'MergePatients',
      route: {
        method: 'POST',
        path: `/patients/${encodeURIComponent(sourceId.value)}/merge`,
        expectedVersion: 'entity',
      },
      payload: {
        source_patient_id: sourceId.value,
        survivor_patient_id: survivorId.value,
        reason: checked.reason,
      },
      expectedVersion: source.value.version,
    }),
  )
}

async function onPasswordConfirmed(): Promise<void> {
  askingPassword.value = false
  await afterOutcome(await mutation.retry())
}
</script>

<template>
  <section aria-labelledby="titre-fusion" class="page-stack max-w-5xl">
    <PageHeader
      title-id="titre-fusion"
      title="Fusionner deux fiches"
      icon="merge"
      subtitle="Pour un patient enregistré deux fois. La fiche absorbée garde son numéro et tout son historique : elle renvoie ensuite vers la fiche conservée, qui réunit les deux activités. Rien n’est réécrit, aucune facture émise ne change d’identité."
      :back="back"
    />

    <StateMessage v-if="sourceProblem" state="offline" title="Fiche à absorber non lue">
      {{ sourceProblem }}
    </StateMessage>

    <template v-if="source">
      <StateMessage
        v-if="source.merged_into_patient_id !== null"
        state="error"
        title="Cette fiche est déjà fusionnée"
      >
        <RouterLink
          :to="{ name: 'patient', params: { id: source.merged_into_patient_id } }"
          class="text-link inline-flex min-h-11 items-center font-semibold"
          >Ouvrir la fiche conservée</RouterLink
        >
      </StateMessage>

      <template v-else>
        <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
          <div class="card flex items-center gap-4 border-t-4 border-t-warning p-4 sm:p-5">
            <PatientInitials
              :last-name="source.last_name"
              :first-names="source.first_names"
              size="md"
            />
            <div class="min-w-0">
              <p class="text-sm font-medium text-muted">Fiche à absorber</p>
              <p class="text-lg font-semibold break-words">
                {{ sourceName }}
                <span class="font-normal text-muted">· dossier {{ source.record_number }}</span>
              </p>
            </div>
          </div>

          <div class="card border-t-4 border-t-accent p-4 sm:p-5">
            <ComboboxField
              v-model="survivorId"
              label="Fiche à conserver"
              hint="Nom, téléphone ou numéro de dossier (2 caractères au moins). La fiche à absorber n’est jamais proposée."
              :search="search"
              control-id="fusion-conservee"
              required
              unavailable-text="Recherche serveur indisponible : cela ne prouve pas que la fiche n’existe pas."
            />
          </div>
        </div>

        <StateMessage v-if="previewLoading" state="loading" title="Lecture de l’aperçu…" />
        <StateMessage v-if="previewProblem" state="offline" title="Aperçu non lu">
          {{ previewProblem }}
        </StateMessage>

        <template v-if="preview">
          <div class="space-y-3">
            <div class="data-table-wrap">
              <table class="data-table min-w-[32rem]">
                <caption>
                  Identités des deux fiches côte à côte
                </caption>
                <thead>
                  <tr>
                    <th scope="col">Champ</th>
                    <th scope="col">Fiche absorbée</th>
                    <th scope="col">Fiche conservée</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="row in rows"
                    :key="row.label"
                    :class="row.differs ? 'bg-warning-soft' : ''"
                  >
                    <th scope="row">
                      {{ row.label }}
                      <span v-if="row.differs" class="ml-1 font-semibold text-warning"
                        >· diffère</span
                      >
                    </th>
                    <td class="break-words">{{ row.source }}</td>
                    <td class="break-words">{{ row.survivor }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p
              :class="[
                'flex items-start gap-2 text-sm text-ink',
                differences === 0 ? '' : 'font-medium',
              ]"
              role="status"
            >
              <AppIcon
                :name="differences === 0 ? 'check-circle' : 'alert'"
                :size="18"
                :class="differences === 0 ? 'text-accent' : 'text-warning'"
              />
              <span>
                <template v-if="differences === 0">Les deux identités concordent.</template>
                <template v-else
                  >{{ differences }} champ(s) diffèrent : vérifiez qu’il s’agit bien du même
                  patient. L’identité de la fiche conservée est gardée telle quelle.</template
                >
              </span>
            </p>
          </div>

          <SectionCard
            title="Rattaché à la fiche absorbée"
            title-id="fusion-rattache"
            icon="folder"
            description="Ces éléments restent sur leur fiche d’origine et apparaissent dans l’activité de la fiche conservée."
            flat
          >
            <dl class="stat-grid">
              <StatTile
                v-for="line in linked"
                :key="line.label"
                :label="line.label"
                :value="line.count"
              />
            </dl>
          </SectionCard>

          <StateMessage
            v-if="!preview.can_merge"
            state="error"
            title="Fusion impossible pour l’instant"
          >
            <ul class="list-disc space-y-1 pl-5">
              <li v-for="blocker in preview.blockers" :key="blocker.code">{{ blocker.message }}</li>
            </ul>
          </StateMessage>

          <SectionCard v-else>
            <div class="space-y-4">
              <TextAreaField
                v-model="reason"
                label="Motif de la fusion"
                hint="Par exemple : patient enregistré deux fois à l’accueil. Conservé dans le journal."
                :error="reasonError"
                :maxlength="MERGE_REASON_MAX"
                :rows="3"
                required
                :control-id="REASON_ID"
              />
              <p v-if="problem" role="alert" class="max-w-prose text-sm font-medium text-danger">
                {{ problem }}
              </p>
              <div class="form-actions">
                <AppButton variant="danger" :pending="saving" @click="openConfirm"
                  ><AppIcon name="merge" :size="18" />Fusionner les deux fiches</AppButton
                >
              </div>
            </div>
          </SectionCard>
        </template>
      </template>
    </template>

    <ConfirmDialog
      :open="confirming"
      title="Fusionner définitivement ces deux fiches ?"
      confirm-label="Fusionner"
      cancel-label="Ne pas fusionner"
      tone="danger"
      :pending="saving"
      pending-label="Fusion en cours…"
      @confirm="merge"
      @cancel="confirming = false"
    >
      <p>
        La fiche {{ preview?.source.record_number }} sera absorbée par la fiche
        {{ preview?.survivor.record_number }}. Une fusion ne se défait pas : elle reste tracée dans
        le journal.
      </p>
    </ConfirmDialog>
    <PasswordConfirmDialog
      :open="askingPassword"
      action="La fusion des deux fiches"
      @confirmed="onPasswordConfirmed"
      @cancel="askingPassword = false"
    />
  </section>
</template>
