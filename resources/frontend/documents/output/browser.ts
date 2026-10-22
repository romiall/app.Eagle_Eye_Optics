/**
 * Ouverture, téléchargement et impression d'un PDF généré localement (D01). Aucune requête réseau :
 * les octets restent sur le poste, l'adresse est une URL d'objet (`blob:`) locale et aléatoire.
 *
 * Impression : la politique de sécurité de l'application (CSP `default-src 'self'`, `object-src blob:`)
 * interdit d'intégrer le PDF dans un cadre de la page pour l'imprimer en place. L'impression passe donc
 * par la visionneuse PDF du navigateur : le PDF s'ouvre dans un nouvel onglet (« Ouvrir le PDF ») et
 * l'utilisateur imprime depuis la visionneuse (Ctrl+P) ; `object-src blob:` permet à cette visionneuse de
 * s'afficher dans le document `blob:`, qui hérite de la CSP. Si le navigateur bloque l'ouverture, le PDF
 * est téléchargé (voir `docs/implementation/documents.md` et `app/Http/Middleware/SecurityHeaders.php`).
 */
export type OpenOutcome = 'opened' | 'downloaded'

const OPEN_URL_LIFETIME_MS = 10 * 60 * 1000
const DOWNLOAD_URL_LIFETIME_MS = 30 * 1000

function pdfBlob(bytes: Uint8Array): Blob {
  return new Blob([new Uint8Array(bytes)], { type: 'application/pdf' })
}

/** Télécharge le PDF sous `fileName` (nom sans donnée sensible, voir `fileName.ts`). */
export function downloadPdf(bytes: Uint8Array, fileName: string): void {
  const url = URL.createObjectURL(pdfBlob(bytes))
  const link = document.createElement('a')
  link.href = url
  link.download = fileName
  link.rel = 'noopener'
  link.style.display = 'none'
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), DOWNLOAD_URL_LIFETIME_MS)
}

/**
 * Ouvre le PDF dans un nouvel onglet (visionneuse du navigateur, impression possible). Si le
 * navigateur refuse la fenêtre, le PDF est téléchargé : la pièce n'est jamais perdue.
 */
export function openPdf(bytes: Uint8Array, fileName: string): OpenOutcome {
  const url = URL.createObjectURL(pdfBlob(bytes))
  const opened = window.open(url, '_blank')
  if (opened === null) {
    URL.revokeObjectURL(url)
    downloadPdf(bytes, fileName)
    return 'downloaded'
  }
  setTimeout(() => URL.revokeObjectURL(url), OPEN_URL_LIFETIME_MS)
  return 'opened'
}

/** Impression : ouverture dans la visionneuse (voir l'en-tête du fichier). */
export function printPdf(bytes: Uint8Array, fileName: string): OpenOutcome {
  return openPdf(bytes, fileName)
}
