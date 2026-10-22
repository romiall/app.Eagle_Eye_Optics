// @vitest-environment node
import { describe, expect, it } from 'vitest'

import {
  demoClinicalPatient,
  demoClinicalRecord,
  demoInvoice,
  demoMedicationOrder,
  demoPrescription,
  demoPrescriptionBody,
  demoPrescriptionNoCorrection,
  demoReceiptDeposit,
  demoReferralLetter,
} from '@/documents/demo/fixtures'
import { NBSP } from '@/documents/engine/charset'
import { DocumentDataError } from '@/documents/engine/errors'
import { MISSING } from '@/documents/engine/format'
import type { DocumentModel, TableBlock } from '@/documents/engine/model'
import { buildClinicalRecord } from '@/documents/templates/clinical/clinicalRecord'
import { buildMedicationOrder } from '@/documents/templates/clinical/medicationOrder'
import { buildOpticalPrescription } from '@/documents/templates/clinical/opticalPrescription'
import { buildReferralLetter } from '@/documents/templates/clinical/referralLetter'
import { buildInvoice } from '@/documents/templates/financial/invoice'
import { buildReceipt } from '@/documents/templates/financial/receipt'

import { FORBIDDEN_WORDS, layout, outOfBounds, printedText } from './support'

const NOW = '2026-10-22T09:00:00.000Z'

function atFirst<T>(items: readonly T[]): T {
  const item = items[0]
  if (item === undefined) throw new Error('Liste vide.')
  return item
}

function orderTable(model: DocumentModel): TableBlock {
  const found = model.body.find((block): block is TableBlock => block.kind === 'table')
  if (found === undefined) throw new Error('Tableau des produits absent.')
  return found
}

function failure(action: () => unknown): DocumentDataError {
  try {
    action()
  } catch (error) {
    if (error instanceof DocumentDataError) return error
    throw error
  }
  throw new Error('Une DocumentDataError était attendue.')
}

