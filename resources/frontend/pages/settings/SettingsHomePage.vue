<script setup lang="ts">
/**
 * Paramètres (module « settings ») : point d'entrée de la gérante vers les comptes du personnel et
 * l'identité du cabinet imprimée sur les documents (F05 côté serveur, écrans F09). Chaque carte n'apparaît
 * qu'avec son droit ; les autorisations des opérations sensibles (B06) apparaissent quand leur écran existe
 * dans cette version. La liste des modules ouverts n'est PAS un paramètre de la gérante : elle appartient au
 * déploiement (EEO_ENABLED_MODULES), l'écran le dit.
 */
import { computed } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { canOnScreen, session } from '@/app/session'

interface Card {
  id: string
  title: string
  text: string
  to: string | { name: string }
}

const router = useRouter()

const cards = computed<Card[]>(() => {
  const list: Card[] = []
  if (canOnScreen('users.manage')) {
    list.push(
      {
        id: 'personnel',
        title: 'Personnel',
        text: 'Comptes nominatifs, profils, dérogations de droits, désactivation, réinitialisation d’un mot de passe.',
        to: { name: 'parametres-personnel' },
      },
      {
        id: 'cabinet',
        title: 'Cabinet et documents',
        text: 'Nom, coordonnées, identifiant fiscal et mentions imprimés sur les reçus, factures et ordonnances.',
        to: { name: 'parametres-cabinet' },
      },
    )
  }
  if (canOnScreen('approval.decide') && router.hasRoute('approbations')) {
    list.push({
      id: 'approbations',
      title: 'Autorisations',
      text: 'Demandes d’autorisation des opérations sensibles (remise, dispense, correction).',
      to: { name: 'approbations' },
    })
  }
  return list
})
</script>

<template>
  <section aria-labelledby="titre-parametres" class="max-w-3xl space-y-6">
    <div class="space-y-1">
      <h1 id="titre-parametres" class="text-2xl font-semibold tracking-tight">Paramètres</h1>
      <p v-if="session.account" class="text-muted">{{ session.account.clinic.name }}</p>
    </div>

    <ul v-if="cards.length > 0" class="grid gap-3 sm:grid-cols-2">
      <li v-for="card in cards" :key="card.id">
        <RouterLink
          :to="card.to"
          class="flex min-h-24 flex-col gap-1 rounded-lg border-2 border-line bg-surface px-4 py-3 hover:border-primary"
        >
          <span class="text-base font-semibold text-primary">{{ card.title }}</span>
          <span class="text-sm text-ink">{{ card.text }}</span>
        </RouterLink>
      </li>
    </ul>
    <p v-else class="max-w-prose text-muted">
      Aucun paramètre n’est ouvert à ce compte.
    </p>

    <p class="max-w-prose text-sm text-muted">
      Les modules visibles dans l’application (patients, consultations, ventes…) sont ouverts par
      l’exploitant lors du déploiement, pas depuis cet écran.
    </p>
  </section>
</template>
