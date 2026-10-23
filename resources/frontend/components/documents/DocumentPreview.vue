<script setup lang="ts">
/**
 * Aperçu d'un document PDF généré sur ce poste (D01, D02) : les pages dessinées à l'écran d'après le
 * MÊME plan de mise en page que le PDF, une alternative textuelle par page, et les actions « Ouvrir le
 * PDF », « Imprimer » et « Télécharger le PDF ». Aucune logique métier : le composant montre un
 * document déjà construit et déjà généré (`RenderedDocument`) et ne contacte aucun serveur.
 *
 * Impression : la politique de sécurité de l'application n'autorise pas d'intégrer le PDF dans la
 * page. « Imprimer » ouvre donc le PDF dans la visionneuse du navigateur, d'où l'on imprime (Ctrl+P) ;
 * le message le dit. Un navigateur qui bloque l'ouverture reçoit le téléchargement : la pièce n'est
 * jamais perdue.
 */
import { computed, ref } from 'vue'

import {
  downloadRendered,
  openRendered,
  previewPages,
  printRendered,
  type RenderedDocument,
} from '@/app/services/documents'
import AppButton from '@/components/ui/AppButton.vue'

const props = defineProps<{ document: RenderedDocument; heading: string }>()

const pages = computed(() => previewPages(props.document.plan))
const announcement = ref('')
const shortDigest = computed(() => props.document.sha256.slice(0, 12))

function openPdf(): void {
  announcement.value =
    openRendered(props.document) === 'opened'
      ? 'PDF ouvert dans un nouvel onglet.'
      : 'Le navigateur a bloqué l’ouverture : le PDF a été téléchargé.'
}

function printPdf(): void {
  announcement.value =
    printRendered(props.document) === 'opened'
      ? 'PDF ouvert : imprimez-le depuis la visionneuse du navigateur (Ctrl+P).'
      : 'Le navigateur a bloqué l’ouverture : le PDF a été téléchargé, ouvrez-le pour l’imprimer.'
}

function downloadPdf(): void {
  downloadRendered(props.document)
  announcement.value = `PDF téléchargé : ${props.document.fileName}.`
}
</script>

<template>
  <section :aria-label="`Aperçu : ${props.heading}`" class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
      <AppButton @click="openPdf">Ouvrir le PDF</AppButton>
      <AppButton variant="secondary" @click="printPdf">Imprimer</AppButton>
      <AppButton variant="secondary" @click="downloadPdf">Télécharger le PDF</AppButton>
    </div>
    <p role="status" aria-live="polite" class="min-h-6 text-base text-ink">{{ announcement }}</p>

    <ul
      v-if="props.document.warnings.length > 0"
      aria-label="Points à vérifier avant d’imprimer"
      class="space-y-1 rounded-xl border border-warning bg-warning-soft p-3 text-ink"
    >
      <li v-for="warning in props.document.warnings" :key="warning" class="flex gap-2">
        <span aria-hidden="true">⚠</span><span>{{ warning }}</span>
      </li>
    </ul>

    <p class="text-sm text-muted">
      {{ pages.length }} page{{ pages.length > 1 ? 's' : '' }} · fichier
      {{ props.document.fileName }} · empreinte SHA-256 {{ shortDigest }}… · aperçu d’après la mise
      en page du PDF (les polices du PDF peuvent différer légèrement).
    </p>

    <figure v-for="page in pages" :key="page.number" class="space-y-2">
      <div class="overflow-x-auto rounded-xl border border-line bg-surface shadow-card p-2">
        <svg
          :viewBox="`0 0 ${page.widthMm} ${page.heightMm}`"
          role="img"
          :aria-label="`Page ${page.number} sur ${pages.length} : ${props.heading}`"
          class="mx-auto block h-auto w-full min-w-[34rem] max-w-3xl bg-surface"
          font-family="Helvetica, Arial, sans-serif"
        >
          <template v-for="shape in page.shapes" :key="shape.key">
            <text
              v-if="shape.kind === 'text'"
              :x="shape.x"
              :y="shape.y"
              :font-size="shape.fontSize"
              :font-weight="shape.bold ? 'bold' : 'normal'"
              :font-style="shape.italic ? 'italic' : 'normal'"
              :fill="shape.fill"
              :transform="shape.transform ?? undefined"
              v-text="shape.text"
            />
            <line
              v-else-if="shape.kind === 'line'"
              :x1="shape.x1"
              :y1="shape.y1"
              :x2="shape.x2"
              :y2="shape.y2"
              :stroke="shape.stroke"
              :stroke-width="shape.width"
              :stroke-dasharray="shape.dash ?? undefined"
            />
            <rect
              v-else-if="shape.kind === 'rect'"
              :x="shape.x"
              :y="shape.y"
              :width="shape.w"
              :height="shape.h"
              :fill="shape.fill"
              :stroke="shape.stroke"
              :stroke-width="shape.width"
              :stroke-dasharray="shape.dash ?? undefined"
            />
            <image
              v-else
              :x="shape.x"
              :y="shape.y"
              :width="shape.w"
              :height="shape.h"
              :href="shape.href"
            />
          </template>
        </svg>
      </div>
      <figcaption class="text-sm text-muted">
        Page {{ page.number }} sur {{ pages.length }}
      </figcaption>
      <details class="rounded-xl border border-line bg-surface shadow-card p-3">
        <summary class="flex min-h-11 cursor-pointer items-center font-semibold text-primary">
          Lire le contenu de la page {{ page.number }} en texte
        </summary>
        <ol class="mt-2 space-y-1 text-base text-ink">
          <li v-for="(line, index) in page.lines" :key="index">{{ line }}</li>
        </ol>
      </details>
    </figure>
  </section>
</template>
