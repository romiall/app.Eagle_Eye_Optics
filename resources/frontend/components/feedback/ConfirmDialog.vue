<script setup lang="ts">
/**
 * Confirmation d'une action (fichier 04) : boîte de dialogue modale native (`<dialog>` ouverte par
 * showModal : le reste de la page devient inerte, Échap annule), bouton qui dit la conséquence
 * (« Émettre la facture »), focus initial sur « Annuler » pour une action risquée (ou sur le champ
 * à saisir quand la boîte contient un formulaire : `initialFocus`), focus rendu au déclencheur à la
 * fermeture. Le parent garde l'état ouvert/fermé ; ce composant ne décide rien
 * lui-même. Pendant la commande (`pending`), ni Échap ni « Annuler » n'interrompent l'action.
 */
import { nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'

import AppButton from '@/components/ui/AppButton.vue'

const props = withDefaults(
  defineProps<{
    open: boolean
    title: string
    confirmLabel: string
    cancelLabel?: string
    tone?: 'default' | 'danger'
    pending?: boolean
    /** Libellé pendant la commande (« Émission en cours… »). */
    pendingLabel?: string
    /** Sélecteur CSS d'un élément de la boîte qui reçoit le focus à l'ouverture (champ à saisir). */
    initialFocus?: string | undefined
  }>(),
  {
    cancelLabel: 'Annuler',
    tone: 'default',
    pending: false,
    pendingLabel: 'Traitement en cours…',
    initialFocus: undefined,
  },
)

const emit = defineEmits<{ confirm: []; cancel: [] }>()

const baseId = useId()
const titleId = `${baseId}-titre`
const bodyId = `${baseId}-texte`
const dialog = ref<HTMLDialogElement | null>(null)
const cancelButton = ref<{ $el: HTMLElement } | null>(null)
const confirmButton = ref<{ $el: HTMLElement } | null>(null)
let opener: HTMLElement | null = null

async function show(): Promise<void> {
  opener ??= document.activeElement instanceof HTMLElement ? document.activeElement : null
  const element = dialog.value
  if (element === null) return
  if (!element.open) {
    if (typeof element.showModal === 'function') element.showModal()
    else element.setAttribute('open', '')
  }
  await nextTick()
  const field =
    props.initialFocus === undefined ? null : element.querySelector<HTMLElement>(props.initialFocus)
  if (field !== null) {
    field.focus()
    return
  }
  const first = props.tone === 'danger' ? cancelButton.value : confirmButton.value
  first?.$el.focus()
}

function hide(): void {
  const element = dialog.value
  if (element !== null && element.open) {
    if (typeof element.close === 'function') element.close()
    else element.removeAttribute('open')
  }
  const target = opener
  opener = null
  if (target !== null && target.isConnected) target.focus()
}

watch(
  () => props.open,
  (open) => {
    if (open) void show()
    else hide()
  },
  { flush: 'post' },
)

// Pendant la commande, les boutons sont désactivés : le focus reste dans la boîte (sur elle-même)
// au lieu de tomber hors d'une modale.
watch(
  () => props.pending,
  async (pending) => {
    if (!pending || !props.open) return
    await nextTick()
    const element = dialog.value
    const active = document.activeElement
    if (
      element !== null &&
      (!(active instanceof HTMLElement) ||
        !element.contains(active) ||
        (active as HTMLButtonElement).disabled)
    ) {
      element.focus()
    }
  },
  { flush: 'post' },
)

onMounted(() => {
  if (props.open) void show()
})

onBeforeUnmount(() => {
  if (props.open) hide()
})

function requestCancel(): void {
  if (!props.pending) emit('cancel')
}

/** Échap : la fermeture native est empêchée ; le parent décide via « cancel ». */
function onCancel(event: Event): void {
  event.preventDefault()
  requestCancel()
}

/**
 * Le navigateur peut fermer la boîte sans « cancel » annulable (Échap répété sans geste, règle
 * anti-abus de Chromium) : le parent est alors prévenu pour que son état reste exact. Pendant la
 * commande, la boîte est rouverte : l'action continue et son issue doit rester visible.
 */
function onClose(): void {
  if (!props.open) return
  if (props.pending) void show()
  else emit('cancel')
}
</script>

<template>
  <dialog
    ref="dialog"
    :aria-labelledby="titleId"
    :aria-describedby="bodyId"
    tabindex="-1"
    class="m-auto w-[min(32rem,calc(100%-2rem))] rounded-xl border border-t-4 border-line border-t-gold bg-surface p-0 text-ink shadow-xl backdrop:bg-ink/40"
    @cancel="onCancel"
    @close="onClose"
  >
    <div class="space-y-4 p-5">
      <h2 :id="titleId" class="text-lg font-semibold">{{ props.title }}</h2>
      <div :id="bodyId" class="text-base"><slot /></div>
      <div class="flex flex-wrap justify-end gap-3">
        <AppButton
          ref="cancelButton"
          variant="secondary"
          :disabled="props.pending"
          @click="requestCancel"
        >
          {{ props.cancelLabel }}
        </AppButton>
        <AppButton
          ref="confirmButton"
          :variant="props.tone === 'danger' ? 'danger' : 'primary'"
          :pending="props.pending"
          :pending-label="props.pendingLabel"
          @click="emit('confirm')"
        >
          {{ props.confirmLabel }}
        </AppButton>
      </div>
    </div>
  </dialog>
</template>
