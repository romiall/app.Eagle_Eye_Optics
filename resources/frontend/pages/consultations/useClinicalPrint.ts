import { ref, shallowRef } from 'vue'

import { ApiError, NetworkUnavailable } from '@/app/api'
import { ClinicalPrintRefused } from '@/app/services/clinicalDocuments'
import { DocumentAccessError, type RenderedDocument } from '@/app/services/documents'
import { revalidateSession } from '@/app/session'
import { DocumentDataError } from '@/documents/engine/errors'

/** Un document à préparer : identifiant stable, libellé du bouton, nom de l'aperçu et préparation. */
export interface PrintAction {
  id: string
  label: string
  heading: string
  run: () => Promise<RenderedDocument>
}

export type PrintStatus = 'idle' | 'loading' | 'ready' | 'error'

export interface PrintFailure {
  title: string
  message: string
}

/**
 * Message d'un échec de préparation. Aucun détail technique ni aucune valeur du document n'est affiché :
 * les messages du moteur (`DocumentDataError`) et des refus (`ClinicalPrintRefused`) n'en contiennent pas.
 * Rien n'est jamais préparé depuis une copie ancienne ou la saisie en cours quand la lecture échoue.
 */
export function printFailure(error: unknown): PrintFailure {
  if (error instanceof DocumentAccessError) {
    return {
      title: 'Droits insuffisants',
      message: 'Ce compte n’a pas le droit d’ouvrir ce document.',
    }
  }
  if (error instanceof ClinicalPrintRefused) {
    return { title: 'Document non disponible', message: error.message }
  }
  if (error instanceof DocumentDataError) {
    return { title: 'Le document n’a pas pu être préparé', message: error.message }
  }
  if (error instanceof NetworkUnavailable) {
    return {
      title: 'Serveur injoignable',
      message:
        'La version enregistrée n’a pas pu être relue : rien n’est préparé à partir d’une copie ancienne. Réessayez quand la connexion revient.',
    }
  }
  if (error instanceof ApiError) {
    if (error.status === 401 || error.status === 419) {
      return {
        title: 'Session expirée',
        message: 'Reconnectez-vous, puis demandez de nouveau l’aperçu.',
      }
    }
    if (error.status === 403) {
      return { title: 'Droits insuffisants', message: 'Ce compte n’a pas accès à ce dossier.' }
    }
    if (error.status === 404) {
      return {
        title: 'Version introuvable',
        message: 'La version enregistrée n’existe pas ou plus : rechargez la page.',
      }
    }
    if (error.status === 429) {
      return {
        title: 'Trop de lectures',
        message:
          'Trop de lectures du dossier en peu de temps : patientez une minute puis recommencez.',
      }
    }
    return {
      title: 'Lecture refusée',
      message: 'Le serveur n’a pas rendu la version enregistrée : rien n’est préparé.',
    }
  }
  return {
    title: 'Le document n’a pas pu être préparé',
    message: 'Le PDF n’a pas pu être généré sur ce poste.',
  }
}

/**
 * État de la préparation d'un document clinique (un seul à la fois) : chargement, aperçu prêt, échec.
 * Une demande plus récente annule l'affichage d'une précédente encore en cours. Rien n'est conservé dans
 * le navigateur ; le PDF est préparé sur ce poste par le service des documents.
 */
export function useClinicalPrint() {
  const status = ref<PrintStatus>('idle')
  const rendered = shallowRef<RenderedDocument | null>(null)
  const heading = ref('')
  const failure = ref<PrintFailure | null>(null)
  const active = ref<string | null>(null)
  const announcement = ref('')
  let generation = 0

  async function prepare(action: PrintAction): Promise<void> {
    generation += 1
    const current = generation
    active.value = action.id
    heading.value = action.heading
    rendered.value = null
    failure.value = null
    status.value = 'loading'
    announcement.value = `Préparation du PDF : ${action.heading}.`
    try {
      const result = await action.run()
      if (current !== generation) return
      rendered.value = result
      status.value = 'ready'
      announcement.value = `Aperçu prêt : ${action.heading}.`
    } catch (error) {
      if (current !== generation) return
      failure.value = printFailure(error)
      status.value = 'error'
      announcement.value = ''
      if (error instanceof ApiError && (error.status === 401 || error.status === 419)) {
        void revalidateSession().catch(() => undefined)
      }
    }
  }

  function close(): void {
    generation += 1
    active.value = null
    rendered.value = null
    failure.value = null
    status.value = 'idle'
    announcement.value = 'Aperçu fermé.'
  }

  return { status, rendered, heading, failure, active, announcement, prepare, close }
}
