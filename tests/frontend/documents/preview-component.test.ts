import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import DocumentPreview from '@/components/documents/DocumentPreview.vue'
import { DEMO_DOCUMENTS } from '@/documents/demo/catalog'
import { paragraph } from '@/documents/engine/model'
import { downloadPdf, openPdf, printPdf } from '@/documents/output/browser'

import { fakeRendered } from './support'

vi.mock('@/documents/output/browser', () => ({
  openPdf: vi.fn(),
  printPdf: vi.fn(),
  downloadPdf: vi.fn(),
}))

function modelOf(id: string) {
  const entry = DEMO_DOCUMENTS.find((candidate) => candidate.id === id)
  if (entry === undefined) throw new Error(id)
  return entry.build()
}

function mountPreview(id = 'recu-acompte', overrides = {}) {
  const document = fakeRendered(modelOf(id), { fileName: 'recu-TEST-REC-000001.pdf', ...overrides })
  const wrapper = mount(DocumentPreview, { props: { document, heading: 'Reçu d’acompte' } })
  return { wrapper, document }
}

function button(wrapper: ReturnType<typeof mountPreview>['wrapper'], name: string) {
  const found = wrapper.findAll('button').find((candidate) => candidate.text() === name)
  if (found === undefined) throw new Error(`Bouton introuvable : ${name}`)
  return found
}

