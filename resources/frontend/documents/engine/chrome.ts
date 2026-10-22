import { r3, type PageFlow } from './flow'
import type { DocumentModel } from './model'
import type { TextOp } from './plan'
import { lineHeightMm } from './units'

/**
 * Éléments répétés d'un document (D01) : en-tête du cabinet et bloc titre en première page, rappel
 * compact en haut des pages suivantes, pied de page (mentions, modèle, « Page k/N ») et filigrane.
 * Le pied de page et le filigrane sont posés une fois les pages connues. Rien n'est inventé : une
 * mention absente de la configuration du cabinet n'est pas imprimée (ou est signalée « à renseigner »
 * sur un document de test, voir `templates/common.ts`).
 */
const MUTED_GRAY = 85
const RULE_GRAY = 120
const WATERMARK_GRAY = 222
const PLACEHOLDER_WIDTH = 36
const PLACEHOLDER_HEIGHT = 14

export interface FooterLayout {
  lines: string[]
  height: number
  sizePt: number
  textWidth: number
}

/** Pied de page : lignes découpées et hauteur réservée (le corps du document s'arrête au-dessus). */
export function prepareFooter(flow: PageFlow, model: DocumentModel): FooterLayout {
  const sizePt = flow.pt(7.5)
  const textWidth = flow.continuous ? flow.contentWidth : flow.contentWidth - 24
  const wrapped = model.footerLines.flatMap((line) => flow.wrap(line, textWidth, sizePt, 'normal'))
  const lines = wrapped.length > 0 ? wrapped : ['']
  return { lines, height: lines.length * lineHeightMm(sizePt) + 2.4, sizePt, textWidth }
}

function drawWideHeader(flow: PageFlow, model: DocumentModel): number {
  const top = flow.y
  const leftWidth = flow.contentWidth * 0.5
  const rightX = flow.left + leftWidth + 6
  const rightWidth = flow.right - rightX
  const { letterhead, titleBlock } = model

  let leftY = top
  const logo = letterhead.logo
  if (logo !== null) {
    const ratio = Math.min(Math.min(46, leftWidth) / logo.widthMm, 22 / logo.heightMm, 1)
    const width = logo.widthMm * ratio
    const height = logo.heightMm * ratio
    flow.push({ op: 'image', x: r3(flow.left), y: r3(leftY), w: r3(width), h: r3(height), logo })
    leftY += height + 2
  } else if (letterhead.logoPlaceholder) {
    flow.rect(flow.left, leftY, PLACEHOLDER_WIDTH, PLACEHOLDER_HEIGHT, {
      strokeGray: RULE_GRAY,
      lineWidthMm: 0.25,
      dashMm: 1.2,
    })
    const labelPt = flow.pt(7)
    flow.lines(
      ['LOGO À FOURNIR'],
      flow.left,
      leftY + (PLACEHOLDER_HEIGHT - lineHeightMm(labelPt)) / 2,
      PLACEHOLDER_WIDTH,
      labelPt,
      'bold',
      'center',
      110,
    )
    leftY += PLACEHOLDER_HEIGHT + 2
  }
  const organizationPt = flow.pt(13)
  leftY += flow.lines(
    flow.wrap(letterhead.organization, leftWidth, organizationPt, 'bold'),
    flow.left,
    leftY,
    leftWidth,
    organizationPt,
    'bold',
  )
  const detailPt = flow.pt(8.5)
  for (const line of letterhead.lines) {
    leftY += flow.lines(
      flow.wrap(line, leftWidth, detailPt, 'normal'),
      flow.left,
      leftY,
      leftWidth,
      detailPt,
      'normal',
      'left',
      60,
    )
  }

  let rightY = top
  const titlePt = flow.pt(17)
  rightY += flow.lines(
    flow.wrap(titleBlock.title, rightWidth, titlePt, 'bold'),
    rightX,
    rightY,
    rightWidth,
    titlePt,
    'bold',
    'right',
  )
  if (titleBlock.subtitle !== null) {
    const subtitlePt = flow.pt(9.5)
    rightY += flow.lines(
      flow.wrap(titleBlock.subtitle, rightWidth, subtitlePt, 'normal'),
      rightX,
      rightY,
      rightWidth,
      subtitlePt,
      'normal',
      'right',
      MUTED_GRAY,
    )
  }
  rightY += 1.5
  const labelPt = flow.pt(8.5)
  const valuePt = flow.pt(9.5)
  const labelWidth = Math.min(rightWidth * 0.4, 30)
  const valueWidth = rightWidth - labelWidth - 2
  for (const field of titleBlock.fields) {
    const labelLines = flow.wrap(field.label, labelWidth, labelPt, 'normal')
    const valueLines = flow.wrap(field.value, valueWidth, valuePt, 'bold')
    flow.lines(labelLines, rightX, rightY, labelWidth, labelPt, 'normal', 'left', MUTED_GRAY)
    flow.lines(valueLines, rightX + labelWidth + 2, rightY, valueWidth, valuePt, 'bold', 'right')
    rightY +=
      Math.max(
        labelLines.length * lineHeightMm(labelPt),
        valueLines.length * lineHeightMm(valuePt),
      ) + 0.6
  }

  const bottom = Math.max(leftY, rightY)
  flow.line(flow.left, bottom + 1.5, flow.right, bottom + 1.5, 0.5, 0)
  return bottom + 4.5
}

