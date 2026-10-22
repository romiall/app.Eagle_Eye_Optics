import { afterEach, beforeEach, describe, expect, it, type Mock, vi } from 'vitest'

import { DEMO_DOCUMENTS } from '@/documents/demo/catalog'
import { downloadPdf, openPdf, printPdf } from '@/documents/output/browser'
import { documentFileName } from '@/documents/output/fileName'

function model(id: string) {
  const entry = DEMO_DOCUMENTS.find((candidate) => candidate.id === id)
  if (entry === undefined) throw new Error(`Document de démonstration introuvable : ${id}`)
  return entry.build()
}

describe('nom de fichier du PDF : sans donnée sensible', () => {
  it('reprend le numéro de la pièce', () => {
    expect(documentFileName(model('facture-courte'))).toBe('facture-TEST-FAC-000001.pdf')
    expect(documentFileName(model('recu-acompte'))).toBe('recu-TEST-REC-000001.pdf')
    expect(documentFileName(model('avoir'))).toBe('avoir-TEST-AV-000001.pdf')
    expect(documentFileName(model('remboursement'))).toBe('remboursement-TEST-RMB-000001.pdf')
  })

  it('sans numéro : le type et la date de la pièce, jamais un nom', () => {
    expect(documentFileName(model('prescription'))).toBe('prescription-optique-20261014.pdf')
    expect(documentFileName(model('etat-de-compte'))).toBe('etat-de-compte-20261022.pdf')
    expect(documentFileName(model('ordonnance'))).toBe('ordonnance-20261014.pdf')
  })

  it('signale le brouillon et le duplicata dans le nom', () => {
    expect(documentFileName(model('facture-brouillon'))).toBe('facture-20261022-brouillon.pdf')
    expect(documentFileName(model('recu-duplicata'))).toBe('recu-TEST-REC-000001-duplicata.pdf')
  })

  it('n’emploie que des caractères sûrs et ne contient jamais le patient fictif', () => {
    for (const entry of DEMO_DOCUMENTS) {
      const name = documentFileName(entry.build())
      expect(name, entry.id).toMatch(/^[A-Za-z0-9._-]+\.pdf$/)
      expect(name, entry.id).not.toMatch(/PATIENT|FICTIF|lodie|TEST-P-/i)
    }
  })

  it('neutralise un numéro qui contiendrait des caractères spéciaux', () => {
    const base = model('recu-acompte')
    const unsafe = {
      ...base,
      meta: { ...base.meta, documentNumber: 'REC/2026 \\ é "x"' },
    }

    expect(documentFileName(unsafe)).toBe('recu-REC-2026-x.pdf')
  })
})

describe('ouverture, téléchargement et impression du PDF (aucun réseau)', () => {
  const bytes = new TextEncoder().encode('%PDF-1.3 fictif')
  let createObjectURL: Mock<(blob: Blob) => string>
  let revokeObjectURL: Mock<(url: string) => void>

  beforeEach(() => {
    vi.useFakeTimers()
    createObjectURL = vi.fn<(blob: Blob) => string>(() => 'blob:http://localhost/aleatoire')
    revokeObjectURL = vi.fn<(url: string) => void>(() => undefined)
    Object.defineProperty(URL, 'createObjectURL', {
      value: createObjectURL,
      configurable: true,
      writable: true,
    })
    Object.defineProperty(URL, 'revokeObjectURL', {
      value: revokeObjectURL,
      configurable: true,
      writable: true,
    })
  })

  afterEach(() => {
    vi.useRealTimers()
    Reflect.deleteProperty(URL, 'createObjectURL')
    Reflect.deleteProperty(URL, 'revokeObjectURL')
  })

  it('le téléchargement donne un lien local au nom sans donnée sensible, puis libère l’URL', () => {
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      expect(this.download).toBe('recu-TEST-REC-000001.pdf')
      expect(this.href).toBe('blob:http://localhost/aleatoire')
    })

    downloadPdf(bytes, 'recu-TEST-REC-000001.pdf')

    expect(click).toHaveBeenCalledTimes(1)
    const blob = createObjectURL.mock.calls[0]?.[0]
    expect(blob?.type).toBe('application/pdf')
    expect(blob?.size).toBe(bytes.byteLength)
    expect(document.querySelector('a[download]')).toBeNull()
    expect(revokeObjectURL).not.toHaveBeenCalled()
    vi.advanceTimersByTime(30_000)
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:http://localhost/aleatoire')
  })

  it('l’ouverture passe par un nouvel onglet et garde l’URL le temps de la visionneuse', () => {
    const open = vi.spyOn(window, 'open').mockReturnValue({} as Window)

    expect(openPdf(bytes, 'facture.pdf')).toBe('opened')
    expect(open).toHaveBeenCalledWith('blob:http://localhost/aleatoire', '_blank')
    vi.advanceTimersByTime(60_000)
    expect(revokeObjectURL).not.toHaveBeenCalled()
    vi.advanceTimersByTime(10 * 60_000)
    expect(revokeObjectURL).toHaveBeenCalledTimes(1)
  })

  it('un navigateur qui bloque la fenêtre reçoit le téléchargement : la pièce n’est pas perdue', () => {
    vi.spyOn(window, 'open').mockReturnValue(null)
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    expect(openPdf(bytes, 'facture.pdf')).toBe('downloaded')
    expect(click).toHaveBeenCalledTimes(1)
    expect(revokeObjectURL).toHaveBeenCalledTimes(1)
  })

  it('l’impression ouvre la visionneuse (aucun cadre ni objet intégré : la CSP les interdit)', () => {
    const open = vi.spyOn(window, 'open').mockReturnValue({} as Window)

    expect(printPdf(bytes, 'facture.pdf')).toBe('opened')
    expect(open).toHaveBeenCalledTimes(1)
    expect(document.querySelector('iframe, object, embed')).toBeNull()
  })

  it('n’émet aucune requête réseau', () => {
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)
    vi.spyOn(window, 'open').mockReturnValue({} as Window)

    openPdf(bytes, 'facture.pdf')
    downloadPdf(bytes, 'facture.pdf')

    expect(fetchSpy).not.toHaveBeenCalled()
  })
})
