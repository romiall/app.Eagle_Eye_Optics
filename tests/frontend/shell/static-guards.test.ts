import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * Gardes statiques du frontend (fichier 04, fichier 19) : aucune donnée dans localStorage ou
 * sessionStorage (le coffre local chiffré est le seul stockage des saisies) et aucune ressource ni
 * connexion distante (fonte, style, script, image, appel, balise, socket) : l'application doit
 * fonctionner hors ligne depuis sa seule origine. Les commentaires sont retirés avant l'analyse :
 * seul le code compte. La preuve d'exécution reste l'e2e « aucune ressource hors de l'origine ».
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

/** Retire les commentaires `/* … *\/`, `<!-- … -->`, `{{-- … --}}` et `// …` (hors URL `://`). */
function withoutComments(text: string): string {
  return text
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\{\{--[\s\S]*?--\}\}/g, '')
    .replace(/(^|[^:'"`\\])\/\/[^\n]*/g, '$1')
}

const sources = SOURCES.flatMap(files).map((path) => ({
  path: relative(ROOT, path),
  code: withoutComments(readFileSync(path, 'utf8')),
}))

function offenders(pattern: RegExp): string[] {
  return sources.filter((source) => pattern.test(source.code)).map((source) => source.path)
}

describe('gardes statiques du frontend', () => {
  it('lit bien des sources du frontend, commentaires retirés', () => {
    expect(sources.some((source) => source.path.endsWith('App.vue'))).toBe(true)
    expect(sources.some((source) => source.path.endsWith('spa.blade.php'))).toBe(true)
    expect(withoutComments('a // localStorage\nb /* sessionStorage */ c')).not.toMatch(/Storage/)
    expect(withoutComments("fetch('https://exemple.test')")).toContain('https://')
  })

  it('n’utilise ni localStorage ni sessionStorage, même indirectement', () => {
    expect(offenders(/\b(?:local|session)Storage\b/)).toEqual([])
    // Accès par nom calculé : window['local' + 'Storage'].
    expect(offenders(/['"`](?:local|session)['"`]\s*\+/)).toEqual([])
  })

  it('ne charge ni n’appelle aucune ressource distante', () => {
    const remoteUrl = String.raw`(?:https?:)?\/\/[a-z0-9.-]`
    const patterns = [
      new RegExp(String.raw`url\(\s*['"]?${remoteUrl}`, 'i'),
      new RegExp(String.raw`@import\s+(?:url\(\s*)?['"]?${remoteUrl}`, 'i'),
      new RegExp(String.raw`\b(?:src|href|action|srcset)\s*=\s*['"]${remoteUrl}`, 'i'),
      new RegExp(
        String.raw`\b(?:fetch|import|sendBeacon|EventSource|WebSocket)\s*\(\s*['"\`]${remoteUrl}`,
        'i',
      ),
      /\bnew\s+WebSocket\s*\(/,
      /\bsendBeacon\s*\(/,
    ]
    for (const pattern of patterns) expect(offenders(pattern), String(pattern)).toEqual([])
  })
})
