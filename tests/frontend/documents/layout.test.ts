// @vitest-environment node
import { describe, expect, it } from 'vitest'

import { resolveColumnWidths } from '@/documents/engine/blocks'
import {
  type Block,
  type DocumentModel,
  fields,
  group,
  heading,
  notice,
  paragraph,
  row,
  signature,
  spacer,
  table,
  type TableColumn,
  totalRow,
  totals,
} from '@/documents/engine/model'
import type { LayoutPlan, TextOp } from '@/documents/engine/plan'
import { planPageLines } from '@/documents/engine/preview'
import { wrapText } from '@/documents/engine/wrap'

import { allTexts, layout, MEASURER, outOfBounds, printedText } from './support'

/** Modèle minimal de test : en-tête réduit, pied de page, aucun filigrane. */
function model(body: Block[], overrides: Partial<DocumentModel> = {}): DocumentModel {
  return {
    schema: 1,
    kind: 'INVOICE',
    templateVersion: 'TEST-1',
    title: 'Document de test',
    format: 'A4',
    meta: {
      documentNumber: 'TEST-0001',
      documentAt: '2026-10-14T09:30:00.000Z',
      copy: { kind: 'ORIGINAL' },
      draft: false,
      test: true,
      timeZone: 'Africa/Douala',
    },
    letterhead: {
      organization: 'CABINET DE DÉMONSTRATION',
      lines: ['Adresse du cabinet : à renseigner'],
      logo: null,
      logoPlaceholder: true,
    },
    titleBlock: {
      title: 'FACTURE',
      subtitle: null,
      fields: [{ label: 'Numéro', value: 'TEST-0001' }],
    },
    body,
    footerLines: ['Mentions : à renseigner', 'Modèle TEST-1'],
    watermark: null,
    warnings: [],
    ...overrides,
  }
}

const COLUMNS: TableColumn[] = [
  { key: 'description', header: 'Désignation', width: { fr: 1 }, align: 'left' },
  { key: 'quantity', header: 'Qté', width: { mm: 14 }, align: 'right' },
  { key: 'total', header: 'Total (FCFA)', width: { mm: 28 }, align: 'right' },
]

function rows(count: number, text = 'Ligne'): ReturnType<typeof row>[] {
  return Array.from({ length: count }, (_, index) =>
    row([`${text} ${index + 1}`, '1', `${(index + 1) * 1000}`]),
  )
}

/** Lignes du corps d'une page : sans rappel de continuation ni pied de page. */
function bodyLines(page: LayoutPlan['pages'][number]): string[] {
  return planPageLines(page).filter(
    (line) =>
      !line.includes('Page ') &&
      !line.includes('(suite)') &&
      !line.includes('Mentions :') &&
      !line.includes('Modèle TEST-1'),
  )
}

function textsOf(plan: ReturnType<typeof layout>, page: number): TextOp[] {
  const found = plan.pages[page]
  return (found?.ops ?? []).filter((op): op is TextOp => op.op === 'text')
}

describe('découpe du texte en lignes', () => {
  const wrap = (text: string, width: number) => wrapText(text, width, 10, 'normal', MEASURER)

  it('coupe aux espaces ordinaires et ne perd aucun mot', () => {
    const lines = wrap('un deux trois quatre cinq six sept huit neuf dix', 25)

    expect(lines.length).toBeGreaterThan(1)
    expect(lines.join(' ')).toBe('un deux trois quatre cinq six sept huit neuf dix')
    for (const line of lines) expect(MEASURER.width(line, 10, 'normal')).toBeLessThanOrEqual(25.01)
  })

  it('ne coupe jamais une espace insécable : « 80 000 FCFA » reste d’un bloc', () => {
    const amount = `80\u{A0}000\u{A0}FCFA`
    const lines = wrap(`Total ${amount} dû`, 36)

    expect(lines.some((line) => line.includes(amount))).toBe(true)
  })

  it('coupe un mot plus long qu’une ligne par caractères, sans rien supprimer', () => {
    const word = 'A'.repeat(60)
    const lines = wrap(word, 30)

    expect(lines.length).toBeGreaterThan(1)
    expect(lines.join('')).toBe(word)
  })

  it('conserve les retours forcés et les lignes vides, et rend au moins une ligne', () => {
    expect(wrap('a\n\nb', 50)).toEqual(['a', '', 'b'])
    expect(wrap('', 50)).toEqual([''])
  })
})

