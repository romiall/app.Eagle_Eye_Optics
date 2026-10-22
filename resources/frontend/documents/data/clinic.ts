import type { ClinicDocumentIdentity, InvoiceClinicSnapshot } from '@/domain/contracts/generated'

import type { DocumentLogo } from '../engine/model'

/**
 * Identité du cabinet imprimée sur les documents (D01, D02). Elle vient TOUJOURS de la configuration
 * du cabinet : le snapshot `clinic_snapshot` figé à l'émission pour une pièce financière, les
 * paramètres du cabinet (`ClinicSettings`) pour un document clinique. Rien n'est écrit en dur dans
 * un modèle : logo, adresse, identifiant fiscal et mentions professionnelles sont « attendus du
 * client » (fichier 11, Q03). Tant qu'ils manquent, le document est un document de TEST marqué
 * (`testDocument`) et les emplacements manquants sont signalés « à renseigner ».
 */
export interface DocumentSettings {
  /** Lignes ajoutées sous les coordonnées du cabinet, en tête de page. */
  headerLines: readonly string[]
  /** Lignes ajoutées au pied de page. */
  footerLines: readonly string[]
  /** Conditions de règlement de la facture, texte validé par la gérante (jamais inventé). */
  paymentTerms: string | null
}

export const EMPTY_DOCUMENT_SETTINGS: DocumentSettings = {
  headerLines: [],
  footerLines: [],
  paymentTerms: null,
}

export interface DocumentClinic {
  name: string
  address: string | null
  phone: string | null
  email: string | null
  taxIdentifier: string | null
  professionalMentions: string | null
  settings: DocumentSettings
  currency: 'XAF'
  /** Fuseau du cabinet : toutes les dates imprimées sont à cette heure. */
  timezone: string
  /** Vrai tant que les paramètres réels ne sont pas validés (`production_documents_enabled` faux). */
  testDocument: boolean
  logo: DocumentLogo | null
}

const SETTINGS_LINES_MAX = 6
const SETTINGS_LINE_LENGTH = 200
const SETTINGS_TEXT_LENGTH = 1000

function textLines(value: unknown): string[] {
  if (!Array.isArray(value)) return []
  return value
    .filter((line): line is string => typeof line === 'string')
    .map((line) => line.trim())
    .filter((line) => line !== '')
    .slice(0, SETTINGS_LINES_MAX)
    .map((line) => line.slice(0, SETTINGS_LINE_LENGTH))
}

/**
 * Lit `document_settings` du cabinet (objet JSON libre côté serveur). Clés connues : `header_lines`,
 * `footer_lines` (listes de textes) et `payment_terms` (texte). Toute autre clé est ignorée ; une
 * valeur d'un autre type, vide ou trop longue est écartée ou tronquée : un réglage malformé ne
 * bloque jamais une impression.
 */
export function readDocumentSettings(raw: unknown): DocumentSettings {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
    return EMPTY_DOCUMENT_SETTINGS
  }
  const source = raw as Record<string, unknown>
  const terms = source['payment_terms']
  return {
    headerLines: textLines(source['header_lines']),
    footerLines: textLines(source['footer_lines']),
    paymentTerms:
      typeof terms === 'string' && terms.trim() !== ''
        ? terms.trim().slice(0, SETTINGS_TEXT_LENGTH)
        : null,
  }
}

function blankToNull(value: string | null): string | null {
  if (value === null) return null
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

/**
 * Cabinet d'un document CLINIQUE (D02) : le cabinet du compte connecté (`/auth/me`, `ClinicSummary`).
 * Seuls le nom, le fuseau et la monnaie y figurent ; adresse, contacts, identifiant fiscal, mentions et
 * logo ne sont lisibles que par la gérante (`/clinic/settings`) : ils restent absents, jamais inventés.
 * `testDocument` suit `production_documents_enabled` comme `clinic_snapshot.test_document` des pièces
 * financières : tant que les mentions réelles ne sont pas validées, le document est marqué « DOCUMENT DE
 * TEST » et les emplacements manquants sont dits « à renseigner ».
 */
export function clinicFromSummary(summary: {
  name: string
  timezone: string
  currency: 'XAF'
  production_documents_enabled: boolean
}): DocumentClinic {
  return {
    name: summary.name,
    address: null,
    phone: null,
    email: null,
    taxIdentifier: null,
    professionalMentions: null,
    settings: EMPTY_DOCUMENT_SETTINGS,
    currency: summary.currency,
    timezone: summary.timezone,
    testDocument: !summary.production_documents_enabled,
    logo: null,
  }
}

/** Cabinet d'une pièce financière : le snapshot figé à l'émission, jamais la configuration actuelle. */
export function clinicFromSnapshot(
  snapshot: InvoiceClinicSnapshot,
  logo: DocumentLogo | null = null,
): DocumentClinic {
  return {
    name: snapshot.name,
    address: blankToNull(snapshot.address),
    phone: blankToNull(snapshot.phone),
    email: blankToNull(snapshot.email),
    taxIdentifier: blankToNull(snapshot.tax_identifier),
    professionalMentions: blankToNull(snapshot.professional_mentions),
    settings: readDocumentSettings(snapshot.document_settings),
    currency: snapshot.currency,
    timezone: snapshot.timezone,
    testDocument: snapshot.test_document,
    logo,
  }
}

/**
 * Cabinet d'un document produit maintenant (document clinique, aperçu) à partir de l'identité imprimable du
 * cabinet (`GET /clinic/document-identity`, lisible par tout profil qui produit un document) : nom,
 * coordonnées, identifiant fiscal, mentions et réglages des documents tels que la gérante les a saisis. À
 * préférer à `clinicFromSummary`, qui n'a que le nom. Une pièce financière émise garde, elle, son snapshot.
 */
export function clinicFromIdentity(
  identity: ClinicDocumentIdentity,
  logo: DocumentLogo | null = null,
): DocumentClinic {
  return {
    name: identity.name,
    address: blankToNull(identity.address),
    phone: blankToNull(identity.phone),
    email: blankToNull(identity.email),
    taxIdentifier: blankToNull(identity.tax_identifier),
    professionalMentions: blankToNull(identity.professional_mentions),
    settings: readDocumentSettings(identity.document_settings),
    currency: identity.currency,
    timezone: identity.timezone,
    testDocument: !identity.production_documents_enabled,
    logo,
  }
}
