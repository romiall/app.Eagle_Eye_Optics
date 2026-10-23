import { apiRequest } from '@/app/api'
import { sha256Hex } from '@/documents/engine/hash'
import type {
  ImportReport,
  ImportReportResponse,
  LegacyBalanceImportRequest,
  LegacyEvidence,
  LegacyEvidenceResponse,
} from '@/domain/contracts/generated'

/**
 * Reprise des créances papier (X01), EN LIGNE, gérante (`finance.global.read`, réauthentification récente : 423
 * sinon, l'écran demande le mot de passe puis renvoie la MÊME demande avec la même clé).
 *
 * - `uploadLegacyEvidence` : le scan de la pièce papier (PDF, JPEG ou PNG) part en octets bruts avec son
 *   empreinte ; le serveur reconnaît le type aux premiers octets et le conserve en privé.
 * - `submitLegacyBalances` : aperçu (`dry_run`) sans écriture, ou reprise (toutes les créances ou aucune). La clé
 *   d'idempotence est fournie par l'écran : une reprise relancée après une coupure ne crée aucun doublon.
 */
export const EVIDENCE_TYPES = ['application/pdf', 'image/jpeg', 'image/png'] as const

export async function uploadLegacyEvidence(file: File): Promise<LegacyEvidence> {
  const type = (EVIDENCE_TYPES as readonly string[]).includes(file.type)
    ? file.type
    : 'application/octet-stream'
  const bytes = new Uint8Array(await file.arrayBuffer())
  const sha256 = await sha256Hex(bytes)
  const response = await apiRequest<LegacyEvidenceResponse>(
    'POST',
    '/api/v1/legacy-balances/evidence',
    bytes,
    {
      'Content-Type': type,
      'X-Content-Sha256': sha256,
      'Idempotency-Key': `ev-${sha256.slice(0, 32)}`,
    },
  )
  return response.data
}

export async function submitLegacyBalances(
  body: LegacyBalanceImportRequest,
  idempotencyKey: string,
): Promise<ImportReport> {
  const response = await apiRequest<ImportReportResponse>(
    'POST',
    '/api/v1/imports/legacy-balances',
    body,
    {
      'Idempotency-Key': idempotencyKey,
    },
  )
  return response.data
}
