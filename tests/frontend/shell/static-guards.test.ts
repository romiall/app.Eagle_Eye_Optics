import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * Gardes statiques du frontend (fichier 04, fichier 19) : aucune donnée dans localStorage ou
 * sessionStorage (le coffre local chiffré est le seul stockage des saisies) et aucune ressource
 * distante (fonte, style, script) : l'application doit fonctionner hors ligne depuis sa seule
 * origine.
 */
const ROOT = join(import.meta.dirname, '..', '..', '..')
const SOURCES = [join(ROOT, 'resources', 'frontend'), join(ROOT, 'resources', 'views')]

function files(directory: string): string[] {
  return readdirSync(directory).flatMap((entry) => {
    const path = join(directory, entry)
    if (statSync(path).isDirectory()) return files(path)
    return /\.(?:ts|vue|css|php)$/.test(entry) ? [path] : []
  })
}

const sources = SOURCES.flatMap(files).map((path) => ({
  path: relative(ROOT, path),
  text: readFileSync(path, 'utf8'),
}))

describe('gardes statiques du frontend', () => {
  it('lit bien des sources du frontend', () => {
    expect(sources.some((source) => source.path.endsWith('App.vue'))).toBe(true)
    expect(sources.some((source) => source.path.endsWith('spa.blade.php'))).toBe(true)
  })

  it('n’utilise ni localStorage ni sessionStorage', () => {
    const offenders = sources
      .filter((source) => /\b(?:local|session)Storage\s*[.[]/.test(source.text))
      .map((source) => source.path)
    expect(offenders).toEqual([])
  })

  it('ne charge aucune ressource distante (fonte, style, script, image, appel)', () => {
    const remote =
      /(?:url\(\s*['"]?|@import\s+['"]|(?:src|href)\s*=\s*['"]|fetch\(\s*['"`])https?:\/\//i
    const offenders = sources.filter((source) => remote.test(source.text)).map((s) => s.path)
    expect(offenders).toEqual([])
  })
})
