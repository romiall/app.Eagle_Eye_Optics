// @vitest-environment node
// « Aucun flottant monétaire » (F08) côté TypeScript, lu sur l'arbre syntaxique du compilateur (une
// expression régulière ou un commentaire ne trompe pas la garde) : ni division `/` ou `/=` hors de
// values/integer.ts (division entière exacte des dates), ni littéral décimal (0.5), ni lecture ou
// arrondi flottants. Montants et quantités se calculent en BigInt.
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

import ts from 'typescript'
import { describe, expect, it } from 'vitest'

const DOMAIN = join(import.meta.dirname, '..', '..', '..', 'resources', 'frontend', 'domain')
const DIVISION_ALLOWED = ['values/integer.ts']
const FORBIDDEN_CALLS = new Set([
  'parseFloat',
  'toFixed',
  'toPrecision',
  'round',
  'floor',
  'ceil',
  'fround',
])

function walk(directory: string): string[] {
  return readdirSync(directory).flatMap((entry) => {
    const path = join(directory, entry)
    return statSync(path).isDirectory() ? walk(path) : path.endsWith('.ts') ? [path] : []
  })
}

/** Constats de la garde pour un source : « ligne : motif ». */
function offences(source: string, divisionAllowed: boolean): string[] {
  const file = ts.createSourceFile('regle.ts', source, ts.ScriptTarget.ES2022, true)
  const found: string[] = []
  const line = (node: ts.Node): number =>
    file.getLineAndCharacterOfPosition(node.getStart()).line + 1

  const visit = (node: ts.Node): void => {
    if (ts.isBinaryExpression(node)) {
      const operator = node.operatorToken.kind
      if (
        !divisionAllowed &&
        (operator === ts.SyntaxKind.SlashToken || operator === ts.SyntaxKind.SlashEqualsToken)
      ) {
        found.push(`${line(node)} : division`)
      }
    }
    if (ts.isNumericLiteral(node) && /[.eE]/.test(node.getText(file).replace(/_/g, ''))) {
      found.push(`${line(node)} : littéral décimal ${node.getText(file)}`)
    }
    if (
      ts.isIdentifier(node) &&
      FORBIDDEN_CALLS.has(node.text) &&
      ts.isCallExpression(node.parent)
    ) {
      found.push(`${line(node)} : ${node.text}()`)
    }
    if (
      ts.isPropertyAccessExpression(node) &&
      FORBIDDEN_CALLS.has(node.name.text) &&
      ts.isCallExpression(node.parent)
    ) {
      found.push(`${line(node)} : ${node.getText(file)}()`)
    }
    ts.forEachChild(node, visit)
  }
  visit(file)
  return found
}

describe('règles pures sans arithmétique flottante', () => {
  it('aucun fichier du domaine (hors types générés) n’emploie de calcul flottant', () => {
    const files = walk(DOMAIN).filter((file) => !file.endsWith('generated.ts'))
    expect(files.length).toBeGreaterThanOrEqual(13)
    for (const file of files) {
      const name = relative(DOMAIN, file).replace(/\\/g, '/')
      expect(offences(readFileSync(file, 'utf8'), DIVISION_ALLOWED.includes(name)), name).toEqual(
        [],
      )
    }
  })

  it('la garde reconnaît les formes interdites et ignore regex et commentaires', () => {
    // Le mutant relevé par la revue F08 : arrondi par flottant, accepté par l'ancienne garde.
    expect(offences('const g = BigInt(Math.trunc(Number(t) / 1000 + 0.5))', false)).toHaveLength(2)
    expect(offences('let x = 10n\nx /= 2n', false)).toHaveLength(1)
    expect(offences('const r = Math.round(1)', false)).toHaveLength(1)
    expect(offences('const v = parseFloat(s)', false)).toHaveLength(1)
    expect(offences('const n = (2).toFixed(2)', false)).toHaveLength(1)
    expect(offences('const re = /^[0-9]+\\/[0-9]+$/u // une / dans un commentaire', false)).toEqual(
      [],
    )
    expect(offences('const d = a / b', true)).toEqual([])
  })
})