describe('prescription optique (fichier 11, AT20)', () => {
  const model = buildOpticalPrescription(demoPrescription())
  const plan = layout(model)
  const text = printedText(plan)

  it('imprime cabinet, patient et dossier, dates, professionnel et version', () => {
    expect(text).toContain('PRESCRIPTION OPTIQUE')
    expect(text).toContain('Version | 1')
    expect(text).toContain('Consultation du | 14/10/2026')
    expect(text).toContain('Validée le | 14/10/2026')
    expect(text).toContain('Patient | PATIENT FICTIF Élodie Marie')
    expect(text).toContain('Dossier n° | TEST-P-000001')
    expect(text).toContain('Sexe | Féminin')
    expect(text).toContain('Né(e) le | 17/05/1990')
    expect(text).toContain('Prescripteur | Professionnel (démonstration)')
  })

  it('n’invente aucune mention professionnelle : emplacement « à renseigner » sur un document de test', () => {
    expect(text).toContain('N° professionnel | à renseigner (mention réelle non fournie)')
    expect(text).toContain('DOCUMENT DE TEST')
    expect(text).toContain('aucune valeur médicale ni légale')
    expect(model.watermark).toBe('DONNÉES DE TEST')
  })

  it('tableau OD puis OG : valeurs, signe explicite, zéro visible, axe en degrés', () => {
    expect(text).toContain(
      'Œil | Sphère (dioptries) | Cylindre (dioptries) | Axe (degrés) | Addition (dioptries)',
    )
    expect(text).toContain('OD (œil droit) | -1,25 | -0,50 | 90° | +2,00')
    expect(text).toContain(`OG (œil gauche) | +0,75 | 0,00 | ${MISSING} | +2,00`)
    expect(text.indexOf('OD (œil droit) | -1,25')).toBeLessThan(
      text.indexOf('OG (œil gauche) | +0,75'),
    )
  })

  it('centrage : DP totale et monoculaire, hauteur de montage, unités visibles', () => {
    expect(text).toContain(`DP totale | 63,00${NBSP}mm`)
    expect(text).toContain(`OD (œil droit) | 31,50${NBSP}mm | 22,00${NBSP}mm`)
    expect(text).toContain(`OG (œil gauche) | 31,50${NBSP}mm | 22,00${NBSP}mm`)
  })

  it('usage, type, indice, traitements et observations viennent de la saisie', () => {
    expect(text).toContain('Usage | Progressifs')
    expect(text).toContain('Type de verre | Progressif')
    expect(text).toContain('Indice | 1.60')
    expect(text).toContain('Traitements | Anti-reflet, Durci / anti-rayures')
    expect(text).toContain(
      'Observations | Observation fictive saisie par le professionnel de démonstration.',
    )
  })

  it('porte un cadre de signature et précise que le nom imprimé n’est pas une signature certifiée', () => {
    expect(text).toContain('Signature et cachet du professionnel')
    expect(text).toContain('n’est pas une signature électronique certifiée')
  })

  it('ne génère aucun texte médical : pas de diagnostic, de conseil ni d’interprétation', () => {
    for (const word of [
      'myopie',
      'hypermétropie',
      'presbytie',
      'astigmatisme',
      'diagnostic',
      'conseil',
    ]) {
      expect(text.toLowerCase(), word).not.toContain(word)
    }
  })

  it('la propriété « Titre » du PDF et le pied de page ne contiennent aucun nom', () => {
    expect(model.title).toBe('Prescription optique - version 1')
    expect(text).toContain('Modèle TEST-1 · Prescription V4W30033 · version 1 · DOCUMENT DE TEST')
    expect(outOfBounds(plan)).toEqual([])
  })

  it('un œil sans correction et des mesures absentes s’impriment par mention et tiret, jamais par zéro', () => {
    const rendered = printedText(layout(buildOpticalPrescription(demoPrescriptionNoCorrection())))

    expect(rendered).toContain(`OD (œil droit) | -1,25 | -0,50 | 90° | ${MISSING}`)
    expect(rendered).toContain(
      `OG (œil gauche) | Absence de correction | ${MISSING} | ${MISSING} | ${MISSING}`,
    )
    expect(rendered).toContain(`DP totale | ${MISSING}`)
    expect(rendered).toContain('Version | 2')
    expect(rendered).not.toMatch(/\| 0,00 \| —/)
  })

  it('un brouillon porte BROUILLON et accepte un manque avec un avertissement', () => {
    const body = demoPrescriptionBody()
    const draft = buildOpticalPrescription(
      demoPrescription({
        status: 'DRAFT',
        validatedAt: null,
        body: {
          ...body,
          eyes: [
            { ...body.eyes[0], axis: null },
            { ...body.eyes[1], sphere: null, cylinder: null, addition: null },
          ],
        },
      }),
    )
    const rendered = printedText(layout(draft))

    expect(draft.watermark).toBe('BROUILLON')
    expect(rendered).toContain('BROUILLON : document non validé par le professionnel.')
    expect(rendered).toContain('Brouillon, version 1')
    expect(draft.warnings).toEqual([
      'OD : un cylindre non nul demande un axe.',
      'OG : ni valeurs saisies ni mention d’absence de correction.',
    ])
  })

  it('refuse d’imprimer une prescription VALIDÉE incomplète : axe manquant, œil vide, absence contredite', () => {
    const body = demoPrescriptionBody()
    const [od, og] = body.eyes
    expect(
      failure(() =>
        buildOpticalPrescription(
          demoPrescription({ body: { ...body, eyes: [{ ...od, axis: null }, og] } }),
        ),
      ).code,
    ).toBe('prescription.axis_missing')
    expect(
      failure(() =>
        buildOpticalPrescription(
          demoPrescription({
            body: {
              ...body,
              eyes: [od, { ...og, sphere: null, cylinder: null, addition: null }],
            },
          }),
        ),
      ).code,
    ).toBe('prescription.eye_empty')
    expect(
      failure(() =>
        buildOpticalPrescription(
          demoPrescription({ body: { ...body, eyes: [od, { ...og, noCorrection: true }] } }),
        ),
      ).code,
    ).toBe('prescription.no_correction_with_values')
  })

  it('refuse un ordre OG puis OD et une validation sans professionnel ni date', () => {
    const body = demoPrescriptionBody()
    const [od, og] = body.eyes
    expect(
      failure(() =>
        buildOpticalPrescription(demoPrescription({ body: { ...body, eyes: [og, od] } })),
      ).code,
    ).toBe('prescription.eye_order')
    expect(
      failure(() => buildOpticalPrescription(demoPrescription({ professional: null }))).code,
    ).toBe('prescription.professional_missing')
    expect(
      failure(() => buildOpticalPrescription(demoPrescription({ validatedAt: null }))).code,
    ).toBe('instant.required')
  })

  it('une ordonnance externe garde sa provenance et le professionnel qui l’a transcrite', () => {
    const external = buildOpticalPrescription(
      demoPrescription({
        origin: 'EXTERNAL',
        consultationAt: null,
        externalPrescriber: 'Dr Externe Fictif',
        externalPrescribedOn: '2026-10-03',
      }),
    )
    const rendered = printedText(layout(external))

    expect(rendered).toContain('Ordonnance du | 03/10/2026')
    expect(rendered).toContain(
      'Ordonnance externe transcrite. Prescripteur d’origine : Dr Externe Fictif.',
    )
    expect(rendered).toContain('Transcription validée par | Professionnel (démonstration)')
    expect(rendered).not.toContain('Consultation du')
    expect(
      failure(() =>
        buildOpticalPrescription(
          demoPrescription({ origin: 'EXTERNAL', externalPrescriber: null }),
        ),
      ).code,
    ).toBe('text.required')
  })

  it('le brouillon sans date demande l’instant de génération', () => {
    expect(
      failure(() =>
        buildOpticalPrescription(
          demoPrescription({ status: 'DRAFT', validatedAt: null, consultationAt: null }),
        ),
      ).code,
    ).toBe('document.date_missing')
    expect(
      buildOpticalPrescription(
        demoPrescription({ status: 'DRAFT', validatedAt: null, consultationAt: null }),
        { now: NOW },
      ).meta.documentAt,
    ).toBe(NOW)
  })

  it('aucun mot interdit', () => {
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern)
  })
})

