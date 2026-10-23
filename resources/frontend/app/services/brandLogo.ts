import logoUrl from '@/assets/brand/eeo-logo-document.png'
import type { DocumentLogo } from '@/documents/engine/model'

/**
 * Logo de la marque (aigle et « EAGLE’S EYE OPTICS — SEE BEYOND ») dans l'en-tête des documents PDF, tiré du logo
 * vectoriel fourni par le demandeur (sans le cadre ni les coordonnées de l'original). Fichier de l'application,
 * haché et précaché : il s'imprime aussi hors ligne. Lu une seule fois par session ; s'il ne se lit pas, le
 * document s'imprime sans logo (jamais un logo deviné).
 */
const WIDTH_MM = 20
const HEIGHT_MM = 22

let cached: Promise<DocumentLogo | null> | null = null

async function load(): Promise<DocumentLogo | null> {
  try {
    const response = await fetch(logoUrl)
    if (!response.ok) return null
    const blob = await response.blob()
    const dataUrl = await new Promise<string>((resolve, reject) => {
      const reader = new FileReader()
      reader.onload = () => resolve(String(reader.result))
      reader.onerror = () => reject(reader.error ?? new Error('Lecture du logo impossible.'))
      reader.readAsDataURL(blob)
    })
    return dataUrl.startsWith('data:image/png')
      ? { dataUrl, format: 'PNG', widthMm: WIDTH_MM, heightMm: HEIGHT_MM }
      : null
  } catch {
    return null
  }
}

export function brandLogo(): Promise<DocumentLogo | null> {
  cached ??= load()
  return cached
}

/** Tests : oublie le logo lu. */
export function resetBrandLogoForTests(): void {
  cached = null
}
