import { afterEach, describe, expect, it, vi } from 'vitest'

import { submitLegacyBalances, uploadLegacyEvidence } from '@/app/services/legacyBalances'
import {
  legacyReferenceKey,
  legacyTemplateCsv,
  openingOrProblem,
  readLegacyCsv,
} from '@/domain/billing/legacyImport'
import { nonEmpty } from '@/domain/values/list'

/**
 * Reprise des créances papier (X01) : lecture du carnet en CSV sur le poste (reste dû par la règle jumelle,
 * jamais arrondi ni deviné ; problèmes dits avec la ligne du fichier ; à la moindre erreur, aucune ligne
 * reprise), envoi du justificatif en octets bruts avec son empreinte, aperçu et reprise avec la clé fournie.
 * Données fictives.
 */
afterEach(() => {
  vi.unstubAllGlobals()
})

describe('lecture du carnet', () => {
  it('le modèle se relit : 80 000 dont 30 000 réglés donnent 50 000 de reste dû', () => {
    const parsed = readLegacyCsv(legacyTemplateCsv())
    expect(parsed.problems).toEqual([])
    expect(parsed.rows[0]).toMatchObject({
      line: 2,
      patient_id: null,
      patient_record_number: 'EE-2026-0001',
      paper_reference: 'EX-CARNET-0001',
      original_total: 80000,
      already_paid: 30000,
      original_invoice_on: '2025-11-04',
    })
    expect(parsed.openings).toEqual([50000, 45000])
  })

  it('un déjà-réglé vide vaut 0 ; un total vide est une erreur', () => {
    const parsed = readLegacyCsv(
      'reference_papier;dossier;total_origine;deja_paye\nR1;EE-2026-0001;10 000;\nR2;EE-2026-0001;;',
    )
    expect(parsed.rows).toEqual([])
    expect(parsed.problems).toEqual([
      { line: 3, column: 'total_origine', message: 'Le montant est obligatoire.' },
    ])
  })

  it('références en double, montants incohérents et dates illisibles sont dits ligne par ligne', () => {
    const csv = [
      'Référence papier;Dossier;Total origine;Déjà payé;Date pièce',
      'Carnet-Été-1;EE-2026-0001;80 000;30 000;',
      'CARNET-ETE-1;EE-2026-0002;10 000;0;',
      'C-3;EE-2026-0003;10 000;10 000;',
      'C-4;EE-2026-0004;12,5;0;',
      'C-5;;10 000;0;31/02/2025',
    ].join('\n')
    const parsed = readLegacyCsv(csv)
    expect(parsed.rows).toEqual([])
    expect(parsed.problems.map((problem) => [problem.line, problem.column])).toEqual([
      [3, 'reference_papier'],
      [4, 'deja_paye'],
      [5, 'total_origine'],
      [6, 'dossier'],
      [6, 'date_piece'],
    ])
  })

  it('la règle jumelle refuse une pièce papier déjà entièrement réglée', () => {
    expect(openingOrProblem(80000, 30000)).toBe(50000)
    expect(typeof openingOrProblem(80000, 80000)).toBe('string')
    expect(typeof openingOrProblem(80000, 90000)).toBe('string')
  })

  it('les références se comparent comme la base (casse et accents ignorés, ponctuation gardée)', () => {
    expect(legacyReferenceKey('Carnet-Été-1')).toBe(legacyReferenceKey('CARNET-ETE-1'))
    expect(legacyReferenceKey('CARNET 1')).not.toBe(legacyReferenceKey('CARNET-1'))
  })
})

describe('envoi au serveur', () => {
  it('le justificatif part en octets bruts, avec son type et son empreinte', async () => {
    const fetchMock = vi.fn<typeof fetch>(
      async () =>
        new Response(
          JSON.stringify({
            data: {
              file_id: '01J9ZXK4M2Q8R7S6T5V4W3EV01',
              sha256: 'a'.repeat(64),
              mime_type: 'image/jpeg',
              size_bytes: 4,
            },
          }),
          { status: 201 },
        ),
    )
    vi.stubGlobal('fetch', fetchMock)
    const file = new File([new Uint8Array([0xff, 0xd8, 0xff, 0xe0])], 'carnet.jpg', {
      type: 'image/jpeg',
    })

    const evidence = await uploadLegacyEvidence(file)

    expect(evidence.file_id).toBe('01J9ZXK4M2Q8R7S6T5V4W3EV01')
    const [url, init] = fetchMock.mock.calls[0] ?? []
    expect(url).toBe('/api/v1/legacy-balances/evidence')
    expect(init?.body).toBeInstanceOf(Uint8Array)
    const headers = init?.headers as Record<string, string>
    expect(headers['Content-Type']).toBe('image/jpeg')
    expect(headers['X-Content-Sha256']).toMatch(/^[0-9a-f]{64}$/)
    expect(headers['Idempotency-Key']).toMatch(/^ev-[0-9a-f]{32}$/)
  })

  it('l’aperçu et la reprise portent la clé fournie par l’écran', async () => {
    const fetchMock = vi.fn<typeof fetch>(
      async () =>
        new Response(
          JSON.stringify({
            data: { dry_run: true, rows: 1, accepted: 1, rejected: [], created_ids: [] },
          }),
          {
            status: 200,
          },
        ),
    )
    vi.stubGlobal('fetch', fetchMock)

    const report = await submitLegacyBalances(
      {
        dry_run: true,
        evidence_file_id: '01J9ZXK4M2Q8R7S6T5V4W3EV01',
        rows: nonEmpty(readLegacyCsv(legacyTemplateCsv()).rows),
      },
      '01J9ZXK4M2Q8R7S6T5V4W3KEY1',
    )

    expect(report.accepted).toBe(1)
    const [url, init] = fetchMock.mock.calls[0] ?? []
    expect(url).toBe('/api/v1/imports/legacy-balances')
    expect((init?.headers as Record<string, string>)['Idempotency-Key']).toBe(
      '01J9ZXK4M2Q8R7S6T5V4W3KEY1',
    )
  })
})
