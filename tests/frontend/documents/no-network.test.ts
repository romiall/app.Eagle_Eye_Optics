// @vitest-environment node
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * Le moteur de documents est LOCAL (D01, fichier 11 : « jsPDF : fonte française embarquée, tableaux
 * paginés, logo/templates locaux, génération sans CDN ou réseau ») : aucun appel réseau, aucune
 * ressource distante, aucun stockage navigateur de donnée de santé, aucune dépendance ajoutée.
 */
const ROOT = join(import.meta.dirname, '..', '..', '..')
const FRONTEND = join(ROOT, 'resources', 'frontend')

function walk(directory: string): string[] {
  return readdirSync(directory).flatMap((entry) => {
    const path = join(directory, entry)
    if (statSync(path).isDirectory()) return walk(path)
    return /\.(ts|vue)$/.test(path) ? [path] : []
  })
}

const FILES = [
  ...walk(join(FRONTEND, 'documents')),
  ...walk(join(FRONTEND, 'components', 'documents')),
  ...walk(join(FRONTEND, 'pages', 'documents')),
  join(FRONTEND, 'app', 'services', 'documents.ts'),
]

/** Source sans commentaires : les explications peuvent parler de réseau sans en faire. */
function code(path: string): string {
  return readFileSync(path, 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/.*$/gm, '$1')
}

const NAMES = (paths: readonly string[]): string[] =>
  paths.map((path) => relative(FRONTEND, path).replace(/\\/g, '/'))

describe('génération locale des documents : aucun réseau', () => {
  it('lit bien les sources du moteur', () => {
    expect(FILES.length).toBeGreaterThan(30)
    expect(NAMES(FILES)).toContain('documents/engine/pdf.ts')
    expect(NAMES(FILES)).toContain('app/services/documents.ts')
  })

  it.each([
    ['fetch', /\bfetch\s*\(/],
    ['XMLHttpRequest', /XMLHttpRequest/],
    ['WebSocket', /WebSocket/],
    ['EventSource', /EventSource/],
    ['sendBeacon', /sendBeacon/],
    ['importScripts', /importScripts/],
    ['client HTTP de l’application', /@\/app\/api|apiRequest/],
    ['client HTTP du coffre', /@\/offline/],
  ])('aucun appel à %s', (_label, pattern) => {
    const offenders = FILES.filter((path) => pattern.test(code(path)))

    expect(NAMES(offenders)).toEqual([])
  })

  it('aucune adresse distante (seul l’espace de noms SVG est une chaîne http)', () => {
    for (const path of FILES) {
      const urls = [...code(path).matchAll(/https?:\/\/[^\s'"`)]+/g)].map((match) => match[0])
      const foreign = urls.filter((url) => url !== 'http://www.w3.org/2000/svg')

      expect(foreign, relative(FRONTEND, path)).toEqual([])
    }
  })

  it('aucune donnée de santé dans le stockage du navigateur, aucun journal console', () => {
    for (const path of FILES) {
      const source = code(path)

      expect(source, relative(FRONTEND, path)).not.toMatch(
        /localStorage|sessionStorage|document\.cookie/,
      )
      expect(source, relative(FRONTEND, path)).not.toMatch(/\bconsole\.\w+/)
    }
  })

  it('aucune dépendance externe hors jsPDF et Vue (aucune bibliothèque ajoutée)', () => {
    const allowed = new Set(['jspdf', 'vue'])
    for (const path of FILES) {
      const specifiers = [...code(path).matchAll(/from\s+'([^']+)'/g)].map(
        (match) => match[1] ?? '',
      )
      for (const specifier of specifiers) {
        if (specifier.startsWith('.') || specifier.startsWith('@/')) continue
        expect(allowed.has(specifier), `${relative(FRONTEND, path)} importe ${specifier}`).toBe(
          true,
        )
      }
    }
  })

  it('le moteur de rendu n’est chargé qu’à la demande (jsPDF hors du paquet principal)', () => {
    const service = code(join(FRONTEND, 'app', 'services', 'documents.ts'))

    expect(service).toMatch(/await import\('@\/documents\/engine\/pdf'\)/)
    expect(service).not.toMatch(/from '@\/documents\/engine\/pdf'/)
  })

  it('jsPDF n’est importé que par le moteur de rendu', () => {
    const importers = FILES.filter((path) => /from\s+'jspdf'/.test(code(path)))

    expect(NAMES(importers)).toEqual(['documents/engine/pdf.ts'])
  })

  it('les polices sont celles du format PDF : aucun fichier de police ni @font-face', () => {
    for (const path of FILES) {
      expect(code(path), relative(FRONTEND, path)).not.toMatch(
        /@font-face|\.ttf|\.woff|addFileToVFS|addFont\(/,
      )
    }
  })
})

describe('dépendances du projet', () => {
  it('jspdf est la seule bibliothèque de génération de PDF', () => {
    const manifest = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8')) as {
      dependencies: Record<string, string>
      devDependencies: Record<string, string>
    }
    const names = Object.keys({ ...manifest.dependencies, ...manifest.devDependencies })

    expect(names).toContain('jspdf')
    for (const other of [
      'pdfmake',
      'pdf-lib',
      'pdfkit',
      'html2pdf.js',
      '@react-pdf/renderer',
      'puppeteer',
      'playwright-pdf',
    ]) {
      expect(names, other).not.toContain(other)
    }
  })
})
