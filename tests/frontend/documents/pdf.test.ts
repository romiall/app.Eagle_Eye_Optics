// @vitest-environment node
import { jsPDF } from 'jspdf'
import { describe, expect, it } from 'vitest'

import { DEMO_DOCUMENTS } from '@/documents/demo/catalog'
import {
  demoInvoice,
  demoLongInvoice,
  demoReceiptDeposit,
  demoReceiptSettlement,
} from '@/documents/demo/fixtures'
import { NBSP } from '@/documents/engine/charset'
import { sha256Hex } from '@/documents/engine/hash'
import { ASCII_FIRST, ASCII_LAST, glyphUnits } from '@/documents/engine/metrics'
import { createJsPdfMeasurer, pdfDateOf, renderDocument, renderPdf } from '@/documents/engine/pdf'
import { buildInvoice } from '@/documents/templates/financial/invoice'
import { buildReceipt } from '@/documents/templates/financial/receipt'

import { outOfBounds } from './support'

/** Octets du PDF lus comme du latin1 : suffisant pour retrouver le dictionnaire d'informations. */
function raw(bytes: Uint8Array): string {
  return new TextDecoder('latin1').decode(bytes)
}

describe('rendu PDF local (jsPDF, polices standard, aucun réseau)', () => {
  const model = buildReceipt(demoReceiptDeposit())
  const bytes = renderPdf(model)
  const text = raw(bytes)

  it('produit un vrai PDF : en-tête, fin de fichier, compression', () => {
    expect(text.startsWith('%PDF-')).toBe(true)
    expect(text.trimEnd().endsWith('%%EOF')).toBe(true)
    expect(text).toContain('/FlateDecode')
    expect(bytes.byteLength).toBeGreaterThan(1000)
  })

  it('est reproductible : même modèle, mêmes octets, même empreinte (réimpression = même pièce)', async () => {
    const again = renderPdf(buildReceipt(demoReceiptDeposit()))

    expect(again).toEqual(bytes)
    expect(await sha256Hex(again)).toBe(await sha256Hex(bytes))
  })

  it('un autre contenu donne d’autres octets', () => {
    expect(renderPdf(buildReceipt(demoReceiptSettlement()))).not.toEqual(bytes)
  })

  it('un duplicata est un autre fichier ; l’original, régénéré, reste identique', () => {
    const copy = renderPdf(
      buildReceipt(demoReceiptDeposit(), {
        copy: { kind: 'DUPLICATA', reprintNumber: 1, reprintedAt: '2026-10-22T08:30:00.000Z' },
      }),
    )

    expect(copy).not.toEqual(bytes)
    expect(renderPdf(buildReceipt(demoReceiptDeposit()))).toEqual(bytes)
  })

  it('date de création = instant de la pièce (UTC), pas l’heure de la génération', () => {
    expect(text).toContain("/CreationDate (D:20261014093000+00'00')")
    expect(pdfDateOf('2026-10-14T09:30:00.000Z')).toBe("D:20261014093000+00'00'")
    expect(pdfDateOf('1969-12-31T23:59:59.000Z')).toBe("D:20260101000000+00'00'")
    expect(pdfDateOf('2099-01-01T00:00:00.000Z')).toBe("D:20260101000000+00'00'")
    expect(pdfDateOf('pas une date')).toBe("D:20260101000000+00'00'")
  })

  it('propriétés du PDF : titre = type et numéro, jamais un nom de personne', () => {
    expect(text).toContain('TEST-REC-000001')
    expect(text).not.toContain('PATIENT')
    expect(text).not.toContain('lodie')
    expect(text).toContain('/Keywords (RECEIPT)')
  })

  it('A4 : page de 210 × 297 mm', () => {
    const match = /\/MediaBox \[0 0 ([0-9.]+) ([0-9.]+)\]/.exec(text)

    expect(Number(match?.[1])).toBeCloseTo(595.28, 1)
    expect(Number(match?.[2])).toBeCloseTo(841.89, 1)
  })

  it('ticket 80 mm : page continue, largeur 80 mm, une seule page', () => {
    const ticket = raw(renderPdf(buildReceipt(demoReceiptDeposit(), { format: 'TICKET_80' })))
    const match = /\/MediaBox \[0 0 ([0-9.]+) ([0-9.]+)\]/.exec(ticket)

    expect(Number(match?.[1])).toBeCloseTo(226.77, 1)
    expect(Number(match?.[2])).toBeGreaterThan(200)
    expect(Number(match?.[2])).toBeLessThan(800)
    expect(ticket.match(/\/Type \/Page\b/g)).toHaveLength(1)
  })

  it('une facture longue compte autant de pages que son plan de mise en page', () => {
    const rendered = renderDocument(buildInvoice(demoLongInvoice(48)))
    const declared =
      /\/Type \/Pages[^>]*\/Count (\d+)/.exec(raw(rendered.bytes)) ??
      /\/Count (\d+)/.exec(raw(rendered.bytes))

    expect(rendered.pageCount).toBeGreaterThan(1)
    expect(Number(declared?.[1])).toBe(rendered.pageCount)
  })

  it('aucune requête réseau ni ressource distante dans le fichier', () => {
    expect(text).not.toMatch(/https?:\/\//)
  })
})

describe('mesure du texte par les métriques de Helvetica', () => {
  const measurer = createJsPdfMeasurer()

  it('mesure la largeur réelle (sans crénage) en millimètres', () => {
    // Helvetica : H 722 + e 556 + l 222 + l 222 + o 556 = 2278 / 1000 em ; 10 pt = 3,5278 mm.
    expect(measurer.width('Hello', 10, 'normal')).toBeCloseTo(8.037, 2)
    // Helvetica-Bold : H 722 + e 556 + l 278 + l 278 + o 611 = 2445 / 1000 em.
    expect(measurer.width('Hello', 10, 'bold')).toBeCloseTo(8.625, 2)
    // Sans crénage : « AV » vaut la somme de ses lettres (667 + 667).
    expect(measurer.width('AV', 10, 'normal')).toBeCloseTo(4.705, 2)
  })

  it('l’italique garde les largeurs de la police droite, le gras italique celles du gras', () => {
    expect(measurer.width('Hello', 10, 'italic')).toBeCloseTo(
      measurer.width('Hello', 10, 'normal'),
      5,
    )
    expect(measurer.width('Hello', 10, 'bolditalic')).toBeCloseTo(
      measurer.width('Hello', 10, 'bold'),
      5,
    )
  })

  it('mesure une lettre accentuée comme sa lettre de base et croît avec le corps', () => {
    expect(measurer.width('é', 10, 'normal')).toBeCloseTo(measurer.width('e', 10, 'normal'), 1)
    expect(measurer.width('Hello', 20, 'normal')).toBeCloseTo(
      2 * measurer.width('Hello', 10, 'normal'),
      3,
    )
  })

  it('l’espace insécable a exactement la largeur d’une espace (jsPDF lui donnerait celle d’un « n »)', () => {
    expect(measurer.width(NBSP, 10, 'normal')).toBeCloseTo(measurer.width(' ', 10, 'normal'), 5)
    expect(measurer.width(`80${NBSP}000`, 10, 'normal')).toBeCloseTo(
      measurer.width('80 000', 10, 'normal'),
      5,
    )
  })

  it('corrige les caractères que jsPDF mesure avec sa largeur par défaut : « œ », « × », « € »', () => {
    // Helvetica : oe 944, multiply 584, Euro 556 (jsPDF donnerait 530 à chacun).
    expect(measurer.width('œ', 10, 'normal')).toBeCloseTo(3.33, 1)
    expect(measurer.width('×', 10, 'normal')).toBeCloseTo(2.06, 1)
    expect(measurer.width('€', 10, 'normal')).toBeCloseTo(1.96, 1)
  })

  it('les tables exactes concordent avec celles de jsPDF à un centième de corps près (ASCII)', () => {
    const doc = new jsPDF({ unit: 'mm', format: [100, 100] })
    for (const bold of [false, true]) {
      doc.setFont('helvetica', bold ? 'bold' : 'normal')
      for (let codePoint = ASCII_FIRST; codePoint <= ASCII_LAST; codePoint += 1) {
        const mine = (glyphUnits(codePoint, bold) ?? Number.NaN) / 1000
        const theirs = doc.getStringUnitWidth(String.fromCharCode(codePoint), { doKerning: false })
        const label = `${bold ? 'gras' : 'droit'} U+${codePoint.toString(16)}`
        expect(Math.abs(mine - theirs), label).toBeLessThanOrEqual(0.0101)
      }
    }
  })

  it('ne renvoie une largeur exacte que pour les caractères connus', () => {
    expect(glyphUnits(0x41, false)).toBe(667)
    expect(glyphUnits(0xa0, true)).toBe(278)
    expect(glyphUnits(0xe9, false)).toBeNull()
    expect(glyphUnits(0x2019, false)).toBeNull()
  })
})

describe('tous les documents de démonstration, avec les vraies métriques', () => {
  it.each(DEMO_DOCUMENTS.map((entry) => [entry.id, entry] as const))(
    '%s : PDF valide, sans avertissement, tout dans les marges',
    (_id, entry) => {
      const rendered = renderDocument(entry.build())
      const measurer = createJsPdfMeasurer()

      expect(raw(rendered.bytes).startsWith('%PDF-')).toBe(true)
      expect(rendered.pageCount).toBeGreaterThanOrEqual(1)
      expect(rendered.warnings).toEqual([])
      expect(outOfBounds(rendered.plan, measurer)).toEqual([])
    },
  )

  it('la facture courte tient sur une page A4', () => {
    expect(renderDocument(buildInvoice(demoInvoice())).pageCount).toBe(1)
  })
})