function drawNarrowHeader(flow: PageFlow, model: DocumentModel): number {
  let y = flow.y
  const { letterhead, titleBlock } = model
  const width = flow.contentWidth
  if (model.watermark !== null) {
    const bannerPt = flow.pt(10)
    y += flow.lines([`*** ${model.watermark} ***`], flow.left, y, width, bannerPt, 'bold', 'center')
    y += 1
  }
  const organizationPt = flow.pt(11)
  y += flow.lines(
    flow.wrap(letterhead.organization, width, organizationPt, 'bold'),
    flow.left,
    y,
    width,
    organizationPt,
    'bold',
    'center',
  )
  const detailPt = flow.pt(7.5)
  for (const line of letterhead.lines) {
    y += flow.lines(
      flow.wrap(line, width, detailPt, 'normal'),
      flow.left,
      y,
      width,
      detailPt,
      'normal',
      'center',
      60,
    )
  }
  y += 1
  flow.line(flow.left, y, flow.right, y, 0.3, 0, 1.2)
  y += 1.5
  const titlePt = flow.pt(11)
  y += flow.lines(
    flow.wrap(titleBlock.title, width, titlePt, 'bold'),
    flow.left,
    y,
    width,
    titlePt,
    'bold',
    'center',
  )
  if (titleBlock.subtitle !== null) {
    const subtitlePt = flow.pt(8.5)
    y += flow.lines(
      flow.wrap(titleBlock.subtitle, width, subtitlePt, 'normal'),
      flow.left,
      y,
      width,
      subtitlePt,
      'normal',
      'center',
      MUTED_GRAY,
    )
  }
  y += 1
  const labelPt = flow.pt(8)
  const labelWidth = width * 0.4
  const valueWidth = width - labelWidth - 1.5
  for (const field of titleBlock.fields) {
    const labelLines = flow.wrap(field.label, labelWidth, labelPt, 'normal')
    const valueLines = flow.wrap(field.value, valueWidth, labelPt, 'bold')
    flow.lines(labelLines, flow.left, y, labelWidth, labelPt, 'normal', 'left', MUTED_GRAY)
    flow.lines(valueLines, flow.left + labelWidth + 1.5, y, valueWidth, labelPt, 'bold', 'right')
    y += Math.max(labelLines.length, valueLines.length) * lineHeightMm(labelPt) + 0.4
  }
  y += 0.5
  flow.line(flow.left, y, flow.right, y, 0.3, 0, 1.2)
  return y + 2
}

