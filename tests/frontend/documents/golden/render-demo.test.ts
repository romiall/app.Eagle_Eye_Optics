// @vitest-environment node
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

import { beforeAll, describe, expect, it } from 'vitest'

import { DEMO_DOCUMENTS } from '@/documents/demo/catalog'
import { sha256Hex } from '@/documents/engine/hash'
import { renderDocument } from '@/documents/engine/pdf'
import { pageToSvg, planText } from '@/documents/engine/preview'

/**
 * Rendu « golden » des documents de démonstration (D01, D02) : voir `golden/README.md`.
 *
 * Trois usages, selon `EEO_DOCUMENT_GOLDEN` :
 *  - non défini : compare chaque document aux fichiers de `tests/fixtures/document-snapshots/` quand
 *    ils existent (texte de mise en page, nombre de pages, empreinte SHA-256 du PDF). Sans fichier de
 *    référence, le test du document est IGNORÉ (« skipped ») : la revue visuelle initiale n'a pas eu
 *    lieu, rien n'est prétendu vérifié ;
 *  - `render` : écrit PDF, SVG de chaque page, texte et `index.html` dans `test-results/documents/`
 *    pour la REVUE VISUELLE (ouvrir `index.html`, imprimer les PDF en A4 noir et blanc) ;
 *  - `update` : comme `render`, puis réécrit les fichiers de référence. À faire seulement APRÈS la
 *    revue visuelle, et jamais pour « faire passer » un test : un modèle publié (version `TEST-1`) ne
 *    se modifie pas, on ajoute une nouvelle version de modèle (voir `docs/implementation/documents.md`).
 */
const ROOT = join(import.meta.dirname, '..', '..', '..', '..')
const MODE = process.env['EEO_DOCUMENT_GOLDEN'] ?? ''
const OUTPUT = join(ROOT, 'test-results', 'documents')
const GOLDEN = join(ROOT, 'tests', 'fixtures', 'document-snapshots')

interface Rendered {
  id: string
  label: string
  pages: number
  sha256: string
  text: string
  /** Contenu du fichier de référence. */
  file: string
}

const results = new Map<string, Rendered>()

async function renderAll(): Promise<void> {
  for (const entry of DEMO_DOCUMENTS) {
    const rendered = renderDocument(entry.build())
    const text = planText(rendered.plan)
    const sha256 = await sha256Hex(rendered.bytes)
    results.set(entry.id, {
      id: entry.id,
      label: entry.label,
      pages: rendered.pageCount,
      sha256,
      text,
      file: `# ${entry.id} : ${rendered.pageCount} page(s), PDF sha256 ${sha256}\n${text}\n`,
    })
    if (MODE === 'render' || MODE === 'update') {
      mkdirSync(OUTPUT, { recursive: true })
      writeFileSync(join(OUTPUT, `${entry.id}.pdf`), rendered.bytes)
      writeFileSync(join(OUTPUT, `${entry.id}.txt`), `${text}\n`, 'utf8')
      rendered.plan.pages.forEach((_page, index) => {
        const svg = pageToSvg(rendered.plan, index)
        writeFileSync(join(OUTPUT, `${entry.id}-p${index + 1}.svg`), svg, 'utf8')
      })
    }
  }
}

function indexHtml(): string {
  const sections = [...results.values()].map((result) => {
    const pages = Array.from({ length: result.pages }, (_unused, index) => {
      const name = `${result.id}-p${index + 1}.svg`
      return `<a href="${name}"><img src="${name}" alt="${result.label}, page ${index + 1}" width="420"></a>`
    }).join('\n')
    return [
      `<section><h2>${result.label}</h2>`,
      `<p><a href="${result.id}.pdf">PDF</a> · ${result.pages} page(s) · <code>${result.sha256}</code></p>`,
      `${pages}</section>`,
    ].join('\n')
  })
  return [
    '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Documents de démonstration</title>',
    '<body style="font-family:sans-serif"><h1>Documents de démonstration (données fictives)</h1>',
    ...sections,
    '</body></html>',
    '',
  ].join('\n')
}

describe('rendu golden des documents de démonstration', () => {
  beforeAll(async () => {
    await renderAll()
    if (MODE === 'render' || MODE === 'update') {
      writeFileSync(join(OUTPUT, 'index.html'), indexHtml(), 'utf8')
    }
    if (MODE === 'update') {
      mkdirSync(GOLDEN, { recursive: true })
      for (const result of results.values()) {
        writeFileSync(join(GOLDEN, `${result.id}.txt`), result.file, 'utf8')
      }
    }
  }, 120_000)

  it('rend chaque document de démonstration : au moins une page, texte non vide, empreinte SHA-256', () => {
    expect(results.size).toBe(DEMO_DOCUMENTS.length)
    for (const result of results.values()) {
      expect(result.pages, result.id).toBeGreaterThanOrEqual(1)
      expect(result.text.length, result.id).toBeGreaterThan(50)
      expect(result.sha256, result.id).toMatch(/^[0-9a-f]{64}$/)
    }
  })

  it.skipIf(MODE !== 'render' && MODE !== 'update')(
    'écrit l’index de revue visuelle (PDF, pages SVG, textes)',
    () => {
      expect(existsSync(join(OUTPUT, 'index.html'))).toBe(true)
      for (const result of results.values()) {
        expect(existsSync(join(OUTPUT, `${result.id}.pdf`)), result.id).toBe(true)
        expect(existsSync(join(OUTPUT, `${result.id}-p1.svg`)), result.id).toBe(true)
      }
    },
  )

  for (const entry of DEMO_DOCUMENTS) {
    const reference = join(GOLDEN, `${entry.id}.txt`)
    it.skipIf(!existsSync(reference))(
      `${entry.id} : conforme au fichier de référence (texte, pages, empreinte du PDF)`,
      () => {
        const result = results.get(entry.id)
        expect(result?.file).toBe(readFileSync(reference, 'utf8'))
      },
    )
  }
})