describe('aperçu d’un document PDF (accessibilité, 360 px, focus)', () => {
  beforeEach(() => {
    vi.mocked(openPdf).mockReturnValue('opened')
    vi.mocked(printPdf).mockReturnValue('opened')
  })

  it('est une région nommée avec trois actions dont le libellé dit la conséquence', () => {
    const { wrapper } = mountPreview()

    expect(wrapper.attributes('aria-label')).toBe('Aperçu : Reçu d’acompte')
    expect(wrapper.findAll('button').map((candidate) => candidate.text())).toEqual([
      'Ouvrir le PDF',
      'Imprimer',
      'Télécharger le PDF',
    ])
    // Les boutons sont de vrais boutons (clavier, focus), de 44 px au moins.
    for (const candidate of wrapper.findAll('button')) {
      expect(candidate.attributes('type')).toBe('button')
      expect(candidate.classes()).toContain('min-h-11')
    }
  })

  it('dessine chaque page en image nommée, avec le texte du plan', () => {
    const { wrapper, document } = mountPreview()
    const svg = wrapper.get('svg')

    expect(svg.attributes('role')).toBe('img')
    expect(svg.attributes('aria-label')).toBe('Page 1 sur 1 : Reçu d’acompte')
    expect(svg.attributes('viewBox')).toBe(
      `0 0 ${document.plan.format.widthMm} ${document.plan.format.pageHeightMm}`,
    )
    const texts = svg.findAll('text').map((node) => node.text())
    expect(texts).toContain('TEST-REC-000001')
    expect(texts).toContain('REÇU DE PAIEMENT')
    expect(texts.some((text) => text.includes('30\u{A0}000\u{A0}FCFA'))).toBe(true)
    // Les lignes, cadres du plan sont tracés (tableau, filets).
    expect(svg.findAll('line').length).toBeGreaterThan(3)
    expect(svg.findAll('rect').length).toBeGreaterThan(1)
  })

  it('offre une alternative textuelle par page, repliée par défaut', () => {
    const { wrapper } = mountPreview()
    const details = wrapper.get('details')

    expect(details.attributes('open')).toBeUndefined()
    expect(details.get('summary').text()).toBe('Lire le contenu de la page 1 en texte')
    const lines = details.findAll('li').map((item) => item.text())
    expect(lines).toContain('Numéro | TEST-REC-000001')
    expect(lines.some((line) => line.includes('Reste après ce versement'))).toBe(true)
  })

  it('le filigrane est dessiné en diagonale, le texte du plan ne le répète pas', () => {
    const { wrapper } = mountPreview('facture-brouillon')
    const rotated = wrapper
      .findAll('text')
      .filter((node) => node.attributes('transform') !== undefined)

    expect(rotated).toHaveLength(1)
    expect(rotated[0]?.text()).toBe('BROUILLON')
    expect(rotated[0]?.attributes('transform')).toMatch(/^rotate\(-45 /)
    const lines = wrapper.findAll('details li').map((item) => item.text())
    expect(lines.every((line) => !line.includes('BROUILLON |'))).toBe(true)
  })

  it('plusieurs pages : une figure, une légende et une alternative textuelle par page', () => {
    const { wrapper } = mountPreview('facture-longue')
    const captions = wrapper.findAll('figcaption').map((node) => node.text())

    expect(captions.length).toBeGreaterThan(1)
    expect(captions[0]).toBe(`Page 1 sur ${captions.length}`)
    expect(wrapper.findAll('svg')).toHaveLength(captions.length)
    expect(wrapper.findAll('details')).toHaveLength(captions.length)
    expect(wrapper.text()).toContain(`${captions.length} pages`)
  })

  it('le défilement horizontal est limité à la page dessinée (aucun débordement de la fenêtre)', () => {
    const { wrapper } = mountPreview()
    const frame = wrapper.get('figure > div')

    expect(frame.classes()).toContain('overflow-x-auto')
    expect(wrapper.get('svg').classes()).toEqual(expect.arrayContaining(['w-full', 'max-w-3xl']))
  })

  it('« Ouvrir le PDF » ouvre la visionneuse et l’annonce', async () => {
    const { wrapper, document } = mountPreview()
    await button(wrapper, 'Ouvrir le PDF').trigger('click')

    expect(openPdf).toHaveBeenCalledWith(document.bytes, 'recu-TEST-REC-000001.pdf')
    expect(wrapper.get('[role="status"]').text()).toBe('PDF ouvert dans un nouvel onglet.')
  })

  it('une ouverture bloquée par le navigateur est dite, le PDF est téléchargé', async () => {
    vi.mocked(openPdf).mockReturnValue('downloaded')
    const { wrapper } = mountPreview()
    await button(wrapper, 'Ouvrir le PDF').trigger('click')

    expect(wrapper.get('[role="status"]').text()).toBe(
      'Le navigateur a bloqué l’ouverture : le PDF a été téléchargé.',
    )
  })

  it('« Imprimer » ouvre la visionneuse et dit où imprimer (aucun cadre intégré)', async () => {
    const { wrapper, document } = mountPreview()
    await button(wrapper, 'Imprimer').trigger('click')

    expect(printPdf).toHaveBeenCalledWith(document.bytes, 'recu-TEST-REC-000001.pdf')
    expect(wrapper.get('[role="status"]').text()).toBe(
      'PDF ouvert : imprimez-le depuis la visionneuse du navigateur (Ctrl+P).',
    )
    expect(wrapper.find('iframe, object, embed').exists()).toBe(false)
  })

  it('« Télécharger le PDF » annonce le nom du fichier, sans donnée sensible', async () => {
    const { wrapper, document } = mountPreview()
    await button(wrapper, 'Télécharger le PDF').trigger('click')

    expect(downloadPdf).toHaveBeenCalledWith(document.bytes, 'recu-TEST-REC-000001.pdf')
    expect(wrapper.get('[role="status"]').text()).toBe('PDF téléchargé : recu-TEST-REC-000001.pdf.')
  })

  it('la zone d’état est annoncée poliment sans interrompre', () => {
    const { wrapper } = mountPreview()

    expect(wrapper.get('[role="status"]').attributes('aria-live')).toBe('polite')
  })

  it('montre les points à vérifier avant d’imprimer, dans une liste nommée', () => {
    const { wrapper } = mountPreview('recu-acompte', {
      warnings: ['Adresse du cabinet absente de la configuration.'],
    })
    const list = wrapper.get('ul[aria-label="Points à vérifier avant d’imprimer"]')

    expect(list.findAll('li').map((item) => item.text())).toEqual([
      '⚠Adresse du cabinet absente de la configuration.',
    ])
  })

  it('sans anomalie, aucune liste d’avertissements', () => {
    const { wrapper } = mountPreview()

    expect(wrapper.find('ul[aria-label]').exists()).toBe(false)
  })

  it('rappelle le fichier, l’empreinte et que les polices du PDF peuvent différer', () => {
    const { wrapper } = mountPreview()

    expect(wrapper.text()).toContain('fichier recu-TEST-REC-000001.pdf')
    expect(wrapper.text()).toContain('empreinte SHA-256 aaaaaaaaaaaa…')
    expect(wrapper.text()).toContain('les polices du PDF peuvent différer légèrement')
  })

  it('ne produit aucune injection HTML : le texte du document est inséré comme texte', () => {
    const model = modelOf('recu-acompte')
    const hostile = { ...model, body: [paragraph('<img src=x onerror=alert(1)>'), ...model.body] }
    const wrapper = mount(DocumentPreview, {
      props: { document: fakeRendered(hostile), heading: '<b>Titre</b>' },
    })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('b').exists()).toBe(false)
    expect(wrapper.html()).toContain('&lt;img src=x onerror=alert(1)&gt;')
    expect(wrapper.attributes('aria-label')).toBe('Aperçu : <b>Titre</b>')
  })
})