/** Première page : cabinet à gauche, titre et références de la pièce à droite ; rend l'ordonnée du contenu. */
export function drawFirstHeader(flow: PageFlow, model: DocumentModel): number {
  return flow.contentWidth >= 120 ? drawWideHeader(flow, model) : drawNarrowHeader(flow, model)
}

/** Pages suivantes : rappel du cabinet et de la pièce sur une ligne, filet dessous. */
export function drawContinuationHeader(flow: PageFlow, model: DocumentModel): number {
  const top = flow.format.marginTopMm
  const sizePt = flow.pt(9)
  const leftWidth = flow.contentWidth * 0.55
  const rightWidth = flow.contentWidth - leftWidth - 4
  const reference =
    model.meta.documentNumber === null
      ? model.titleBlock.title
      : `${model.titleBlock.title} ${model.meta.documentNumber}`
  const leftLines = flow.wrap(model.letterhead.organization, leftWidth, sizePt, 'bold')
  const rightLines = flow.wrap(`${reference} (suite)`, rightWidth, sizePt, 'normal')
  flow.lines(leftLines, flow.left, top, leftWidth, sizePt, 'bold')
  flow.lines(rightLines, flow.right - rightWidth, top, rightWidth, sizePt, 'normal', 'right')
  const height = Math.max(leftLines.length, rightLines.length) * lineHeightMm(sizePt)
  flow.line(flow.left, top + height + 1, flow.right, top + height + 1, 0.3, 0)
  return top + height + 4
}

function watermarkOp(flow: PageFlow, text: string, pageHeightMm: number): TextOp {
  const angle = 45
  const diagonal = Math.hypot(flow.format.widthMm, pageHeightMm)
  const target = diagonal * 0.72
  let sizePt = 70
  let width = flow.width(text, sizePt, 'bold')
  if (width > target) {
    sizePt = Math.floor(((sizePt * target) / width) * 10) / 10
    width = flow.width(text, sizePt, 'bold')
  }
  const radians = (angle * Math.PI) / 180
  return {
    op: 'text',
    x: r3(flow.format.widthMm / 2 - (width / 2) * Math.cos(radians)),
    y: r3(pageHeightMm / 2 + (width / 2) * Math.sin(radians)),
    text: flow.safe(text),
    sizePt,
    style: 'bold',
    gray: WATERMARK_GRAY,
    angle,
  }
}

/**
 * Pied de page de chaque page et filigrane. Le filigrane (BROUILLON, DUPLICATA, DONNÉES DE TEST) est
 * un grand texte gris clair en diagonale, posé SOUS le contenu ; il se lit aussi en noir et blanc. Un
 * ticket continu n'a pas de filigrane : sa mention est une ligne en tête (voir `drawNarrowHeader`).
 */
export function drawPageFurniture(
  flow: PageFlow,
  model: DocumentModel,
  footer: FooterLayout,
  pageHeightMm: number,
  contentEndY: number,
): void {
  const count = flow.pages.length
  const footerTop = flow.continuous
    ? contentEndY + 1
    : pageHeightMm - flow.format.marginBottomMm - footer.height
  flow.pages.forEach((page, index) => {
    flow.onPage(index, () => {
      flow.line(flow.left, footerTop, flow.right, footerTop, 0.2, RULE_GRAY)
      flow.lines(
        footer.lines,
        flow.left,
        footerTop + 1,
        footer.textWidth,
        footer.sizePt,
        'normal',
        'left',
        MUTED_GRAY,
      )
      if (!flow.continuous) {
        flow.lines(
          [`Page ${index + 1}/${count}`],
          flow.right - 22,
          footerTop + 1,
          22,
          footer.sizePt,
          'normal',
          'right',
          MUTED_GRAY,
        )
      }
    })
    if (model.watermark !== null && !flow.continuous) {
      page.ops.unshift(watermarkOp(flow, model.watermark, pageHeightMm))
    }
  })
}