describe('ordonnance de médicaments (fichier 11)', () => {
  const model = buildMedicationOrder(demoMedicationOrder())
  const text = printedText(layout(model))

  it('imprime patient, date, professionnel et chaque produit tel que saisi', () => {
    expect(text).toContain('ORDONNANCE')
    expect(text).toContain('Date | 14/10/2026')
    expect(text).toContain('Patient | PATIENT FICTIF Élodie Marie')
    expect(text).toContain('Prescripteur | Professionnel (démonstration)')
    expect(text).toContain('Produit fictif A')
    expect(text).toContain('Collyre')
    expect(text).toContain('posologie saisie')
    expect(text).toContain('instruction saisie')
    const first = orderTable(model).rows[0]
    expect(first?.cells).toEqual([
      'Produit fictif A (démonstration)',
      'Collyre',
      'dosage fictif',
      'posologie saisie (exemple fictif)',
      'fréquence saisie (exemple fictif)',
      MISSING,
      MISSING,
    ])
  })

  it('ne complète jamais une information absente : tiret dans le modèle et légende explicite', () => {
    const second = orderTable(model).rows[1]

    expect(orderTable(model).rows).toHaveLength(2)
    // Produit B : forme, dosage, posologie, fréquence et durée non saisis.
    expect(second?.cells.slice(1, 6)).toEqual([MISSING, MISSING, MISSING, MISSING, MISSING])
    expect(second?.cells[6]).toBe('instruction saisie (exemple fictif)')
    expect(text).toContain('L’application ne complète aucune posologie.')
  })

  it('refuse une ordonnance validée sans produit ou avec un produit sans nom', () => {
    const order = demoMedicationOrder()
    expect(failure(() => buildMedicationOrder({ ...order, items: [] })).code).toBe(
      'medication.no_items',
    )
    expect(
      failure(() =>
        buildMedicationOrder({ ...order, items: [{ ...atFirst(order.items), name: ' ' }] }),
      ).code,
    ).toBe('text.required')
  })

  it('un brouillon d’ordonnance est marqué et ne demande aucun produit', () => {
    const draft = buildMedicationOrder({ ...demoMedicationOrder(), status: 'DRAFT', items: [] })

    expect(draft.watermark).toBe('BROUILLON')
  })

  it('en A5 le tableau passe en lignes « libellé : valeur » sans rien perdre', () => {
    const compact = printedText(
      layout(buildMedicationOrder(demoMedicationOrder(), { format: 'A5' })),
    )

    expect(compact).toContain('Forme : Collyre')
    expect(compact).toContain('Dosage : dosage fictif')
    expect(compact).toContain(`Durée : ${MISSING}`)
  })
})