describe('largeurs de colonnes', () => {
  it('donne aux colonnes à part le reste après les colonnes fixes', () => {
    const widths = resolveColumnWidths(COLUMNS, 182)

    expect(widths[1]).toBe(14)
    expect(widths[2]).toBe(28)
    expect(widths[0]).toBeCloseTo(140, 5)
  })

  it('réduit proportionnellement des colonnes fixes plus larges que la page', () => {
    const widths = resolveColumnWidths(
      [
        { key: 'a', header: 'A', width: { mm: 100 }, align: 'left' },
        { key: 'b', header: 'B', width: { mm: 100 }, align: 'left' },
      ],
      100,
    )

    expect(widths.reduce((total, width) => total + width, 0)).toBeCloseTo(100, 5)
  })
})

describe('mise en page : en-tête, pied de page, numérotation', () => {
  it('une page courte : cabinet, titre, numéro et pied de page « Page 1/1 »', () => {
    const plan = layout(model([paragraph('Contenu.')]))
    const text = printedText(plan)

    expect(plan.pages).toHaveLength(1)
    expect(text).toContain('CABINET DE DÉMONSTRATION')
    expect(text).toContain('FACTURE')
    expect(text).toContain('Numéro | TEST-0001')
    expect(text).toContain('Page 1/1')
    expect(text).toContain('Modèle TEST-1')
    expect(outOfBounds(plan)).toEqual([])
  })

  it('signale « LOGO À FOURNIR » quand le cabinet n’a pas de logo en démonstration', () => {
    expect(printedText(layout(model([])))).toContain('LOGO À FOURNIR')
    const without = layout(
      model([], {
        letterhead: { organization: 'Cabinet', lines: [], logo: null, logoPlaceholder: false },
      }),
    )
    expect(printedText(without)).not.toContain('LOGO')
  })

  it('numérote chaque page « Page k/N » et répète le rappel du cabinet et de la pièce', () => {
    const plan = layout(model([table('Lignes', COLUMNS, rows(120))]))
    const count = plan.pages.length

    expect(count).toBeGreaterThan(2)
    plan.pages.forEach((page, index) => {
      const lines = planPageLines(page).join('\n')
      expect(lines, `page ${index + 1}`).toContain(`Page ${index + 1}/${count}`)
      expect(lines, `page ${index + 1}`).toContain('CABINET DE DÉMONSTRATION')
      if (index > 0) expect(lines, `page ${index + 1}`).toContain('FACTURE TEST-0001 (suite)')
    })
    expect(outOfBounds(plan)).toEqual([])
  })

  it('pose le filigrane sous le contenu, en diagonale, sur toutes les pages', () => {
    const plan = layout(model([table('Lignes', COLUMNS, rows(120))], { watermark: 'BROUILLON' }))

    for (const page of plan.pages) {
      const first = page.ops[0]
      expect(first?.op).toBe('text')
      expect((first as TextOp).text).toBe('BROUILLON')
      expect((first as TextOp).angle).toBe(45)
      expect((first as TextOp).gray).toBeGreaterThan(200)
    }
  })

  it('le ticket 80 mm est une page continue : une seule page, hauteur du contenu, mention en tête', () => {
    const plan = layout(
      model([paragraph('Contenu du ticket.')], {
        format: 'TICKET_80',
        watermark: 'DONNÉES DE TEST',
      }),
    )

    expect(plan.pages).toHaveLength(1)
    expect(plan.format.widthMm).toBe(80)
    expect(plan.format.pageHeightMm).toBeGreaterThan(40)
    expect(plan.format.pageHeightMm).toBeLessThan(250)
    expect(printedText(plan)).toContain('*** DONNÉES DE TEST ***')
    expect(printedText(plan)).not.toContain('Page 1/1')
    expect(outOfBounds(plan)).toEqual([])
  })
})

