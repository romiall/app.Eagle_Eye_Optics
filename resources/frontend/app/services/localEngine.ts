import { scheduleDeviceSync } from './deviceSync'
import type { LocalCommandEngine, MutationRequest } from './mutations'
import { localCommandsOrNull } from './vault'

/**
 * Journal du poste préparé branché sur le répartiteur des mutations (O04 ; F10 : « Commandes d'un poste préparé
 * toujours enregistrées dans son journal, même en ligne »). Il ne prend une commande QUE si :
 *  - l'exploitant a ouvert le module `offline` et le coffre de ce poste est ouvert avec un grant signé valide ;
 *  - c'est une commande du journal (contrat F06), sans route explicite en ligne seulement ;
 *  - le profil a le compartiment et la permission figés dans son grant, la facture est créée ici ou attribuée à ce
 *    poste, et un numéro de la série est disponible.
 * Sinon `handles` rend faux et la route directe s'applique, EXACTEMENT comme avant (module fermé : aucun changement).
 *
 * « Enregistré sur ce poste » (`saved-local`) n'est rendu qu'après le commit local réel ; la commande part ensuite au
 * serveur (O05, échange peu après le commit, puis au retour du réseau) et n'est « consolidée » qu'à son ACK final.
 * Seules les commandes que le serveur sait appliquer depuis un poste sont prises ici (`offline/sync/applicable.ts`).
 */
export const deviceLocalEngine: LocalCommandEngine = {
  handles(request: MutationRequest): boolean {
    if (request.route !== undefined) return false
    const commands = localCommandsOrNull()
    if (commands === null) return false
    return commands.handles({
      name: request.name,
      payload: request.payload,
      expectedVersion: request.expectedVersion ?? null,
      idempotencyKey: request.idempotencyKey,
    })
  },

  async commit(request: MutationRequest): Promise<{ recordedAt: string }> {
    const commands = localCommandsOrNull()
    if (commands === null)
      throw new Error('Journal du poste indisponible : coffre fermé ou module hors-ligne fermé.')
    const result = await commands.commit({
      name: request.name,
      payload: request.payload,
      expectedVersion: request.expectedVersion ?? null,
      idempotencyKey: request.idempotencyKey,
    })
    scheduleDeviceSync()
    return { recordedAt: result.recordedAt }
  },
}
