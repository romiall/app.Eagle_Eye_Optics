import type { DocumentClinic } from '../data/clinic'
import { invalid } from '../engine/errors'
import { formatDateTimeFr, formatXaf, parseInstant } from '../engine/format'
import {
  type Block,
  type CopyInfo,
  type DocumentKind,
  type DocumentModel,
  type FieldRow,
  type Letterhead,
  notice,
  type WatermarkText,
} from '../engine/model'
import type { PageFormatId } from '../engine/units'

/**
 * Éléments communs des modèles (D01, D02) : options de construction, en-tête du cabinet, pied de
 * page, bandeaux BROUILLON / DUPLICATA / TEST, filigrane et assemblage d'un `DocumentModel`. Aucune
 * identité de cabinet n'est écrite ici : tout vient de `DocumentClinic` (configuration). Les emplacements
 * manquants d'un document de TEST sont signalés « à renseigner » ; sur un document non marqué TEST ils
 * sont omis et signalés dans les avertissements du modèle.
 */
export interface BuildOptions {
  format?: PageFormatId
  copy?: CopyInfo
  /**
   * Instant de génération, requis seulement quand la pièce n'a pas d'instant propre (brouillon).
   * Une pièce émise utilise toujours son instant d'émission : un PDF reste identique d'une génération
   * à l'autre.
   */
  now?: string
}

export interface ResolvedOptions {
  format: PageFormatId
  copy: CopyInfo
  now: string | null
}

export function resolveOptions(options: BuildOptions | undefined): ResolvedOptions {
  return {
    format: options?.format ?? 'A4',
    copy: options?.copy ?? { kind: 'ORIGINAL' },
    now: options?.now ?? null,
  }
}

export const PLACEHOLDERS = {
  address: 'Adresse du cabinet : à renseigner',
  contact: 'Téléphone et courriel du cabinet : à renseigner',
  taxIdentifier: 'Identifiant fiscal : à renseigner',
  mentions:
    'Mentions légales et professionnelles du cabinet : à renseigner avant tout usage officiel.',
} as const

export type DocumentFamily = 'financial' | 'clinical'

export const TEST_NOTICE: Readonly<Record<DocumentFamily, { title: string; text: string }>> = {
  financial: {
    title: 'DOCUMENT DE TEST',
    text: 'Données fictives ou mentions du cabinet non validées : ce document n’a aucune valeur comptable ni fiscale.',
  },
  clinical: {
    title: 'DOCUMENT DE TEST',
    text: 'Données fictives ou mentions professionnelles non validées : ce document n’a aucune valeur médicale ni légale.',
  },
}

const DRAFT_NOTICE: Readonly<Record<DocumentFamily, string>> = {
  financial: 'BROUILLON : sans numéro définitif, ce document n’est ni une facture ni un reçu.',
  clinical: 'BROUILLON : document non validé par le professionnel.',
}

// --- Validation -----------------------------------------------------------------------------------

export function requireText(value: string | null, field: string): string {
  if (value === null || value.trim() === '') {
    throw invalid('text.required', field, 'Une valeur obligatoire du document est vide.')
  }
  return value
}

export function requireInstant(value: string | null, field: string): string {
  if (value === null) throw invalid('instant.required', field, 'Une date du document est absente.')
  parseInstant(value, field)
  return value
}

export function requireMoney(value: number, field: string): number {
  formatXaf(value, field)
  if (value < 0) throw invalid('amount.negative', field, 'Montant négatif non admis ici.')
  return value
}

export function requirePositiveMoney(value: number, field: string): number {
  requireMoney(value, field)
  if (value === 0) throw invalid('amount.not_positive', field, 'Montant strictement positif requis.')
  return value
}

// --- En-tête, pied de page ------------------------------------------------------------------------

function nonEmptyLines(text: string): string[] {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '')
}

export function letterheadOf(clinic: DocumentClinic, family: DocumentFamily): Letterhead {
  const test = clinic.testDocument
  const lines: string[] = []
  if (clinic.address !== null) lines.push(...nonEmptyLines(clinic.address))
  else if (test) lines.push(PLACEHOLDERS.address)
  const contact: string[] = []
  if (clinic.phone !== null) contact.push(`Tél. : ${clinic.phone}`)
  if (clinic.email !== null) contact.push(`Courriel : ${clinic.email}`)
  if (contact.length > 0) lines.push(...contact)
  else if (test) lines.push(PLACEHOLDERS.contact)
  if (family === 'financial') {
    if (clinic.taxIdentifier !== null) lines.push(`Identifiant fiscal : ${clinic.taxIdentifier}`)
    else if (test) lines.push(PLACEHOLDERS.taxIdentifier)
  }
  lines.push(...clinic.settings.headerLines)
  return {
    organization: clinic.name,
    lines,
    logo: clinic.logo,
    logoPlaceholder: clinic.logo === null && test,
  }
}