describe('lettre d’orientation (DP §10, AT20)', () => {
  const model = buildReferralLetter(demoReferralLetter())
  const text = printedText(layout(model))

  it('imprime destinataire, objet, urgence, motifs, résumé et mesures choisis par le professionnel', () => {
    expect(text).toContain('LETTRE D’ORIENTATION')
    expect(text).toContain('À l’attention de | Confrère ophtalmologiste')
    expect(text).toContain('Orientation du patient PATIENT FICTIF Élodie Marie')
    expect(text).toContain('TEST-P-000001')
    expect(text).toContain(
      'Je vous adresse le patient désigné ci-dessus pour avis ophtalmologique.',
    )
    expect(text).toContain('Niveau d’urgence | Consultation recommandée')
    expect(text).toContain('Motif(s) d’orientation | Autre')
    expect(text).toContain('Résumé clinique')
    expect(text).toContain('Résumé clinique fictif rédigé par le professionnel de démonstration.')
    expect(text).toContain('Mesures jointes')
    expect(text).toContain('Confraternellement,')
  })

  it('un niveau d’urgence ou un résumé absent est « Non renseigné », jamais déduit', () => {
    const rendered = printedText(
      layout(
        buildReferralLetter({
          ...demoReferralLetter(),
          urgency: null,
          reasons: [],
          summary: null,
          measurements: [],
        }),
      ),
    )

    expect(rendered).toContain('Niveau d’urgence | Non renseigné')
    expect(rendered).toContain('Motif(s) d’orientation | Non renseigné')
    expect(rendered).not.toContain('Mesures jointes')
  })

  it('le niveau d’urgence est un élément clinique : jamais sur une facture ni un reçu', () => {
    const invoice = printedText(layout(buildInvoice(demoInvoice())))
    const receipt = printedText(layout(buildReceipt(demoReceiptDeposit())))

    for (const financial of [invoice, receipt]) {
      expect(financial).not.toContain('urgence')
      expect(financial).not.toContain('Urgence')
      expect(financial).not.toContain('ophtalmologiste')
    }
  })

  it('un destinataire saisi remplace le libellé générique', () => {
    const rendered = printedText(
      layout(buildReferralLetter({ ...demoReferralLetter(), addressee: 'Dr Destinataire Fictif' })),
    )

    expect(rendered).toContain('À l’attention de | Dr Destinataire Fictif')
    expect(rendered).not.toContain('Confrère ophtalmologiste')
  })
})