describe('tableaux : pagination (fichier 11)', () => {
  it('répète l’en-tête des colonnes sur chaque page et ne coupe aucune ligne', () => {
    const plan = layout(model([table('Lignes', COLUMNS, rows(90))]))

    expect(plan.pages.length).toBeGreaterThan(1)
    plan.pages.forEach((page, index) => {
      const lines = planPageLines(page).join('\n')
      expect(lines, `page ${index + 1}`).toContain('Désignation | Qté | Total (FCFA)')
    })
    // Chaque ligne du tableau est imprimée exactement une fois.
    const text = printedText(plan)
    for (let index = 1; index <= 90; index += 1) {
      const occurrences = text.split(`Ligne ${index} |`).length - 1
      expect(occurrences, `Ligne ${index}`).toBe(1)
    }
  })

  it('une longue désignation passe à la ligne dans sa cellule et la ligne reste entière', () => {
    const long =
      'Désignation volontairement très longue pour vérifier le retour à la ligne dans la colonne, accents compris : é è ê à ç œ'
    const plan = layout(model([table('Lignes', COLUMNS, [row([long, '2', '10000'])])]))
    const text = printedText(plan)

    // Tous les mots sont là, même répartis sur plusieurs lignes.
    for (const word of long.split(' ')) expect(text).toContain(word)
    expect(outOfBounds(plan)).toEqual([])
  })

  it('ne laisse pas le bloc de totaux seul sur une page : la dernière ligne l’accompagne', () => {
    // On cherche le nombre de lignes pour lequel les totaux ne tiendraient pas sous le tableau.
    let verified = false
    for (let count = 60; count <= 80; count += 1) {
      const plan = layout(
        model([
          table('Lignes', COLUMNS, rows(count), { keepLastRows: 1 }),
          totals([totalRow('Total', '1 000 FCFA', true)]),
        ]),
      )
      const last = plan.pages[plan.pages.length - 1]
      const lastLines = planPageLines(last ?? { ops: [] })
      const hasTotal = lastLines.some((line) => line.includes('Total | 1 000 FCFA'))
      expect(hasTotal, `${count} lignes`).toBe(true)
      // La page des totaux contient aussi au moins une ligne du tableau.
      expect(
        lastLines.some((line) => line.startsWith(`Ligne ${count} |`)),
        `${count} lignes`,
      ).toBe(true)
      verified = true
    }
    expect(verified).toBe(true)
  })

  it('découpe, sans rien perdre, une ligne plus haute qu’une page entière', () => {
    const words = Array.from({ length: 1500 }, (_, index) => `mot${index}`).join(' ')
    const plan = layout(model([table('Lignes', COLUMNS, [row([words, '1', '1000'])])]))
    const text = printedText(plan)

    expect(plan.pages.length).toBeGreaterThan(1)
    for (let index = 0; index < 1500; index += 1) {
      expect(text).toMatch(new RegExp(String.raw`\bmot${index}\b`))
    }
    expect(outOfBounds(plan)).toEqual([])
  })

  it('un tableau sans ligne est signalé dans les avertissements', () => {
    const plan = layout(model([table('Vide', COLUMNS, [])]))

    expect(plan.warnings.join('\n')).toContain('ne contient aucune ligne')
  })
})

