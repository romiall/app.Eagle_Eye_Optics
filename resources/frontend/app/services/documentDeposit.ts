import { apiRequest } from '@/app/api'
import type { DocumentKey } from '@/documents/store/port'

import { documentStore } from './documents'

/**
 * Dépose l'ORIGINAL sur le serveur (`POST /documents/{id}/content`, D01) : le serveur vérifie la Policy, la
 * signature PDF et l'empreinte, le conserve dans son stockage privé et passe le document à « prêt ». Un original
 * déjà conservé ne se remplace jamais (409) : le même contenu est simplement reconnu. La clé d'idempotence
 * dépend du document et du contenu : une reprise ne crée aucun doublon. Sans réponse, rien n'est conclu (le
 * document reste « en attente » côté serveur, la pièce reste dans le magasin de ce poste). Rend vrai si le
 * serveur a confirmé la conservation.
 *
 * Ce dépôt est le seul échange réseau des documents : il vit hors de `documents.ts`, dont la génération et la
 * conservation restent strictement locales (aucun client HTTP importé).
 */
export async function depositOriginal(input: {
  documentId: string
  key: DocumentKey
  bytes: Uint8Array
  sha256: string
}): Promise<boolean> {
  try {
    await apiRequest(
      'POST',
      `/api/v1/documents/${encodeURIComponent(input.documentId)}/content`,
      input.bytes,
      {
        'Content-Type': 'application/pdf',
        'X-Content-Sha256': input.sha256,
        'Idempotency-Key': `doc-${input.documentId}-${input.sha256.slice(0, 16)}`,
      },
    )
  } catch {
    return false
  }
  await documentStore()
    .markUploaded(input.key)
    .catch(() => undefined)
  return true
}
