import { describe, expect, it } from 'vitest'

import { clinicFromIdentity, clinicFromSummary } from '@/documents/data/clinic'

/**
 * Identité du cabinet sur un document produit maintenant (`GET /clinic/document-identity`) : coordonnées,
 * identifiant fiscal, mentions et réglages saisis par la gérante, jamais inventés ; blancs ramenés à « absent » ;
 * document de TEST tant que les documents officiels ne sont pas activés. Données fictives.
 */
describe('clinicFromIdentity', () => {
  const identity = {
    name: 'CABINET FICTIF',
    timezone: 'Africa/Douala',
    currency: 'XAF' as const,
    address: 'Rue fictive 1',
    phone: '   ',
    email: null,
    tax_identifier: 'NIU-FICTIF-0001',
    professional_mentions: 'Mentions fictives',
    document_settings: {
      header_lines: ['Ligne fictive'],
      payment_terms: 'Conditions fictives',
      autre: 1,
    },
    production_documents_enabled: false,
  }

  it('reprend les champs saisis et marque le document de test', () => {
    const clinic = clinicFromIdentity(identity)

    expect(clinic.name).toBe('CABINET FICTIF')
    expect(clinic.address).toBe('Rue fictive 1')
    expect(clinic.phone).toBeNull()
    expect(clinic.taxIdentifier).toBe('NIU-FICTIF-0001')
    expect(clinic.professionalMentions).toBe('Mentions fictives')
    expect(clinic.settings.headerLines).toEqual(['Ligne fictive'])
    expect(clinic.settings.paymentTerms).toBe('Conditions fictives')
    expect(clinic.testDocument).toBe(true)
    expect(clinic.logo).toBeNull()
  })

  it('documents officiels activés : plus de marque de test', () => {
    expect(
      clinicFromIdentity({ ...identity, production_documents_enabled: true }).testDocument,
    ).toBe(false)
  })

  it('apporte plus que le résumé du compte, qui n’a que le nom', () => {
    const summary = clinicFromSummary({
      name: identity.name,
      timezone: identity.timezone,
      currency: 'XAF',
      production_documents_enabled: false,
    })
    expect(summary.address).toBeNull()
    expect(clinicFromIdentity(identity).address).not.toBeNull()
  })
})