describe('blocs : titres, paragraphes, groupes, encadrés, signatures', () => {
  it('un titre n’est jamais le dernier élément d’une page', () => {
    // On remplit presque toute la page, puis un titre suivi d’un paragraphe.
    const filler = Array.from({ length: 40 }, () => paragraph('Remplissage.'))
    for (let extra = 0; extra < 8; extra += 1) {
      const plan = layout(
        model([
          ...filler,
          ...Array.from({ length: extra }, () => paragraph('x')),
          heading('Titre'),
          paragraph('Suite du titre.'),
        ]),
      )
      plan.pages.forEach((page, index) => {
        const lines = bodyLines(page)
        if (index < plan.pages.length - 1) {
          expect(lines[lines.length - 1], `extra ${extra} page ${index + 1}`).not.toBe('Titre')
        }
      })
    }
  })

  it('un paragraphe long se coupe entre deux pages sans ligne orpheline ni perte', () => {
    const text = Array.from({ length: 3000 }, (_, index) => `m${index}`).join(' ')
    const plan = layout(model([paragraph(text)]))
    const printed = printedText(plan)

    expect(plan.pages.length).toBeGreaterThan(1)
    for (let index = 0; index < 3000; index += 1) {
      expect(printed).toMatch(new RegExp(String.raw`\bm${index}\b`))
    }
    plan.pages.forEach((page, index) => {
      if (index === 0) return
      expect(bodyLines(page).length, `page ${index + 1}`).toBeGreaterThan(1)
    })
  })

  it('un groupe solidaire qui ne tient pas dans la page restante passe en entier à la suivante', () => {
    const filler = Array.from({ length: 52 }, () => paragraph('Remplissage.'))
    const block = group([heading('Bloc'), paragraph('A.'), paragraph('B.'), paragraph('C.')], true)
    const plan = layout(model([...filler, block]))
    const pageOfBloc = plan.pages.findIndex((page) =>
      planPageLines(page).some((line) => line === 'Bloc'),
    )
    const same = plan.pages.findIndex((page) => planPageLines(page).some((line) => line === 'C.'))

    expect(pageOfBloc).toBeGreaterThanOrEqual(0)
    expect(pageOfBloc).toBe(same)
  })

  it('les champs en deux colonnes tiennent ensemble et gardent libellé et valeur', () => {
    const plan = layout(
      model([
        fields(
          [
            { label: 'Patient', value: 'PATIENT FICTIF' },
            { label: 'Dossier n°', value: 'TEST-P-000001' },
            { label: 'Sexe', value: 'Féminin' },
            { label: 'Né(e) le', value: '17/05/1990' },
          ],
          2,
        ),
      ]),
    )
    const text = printedText(plan)

    for (const expected of [
      'Patient',
      'PATIENT FICTIF',
      'TEST-P-000001',
      'Féminin',
      '17/05/1990',
    ]) {
      expect(text).toContain(expected)
    }
    expect(outOfBounds(plan)).toEqual([])
  })

  it('un encadré de test se dessine avec un trait épais (lisible sans couleur)', () => {
    const plan = layout(model([notice('test', 'Données fictives.', 'DOCUMENT DE TEST')]))
    const rect = plan.pages[0]?.ops.find((op) => op.op === 'rect' && op.lineWidthMm >= 0.7)

    expect(rect).toBeDefined()
    expect(printedText(plan)).toContain('DOCUMENT DE TEST')
  })

  it('les signatures sont des cadres vides étiquetés', () => {
    const plan = layout(
      model([signature([{ label: 'Signature et cachet du professionnel', heightMm: 24 }])]),
    )

    expect(printedText(plan)).toContain('Signature et cachet du professionnel')
    const box = plan.pages[0]?.ops.find((op) => op.op === 'rect' && op.h === 24)
    expect(box).toBeDefined()
  })

  it('un espacement en haut de page est ignoré', () => {
    const plan = layout(model([spacer(40), paragraph('Premier.')]))
    const header = layout(model([paragraph('Premier.')]))

    expect(textsOf(plan, 0).find((op) => op.text === 'Premier.')?.y).toBe(
      textsOf(header, 0).find((op) => op.text === 'Premier.')?.y,
    )
  })
})

describe('texte imprimable', () => {
  it('rapporte les caractères remplacés sans interrompre le document', () => {
    const plan = layout(model([paragraph('Nom \u{0181}ola ✓')]))

    expect(plan.warnings.join('\n')).toContain('Caractères non imprimables remplacés')
    expect(allTexts(plan).join('\n')).not.toContain('✓')
  })

  it('imprime en l’état les accents, les guillemets et l’apostrophe typographique', () => {
    const plan = layout(model([paragraph('L’œil droit « OD » : état été')]))

    expect(printedText(plan)).toContain('L’œil droit « OD » : état été')
    expect(plan.warnings).toEqual([])
  })
})