export function footerLinesOf(
  clinic: DocumentClinic,
  templateVersion: string,
  number: string | null,
): string[] {
  const lines: string[] = []
  if (clinic.professionalMentions !== null) lines.push(...nonEmptyLines(clinic.professionalMentions))
  else if (clinic.testDocument) lines.push(PLACEHOLDERS.mentions)
  lines.push(...clinic.settings.footerLines)
  const reference = number === null ? '' : ` \u{00B7} ${number}`
  const test = clinic.testDocument ? ' \u{00B7} DOCUMENT DE TEST' : ''
  lines.push(`Modèle ${templateVersion}${reference}${test}`)
  return lines
}

function clinicWarnings(clinic: DocumentClinic, family: DocumentFamily): string[] {
  if (clinic.testDocument) return []
  const warnings: string[] = []
  if (clinic.address === null) warnings.push('Adresse du cabinet absente de la configuration.')
  if (family === 'financial' && clinic.taxIdentifier === null) {
    warnings.push('Identifiant fiscal du cabinet absent de la configuration.')
  }
  if (clinic.professionalMentions === null) {
    warnings.push('Mentions professionnelles du cabinet absentes de la configuration.')
  }
  return warnings
}

// --- Assemblage -----------------------------------------------------------------------------------

export interface ModelSpec {
  kind: DocumentKind
  templateVersion: string
  clinic: DocumentClinic
  options: ResolvedOptions
  family: DocumentFamily
  /** Titre imprimé en tête de page (« FACTURE »). */
  heading: string
  subtitle: string | null
  /** Numéro définitif de la pièce, null pour un brouillon ou une pièce sans numéro. */
  number: string | null
  /** Instant de la pièce ; null pour un brouillon (l'instant de génération sert alors). */
  documentAt: string | null
  draft: boolean
  headerFields: readonly FieldRow[]
  /** Propriété « Titre » du PDF : type et numéro, jamais un nom de personne. */
  pdfTitle: string
  body: readonly Block[]
  warnings?: readonly string[]
  /** Référence courte imprimée au pied de page quand la pièce n'a pas de numéro (prescription, version). */
  reference?: string
}

function watermarkOf(spec: ModelSpec): WatermarkText | null {
  if (spec.draft) return 'BROUILLON'
  if (spec.options.copy.kind === 'DUPLICATA') return 'DUPLICATA'
  return spec.clinic.testDocument ? 'DONNÉES DE TEST' : null
}

function duplicateText(copy: Extract<CopyInfo, { kind: 'DUPLICATA' }>, timeZone: string): string {
  return (
    `DUPLICATA : réimpression n° ${copy.reprintNumber} du ${formatDateTimeFr(copy.reprintedAt, timeZone)}. ` +
    'Cette copie ne crée aucune nouvelle pièce ; l’original conserve son numéro.'
  )
}

/** Assemble le modèle : bandeaux, en-tête, pied de page, filigrane et métadonnées. */
export function assembleModel(spec: ModelSpec): DocumentModel {
  const documentAt = spec.documentAt ?? spec.options.now
  if (documentAt === null) {
    throw invalid(
      'document.date_missing',
      'now',
      'Un brouillon demande l’instant de génération (option « now »).',
    )
  }
  parseInstant(documentAt, 'document_at')
  if (spec.options.copy.kind === 'DUPLICATA') {
    parseInstant(spec.options.copy.reprintedAt, 'reprinted_at')
    if (spec.draft) {
      throw invalid('duplicate.draft', 'copy', 'Un brouillon n’a pas de duplicata.')
    }
  }

  const banners: Block[] = []
  if (spec.clinic.testDocument) {
    banners.push(notice('test', TEST_NOTICE[spec.family].text, TEST_NOTICE[spec.family].title))
  }
  if (spec.draft) banners.push(notice('warning', DRAFT_NOTICE[spec.family]))
  if (spec.options.copy.kind === 'DUPLICATA') {
    banners.push(notice('warning', duplicateText(spec.options.copy, spec.clinic.timezone)))
  }

  return {
    schema: 1,
    kind: spec.kind,
    templateVersion: spec.templateVersion,
    title: spec.pdfTitle,
    format: spec.options.format,
    meta: {
      documentNumber: spec.number,
      documentAt,
      copy: spec.options.copy,
      draft: spec.draft,
      test: spec.clinic.testDocument,
      timeZone: spec.clinic.timezone,
    },
    letterhead: letterheadOf(spec.clinic, spec.family),
    titleBlock: { title: spec.heading, subtitle: spec.subtitle, fields: spec.headerFields },
    body: [...banners, ...spec.body],
    footerLines: footerLinesOf(spec.clinic, spec.templateVersion, spec.number ?? spec.reference ?? null),
    watermark: watermarkOf(spec),
    warnings: [...clinicWarnings(spec.clinic, spec.family), ...(spec.warnings ?? [])],
  }
}