describe('fiche de consultation et dossier clinique (fichier 11)', () => {
  const model = buildClinicalRecord(demoClinicalRecord())
  const plan = layout(model)
  const text = printedText(plan)

  it('indique la sélection, la date d’export et l’auteur de l’export', () => {
    expect(text).toContain('FICHE DE CONSULTATION')
    expect(text).toContain('Exporté le | 22/10/2026 à 10h00')
    expect(text).toContain('Exporté par | Professionnel (démonstration)')
    expect(text).toContain('Sélection | Une consultation')
    expect(text).toContain('Consultation du 14/10/2026 (version 1, terminée)')
  })

  it('imprime motifs, antécédents, acuité, réfraction, centrage, conclusion et orientation', () => {
    expect(text).toContain('Motifs de consultation | Vision floue, Fatigue visuelle')
    expect(text).toContain('Porte des lunettes | Oui')
    expect(text).toContain('Antécédents oculaires | Aucun connu')
    expect(text).toContain('Antécédents médicaux | Diabète')
    expect(text).toContain('Antécédents familiaux oculaires | Non renseigné')
    expect(text).toContain(
      'Préliminaire | De loin | Sans correction | 5/10 | 6/10 | 7/10 | échelle fictive',
    )
    expect(text).toContain('Subjective | OD (œil droit) | -1,25 | -0,50 | 90° | +2,00')
    expect(text).toContain(`Subjective | OG (œil gauche) | +0,75 | 0,00 | ${MISSING} | +2,00`)
    expect(text).toContain(`DP totale | 63,00${NBSP}mm`)
    expect(text).toContain(
      'Conclusion | Conclusion fictive saisie par le professionnel de démonstration.',
    )
    expect(text).toContain('Orientation | Non')
  })

  it('inclut la prescription versionnée avec ses mesures', () => {
    expect(text).toContain('Prescriptions')
    expect(text).toContain('Prescription optique, version 1 (validée, 14/10/2026)')
    expect(text).toContain('OD (œil droit) | -1,25 | -0,50 | 90° | +2,00')
  })

  it('est confidentiel, sans aucun montant ni donnée financière', () => {
    expect(text).toContain('Document confidentiel : il contient des données de santé.')
    expect(text).not.toContain('FCFA')
    expect(outOfBounds(plan)).toEqual([])
  })

  it('une orientation oui imprime le badge, le niveau d’urgence et les motifs', () => {
    const record = demoClinicalRecord()
    const rendered = printedText(
      layout(
        buildClinicalRecord({
          ...record,
          consultations: [
            {
              ...atFirst(record.consultations),
              referral: true,
              referralUrgency: 'Consultation rapide',
              referralReasons: ['Cataracte'],
            },
          ],
        }),
      ),
    )

    expect(rendered).toContain('Orientation | Orienté vers ophtalmologiste')
    expect(rendered).toContain('Niveau d’urgence | Consultation rapide')
    expect(rendered).toContain('Motif(s) d’orientation | Cataracte')
  })

  it('l’historique indique le nombre de consultations ; une consultation non terminée rend le contenu provisoire', () => {
    const record = demoClinicalRecord()
    const first = atFirst(record.consultations)
    const history = buildClinicalRecord({
      ...record,
      selection: 'HISTORY',
      consultations: [
        first,
        {
          ...first,
          consultationId: '01J9ZXK4M2Q8R7S6T5V4W30038',
          revisionNo: 2,
          status: 'IN_PROGRESS',
        },
      ],
    })
    const rendered = printedText(layout(history))

    expect(rendered).toContain('DOSSIER CLINIQUE')
    expect(rendered).toContain('Historique : 2 consultation(s)')
    expect(rendered).toContain('Sélection | Historique')
    expect(rendered).toContain('Contenu provisoire')
    expect(history.watermark).toBe('BROUILLON')
  })

  it('refuse un dossier vide et une sélection « une consultation » qui en contient plusieurs', () => {
    const record = demoClinicalRecord()
    const first = atFirst(record.consultations)
    expect(
      failure(() => buildClinicalRecord({ ...record, consultations: [], prescriptions: [] })).code,
    ).toBe('record.empty')
    expect(
      failure(() => buildClinicalRecord({ ...record, consultations: [first, first] })).code,
    ).toBe('record.selection_mismatch')
  })

  it('la patiente sans date de naissance ni sexe n’en affiche aucun (rien n’est inventé)', () => {
    const rendered = printedText(
      layout(
        buildClinicalRecord({
          ...demoClinicalRecord(),
          patient: demoClinicalPatient({ sex: null, birthDate: null }),
        }),
      ),
    )

    expect(rendered).not.toContain('Sexe |')
    expect(rendered).not.toContain('Né(e) le |')
  })
})
