// @vitest-environment node
import { describe, expect, it } from 'vitest'

import { isPdfSafe, isPdfSafeCodePoint, NBSP, toPdfText } from '@/documents/engine/charset'

describe('jeu de caractères imprimable des PDF (polices standard, WinAnsi)', () => {
  it('conserve tel quel tout le français accentué, les guillemets et l’apostrophe typographique', () => {
    const text = 'Élodie Marie-Thérèse : œil, cœur, Œdème, ça, où, naïf, noël, été « citation » l’œil – fin'
    const result = toPdfText(text)

    expect(result.text).toBe(text)
    expect(result.lossy).toEqual([])
    expect(isPdfSafe(text)).toBe(true)
  })

  it('accepte les 27 points de code supplémentaires de la table WinAnsi de jsPDF', () => {
    const extra = [
      0x0152, 0x0153, 0x0160, 0x0161, 0x0178, 0x017d, 0x017e, 0x0192, 0x02c6, 0x02dc, 0x2013,
      0x2014, 0x2018, 0x2019, 0x201a, 0x201c, 0x201d, 0x201e, 0x2020, 0x2021, 0x2022, 0x2026,
      0x2030, 0x2039, 0x203a, 0x20ac, 0x2122,
    ]
    expect(extra).toHaveLength(27)
    for (const codePoint of extra) {
      expect(isPdfSafeCodePoint(codePoint), codePoint.toString(16)).toBe(true)
    }
  })

  it('rejette les points de code hors du jeu : lettres africaines, symboles, émojis', () => {
    for (const codePoint of [0x0181, 0x0189, 0x0300, 0x2713, 0x26a0, 0x1f600, 0x202f, 0x2212]) {
      expect(isPdfSafeCodePoint(codePoint), codePoint.toString(16)).toBe(false)
    }
  })

  it('ramène les espaces fines et les tirets typographiques au jeu, sans perte d’information', () => {
    const result = toPdfText('80\u{202F}000\u{A0}FCFA \u{2212}500 a\u{2011}b\u{2009}c')

    expect(result.text).toBe(`80${NBSP}000${NBSP}FCFA -500 a-b c`)
    expect(result.lossy).toEqual([])
  })

  it('compose une lettre et son accent combinant (NFD) en une lettre accentuée', () => {
    const decomposed = 'E\u{0301}lodie'
    const result = toPdfText(decomposed)

    expect(result.text).toBe('Élodie')
    expect(result.lossy).toEqual([])
  })

  it('remplace ce qui ne peut pas être dessiné ET le signale (jamais en silence)', () => {
    const result = toPdfText('Nom \u{0181}ola \u{2713} ok \u{1F600}')

    expect(result.text).toContain('Nom ')
    expect(result.lossy.length).toBeGreaterThanOrEqual(2)
    expect(result.lossy).toContain('\u{2713}')
    expect(result.lossy).toContain('\u{1F600}')
    // Une lettre décomposable perd son signe mais garde sa lettre de base ; sinon « ? ».
    expect(result.text).toMatch(/\?/)
  })

  it('conserve les retours à la ligne, retire les caractères de contrôle et ramène CRLF à LF', () => {
    const result = toPdfText('a\r\nb\rc\td\u{0007}e\u{200B}f')

    expect(result.text).toBe('a\nb\nc def')
    expect(result.lossy).toEqual([])
  })

  it('isPdfSafe signale toute substitution, même bénigne', () => {
    expect(isPdfSafe('texte simple')).toBe(true)
    expect(isPdfSafe('80\u{202F}000')).toBe(false)
    expect(isPdfSafe('é\u{2713}')).toBe(false)
  })
})
