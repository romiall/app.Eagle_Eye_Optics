import { afterEach, describe, expect, it, vi } from 'vitest'

import { currentDocumentClinic } from '@/app/services/clinicIdentity'
import { getClinicDocumentIdentity } from '@/app/services/staff'
import { session } from '@/app/session'

vi.mock('@/app/services/staff', () => ({ getClinicDocumentIdentity: vi.fn() }))

/**
 * Identité du cabinet d'un document produit maintenant (ordonnance, fiche, lettre, relevé de caisse) :
 * celle des paramètres saisis par la gérante ; si la lecture échoue, le seul nom du cabinet de la session,
 * sans rien inventer. Données fictives.
 */
describe('currentDocumentClinic', () => {
  const summary = {
    name: 'CABINET FICTIF',
    timezone: 'Africa/Douala',
    currency: 'XAF' as const,
    production_documents_enabled: false,
  }

  function signIn(): void {
    session.account = { id: '01J9ZXK4M2Q8R7S6T5V4W30001', display_name: 'Compte fictif', clinic: summary } as never
  }

  afterEach(() => {
    session.account = null
    vi.mocked(getClinicDocumentIdentity).mockReset()
  })

  it('reprend l’identité saisie par la gérante', async () => {
    signIn()
    vi.mocked(getClinicDocumentIdentity).mockResolvedValue({
      ...summary,
      address: 'Rue fictive 1',
      phone: '+237 600 00 00 00',
      email: null,
      tax_identifier: null,
      professional_mentions: 'Mentions fictives',
      document_settings: {},
    } as never)

    const clinic = await currentDocumentClinic()

    expect(clinic.address).toBe('Rue fictive 1')
    expect(clinic.professionalMentions).toBe('Mentions fictives')
    expect(clinic.testDocument).toBe(true)
  })

  it('lecture impossible : le nom du cabinet de la session, aucun champ inventé', async () => {
    signIn()
    vi.mocked(getClinicDocumentIdentity).mockRejectedValue(new Error('réseau indisponible'))

    const clinic = await currentDocumentClinic()

    expect(clinic.name).toBe('CABINET FICTIF')
    expect(clinic.address).toBeNull()
    expect(clinic.phone).toBeNull()
    expect(clinic.professionalMentions).toBeNull()
  })

  it('sans session vérifiée : refus explicite, aucune lecture', async () => {
    await expect(currentDocumentClinic()).rejects.toThrow('reconnectez-vous')
    expect(getClinicDocumentIdentity).not.toHaveBeenCalled()
  })
})
