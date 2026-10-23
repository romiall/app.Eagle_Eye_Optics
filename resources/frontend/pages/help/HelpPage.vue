<script setup lang="ts">
/**
 * Aide intégrée : ce que ce compte peut faire dans cette version, écran par écran, avec les libellés
 * exacts. Seules les sections de ses droits et des modules ouverts par l'exploitant sont montrées : l'aide
 * des examens n'apparaît jamais à l'accueil ni à la caisse. Le sommaire place le focus sur le titre de la
 * section choisie (l'historique du routeur n'est pas modifié). Contenu : `domain/help/guide.ts`.
 * Présentation : sommaire en carte au-dessus des sections sur téléphone et tablette, colonne latérale
 * fixée au défilement sur ordinateur ; chaque section est une carte (étapes numérotées, points, notes).
 */
import { computed, nextTick } from 'vue'
import { RouterLink } from 'vue-router'

import { currentPermissions, enabledModules } from '@/app/session'
import PageHeader from '@/components/layout/PageHeader.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { visibleHelpSections } from '@/domain/help/guide'

const sections = computed(() => visibleHelpSections(currentPermissions.value, enabledModules.value))

function headingId(id: string): string {
  return `aide-${id}`
}

async function goTo(id: string): Promise<void> {
  await nextTick()
  const heading = document.getElementById(headingId(id))
  heading?.scrollIntoView({ block: 'start' })
  heading?.focus()
}
</script>

<template>
  <section aria-labelledby="titre-aide" class="page-stack max-w-6xl">
    <PageHeader
      title-id="titre-aide"
      title="Aide"
      icon="help"
      subtitle="Cette aide ne présente que les écrans ouverts à votre compte. Les libellés cités sont ceux des boutons et des champs de l’application."
    />

    <div
      class="flex flex-col gap-6 lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:items-start lg:gap-8"
    >
      <nav
        aria-labelledby="titre-sommaire"
        class="card p-4 lg:sticky lg:top-6 lg:max-h-[calc(100vh_-_3rem)] lg:overflow-y-auto"
      >
        <h2 id="titre-sommaire" class="flex items-center gap-2 text-lg font-semibold">
          <AppIcon name="list" :size="18" class="text-gold" />
          Sommaire
        </h2>
        <ol role="list" class="mt-3 grid gap-1 sm:grid-cols-2 lg:grid-cols-1">
          <li v-for="section in sections" :key="section.id" class="min-w-0">
            <button
              type="button"
              class="group flex min-h-11 w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-primary hover:bg-primary-soft"
              @click="goTo(section.id)"
            >
              <AppIcon
                name="chevron-right"
                :size="16"
                class="text-gold transition-transform group-hover:translate-x-0.5"
              />
              <span class="min-w-0 break-words underline-offset-4 group-hover:underline">
                {{ section.title }}
              </span>
            </button>
          </li>
        </ol>
      </nav>

      <div class="min-w-0 space-y-6">
        <section
          v-for="section in sections"
          :key="section.id"
          :aria-labelledby="headingId(section.id)"
          class="card space-y-4 p-5 sm:p-6"
        >
          <h2
            :id="headingId(section.id)"
            tabindex="-1"
            class="section-title scroll-mt-6 text-xl focus:outline-none"
          >
            {{ section.title }}
          </h2>
          <p v-if="section.intro" class="max-w-prose">{{ section.intro }}</p>
          <template v-for="(block, index) in section.blocks" :key="`${section.id}-${index}`">
            <div v-if="block.kind === 'steps'" class="space-y-3">
              <h3 v-if="block.title" class="text-base font-semibold">{{ block.title }}</h3>
              <ol role="list" class="max-w-prose space-y-3">
                <li v-for="(item, step) in block.items" :key="item" class="flex gap-3">
                  <span
                    aria-hidden="true"
                    class="flex size-7 shrink-0 items-center justify-center rounded-full bg-gold-soft text-sm font-semibold text-gold-ink tabular-nums"
                    >{{ step + 1 }}</span
                  >
                  <span class="min-w-0 pt-0.5">{{ item }}</span>
                </li>
              </ol>
            </div>
            <div v-else-if="block.kind === 'points'" class="space-y-3">
              <h3 v-if="block.title" class="text-base font-semibold">{{ block.title }}</h3>
              <ul role="list" class="max-w-prose space-y-2">
                <li v-for="item in block.items" :key="item" class="flex gap-3">
                  <span
                    aria-hidden="true"
                    class="mt-2.5 size-1.5 shrink-0 rounded-full bg-gold"
                  ></span>
                  <span class="min-w-0">{{ item }}</span>
                </li>
              </ul>
            </div>
            <p
              v-else
              class="flex max-w-prose items-start gap-3 rounded-xl border border-line bg-primary-soft p-4 text-base text-ink"
            >
              <AppIcon name="info" :size="18" class="mt-0.5 text-primary" />
              <span class="min-w-0">{{ block.text }}</span>
            </p>
          </template>
        </section>

        <p>
          <RouterLink to="/" class="text-link inline-flex min-h-11 items-center gap-2 font-medium">
            <AppIcon name="arrow-left" :size="18" />
            Revenir à l’accueil
          </RouterLink>
        </p>
      </div>
    </div>
  </section>
</template>
