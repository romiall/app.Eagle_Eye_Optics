// @vitest-environment node
// « Aucun flottant monétaire » (F08) côté TypeScript : les règles pures n'emploient ni lecture
// flottante, ni arrondi flottant, ni formatage décimal approché. Montants et quantités se calculent
// en BigInt ; seules les dates emploient Math.trunc sur des entiers sûrs.
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

import { describe, expect, it } from 'vitest'

const DOMAIN = join(import.meta.dirname, '..', '..', '..', 'resources', 'frontend', 'domain')
const FORBIDDEN = /\b(?:parseFloat|toFixed|toPrecision)\b|Math\.(?:round|floor|ceil|fround)\b/

function walk(directory: string): string[] {
  return readdirSync(directory).flatMap((entry) => {
    const path = join(directory, entry)
    return statSync(path).isDirectory() ? walk(path) : path.endsWith('.ts') ? [path] : []
  })
}

describe('règles pures sans arithmétique flottante', () => {
  it('aucun fichier du domaine (hors types générés) n’emploie de calcul flottant', () => {
    const files = walk(DOMAIN).filter((file) => !file.endsWith('generated.ts'))
    expect(files.length).toBeGreaterThanOrEqual(12)
    for (const file of files) {
      const lines = readFileSync(file, 'utf8').split('\n')
      lines.forEach((line, index) => {
        const code = line.replace(/\/\/.*$/, '')
        expect(
          FORBIDDEN.test(code),
          `${relative(DOMAIN, file)}:${index + 1} : ${line.trim()}`,
        ).toBe(false)
      })
    }
  })
})
