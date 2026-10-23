import { describe, expect, it } from 'vitest'

import { HELP_SECTIONS, visibleHelpSections } from '@/domain/help/guide'

/**
 * Aide intégrée : chaque compte ne lit que l'aide de ses droits et des modules ouverts ; l'accueil et la
 * caisse ne voient jamais l'aide des examens ; aucun texte ne dit « payé », « soldé » ni « gratuit », ni ne
 * présente le travail sans Internet comme disponible. Données fictives.
 */
const ALL_MODULES = [
  'patients',
  'today',
  'consultations',
  'sales',
  'stock',
  'settings',
  'reports',
  'agenda',
]
const RECEPTION = [
  'patient.read',
  'patient.create',
  'patient.update',
  'visit.register',
  'agenda.read',
]
const CLINICIAN = ['patient.read', 'agenda.read', 'clinical.read', 'clinical.write']
const CASHIER = ['patient.read', 'invoice.read', 'cash.own_session']
const MANAGER = [
  ...new Set([
    ...RECEPTION,
    ...CLINICIAN,
    ...CASHIER,
    'catalog.manage',
    'users.manage',
    'approval.decide',
    'cash.manage',
    'statistics.activity',
    'statistics.clinical',
    'finance.global.read',
    // Remises au patient, casse et ajustement, fusion de fiches et exports : droits de la gérante.
    'delivery.confirm',
    'stock.adjust',
    'patient.merge',
    'export.global',
    'clinical.export',
  ]),
]

function ids(permissions: readonly string[], modules: readonly string[] = ALL_MODULES): string[] {
  return visibleHelpSections(permissions, modules).map((section) => section.id)
}

describe('aide intégrée', () => {
  it('les identifiants des sections sont uniques', () => {
    const all = HELP_SECTIONS.map((section) => section.id)
    expect(new Set(all).size).toBe(all.length)
  })

  it('l’accueil ne voit ni l’aide des examens ni celle de la caisse', () => {
    const visible = ids(RECEPTION)
    expect(visible).toEqual(
      expect.arrayContaining([
        'essentiel',
        'premiers-pas',
        'patients',
        'arrivees',
        'file-du-jour',
        'a-venir',
      ]),
    )
    expect(visible).not.toContain('examen')
    expect(visible).not.toContain('ordonnances')
    expect(visible).not.toContain('factures')
    expect(visible).not.toContain('caisse')
  })

  it('l’agenda : visible avec agenda.read et le module ouvert, jamais module fermé', () => {
    expect(ids(RECEPTION)).toEqual(expect.arrayContaining(['agenda', 'suivis']))
    expect(ids(RECEPTION, ['patients', 'today'])).not.toContain('agenda')
  })

  it('la caisse ne voit jamais l’aide clinique', () => {
    const visible = ids(CASHIER)
    expect(visible).toEqual(expect.arrayContaining(['caisse', 'factures']))
    expect(visible).not.toContain('examen')
    expect(visible).not.toContain('ordonnances')
  })

  it('les devis : visibles avec les droits de vente et le module « sales », jamais pour l’accueil', () => {
    expect(ids(CASHIER)).toContain('devis')
    expect(ids(RECEPTION)).not.toContain('devis')
    expect(ids(CASHIER, ['patients', 'today'])).not.toContain('devis')
  })

  it('le professionnel voit l’examen et les ordonnances', () => {
    expect(ids(CLINICIAN)).toEqual(
      expect.arrayContaining(['examen', 'ordonnances', 'file-du-jour']),
    )
  })

  it('la gérante voit tout ce qui est ouvert', () => {
    expect(ids(MANAGER)).toEqual(HELP_SECTIONS.map((section) => section.id))
  })

  it('un module fermé n’apparaît nulle part, même avec tous les droits', () => {
    const visible = ids(MANAGER, ['patients', 'today'])
    expect(visible).not.toContain('examen')
    expect(visible).not.toContain('factures')
    expect(visible).not.toContain('personnel')
    expect(visible).toContain('patients')
  })

  it('aucun texte ne promet un règlement ni le travail sans Internet', () => {
    const text = HELP_SECTIONS.flatMap((section) => [
      section.title,
      section.intro ?? '',
      ...section.blocks.flatMap((block) =>
        block.kind === 'note' ? [block.text] : [...block.items, block.title ?? ''],
      ),
    ])
      .join('\n')
      .toLowerCase()
    expect(text).not.toMatch(/\bpayée?s?\b|\bsoldée?s?\b|\bgratuite?s?\b|acquitté/)
    expect(text).not.toContain('enregistré sur ce poste')
    expect(text).toContain('connexion internet')
  })
})
