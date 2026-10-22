import { onMounted, shallowRef } from 'vue'

import { getSalesSettings } from '@/app/services/billing'
import type { SalesSettings } from '@/domain/contracts/generated'

/**
 * Règles de vente du compte connecté (`GET /sales/settings`) pour DIRE les limites avant l'envoi : plafond
 * de remise effectif, modes de règlement à référence obligatoire. Lues à l'ouverture de l'écran ; tant
 * qu'elles ne sont pas lues (ou si la lecture échoue), l'écran garde ses textes génériques et ses valeurs
 * par défaut : le serveur reste seul juge à chaque commande, rien n'est bloqué à cause de cette lecture.
 */
export function useSalesSettings() {
  const settings = shallowRef<SalesSettings | null>(null)

  onMounted(async () => {
    try {
      settings.value = (await getSalesSettings()).data
    } catch {
      settings.value = null
    }
  })

  return settings
}
