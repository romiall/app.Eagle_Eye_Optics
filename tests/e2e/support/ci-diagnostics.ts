import { closeSync, existsSync, openSync, readSync, statSync } from 'node:fs'

import type { Reporter, TestCase, TestResult } from '@playwright/test/reporter'

/**
 * Diagnostic des échecs e2e dans la CI (GitHub Actions) : les journaux du job ne se lisent pas sans compte, mais les
 * annotations oui. Pour chaque test en échec, la fin du journal du serveur de test et de celui de l'application est
 * publiée en annotation (commande de workflow `::error`). Données fictives ; sans effet hors de la CI.
 */
const FILES = ['storage/logs/e2e-php-server.log', 'storage/logs/laravel.log']
const TAIL_BYTES = 3500

function tail(path: string): string {
  if (!existsSync(path)) return '(fichier absent)'
  const size = statSync(path).size
  const start = Math.max(0, size - TAIL_BYTES)
  const buffer = Buffer.alloc(size - start)
  const handle = openSync(path, 'r')
  try {
    readSync(handle, buffer, 0, buffer.length, start)
  } finally {
    closeSync(handle)
  }
  return buffer.toString('utf8')
}

function escapeData(text: string): string {
  return text.replace(/%/g, '%25').replace(/\r/g, '%0D').replace(/\n/g, '%0A')
}

function escapeProperty(text: string): string {
  return escapeData(text).replace(/:/g, '%3A').replace(/,/g, '%2C')
}

export default class CiDiagnostics implements Reporter {
  onTestEnd(test: TestCase, result: TestResult): void {
    if (process.env['GITHUB_ACTIONS'] !== 'true') return
    if (result.status === 'passed' || result.status === 'skipped') return
    for (const file of FILES) {
      const title = `${test.title.slice(0, 50)} - ${file}`
      console.log(`::error title=${escapeProperty(title)}::${escapeData(tail(file))}`)
    }
  }
}
