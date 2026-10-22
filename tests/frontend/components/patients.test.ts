import { mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import TextAreaField from '@/components/form/TextAreaField.vue'
import DuplicateSuggestions from '@/components/patients/DuplicateSuggestions.vue'
import PatientIdentityHeader from '@/components/patients/PatientIdentityHeader.vue'
import LinkButton from '@/components/ui/LinkButton.vue'
import PaginationControls from '@/components/ui/PaginationControls.vue'
import type { PatientSummary } from '@/domain/contracts/generated'
import type { DuplicateSuggestion } from '@/domain/patients/duplicates'

// Composants réutilisables des écrans patient (P01, P02) : champ multiligne, pagination, ressemblances
// sans fusion, en-tête d'identité. Données FICTIVES.
const wrappers: VueWrapper[] = []
function track<T extends VueWrapper>(wrapper: T): T {
  wrappers.push(wrapper)
  return wrapper
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-15T09:00:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  while (wrappers.length > 0) wrappers.pop()?.unmount()
})

function summary(overrides: Partial<PatientSummary> = {}): PatientSummary {
  return {
    id: '01J9ZXK4M2Q8R7S6T5V4W30011',
    record_number: 'EE-2026-0001',
    last_name: 'PATIENT FICTIF',
    first_names: 'Élodie',
    sex: 'F',
    birth_date: '1990-05-17',
    phone_primary: '+237600000001',
    archived: false,
    merged_into_patient_id: null,
    version: 1,
    ...overrides,
  }
}

describe('TextAreaField', () => {
  it('relie libellé, aide et erreur au champ multiligne et garde les retours à la ligne', async () => {
    const wrapper = track(
      mount(TextAreaField, {
        props: {
          label: 'Adresse ou quartier',
          modelValue: 'Quartier fictif\nDouala',
          hint: 'Quartier, rue ou repère.',
          error: 'L’adresse est trop longue.',
        },
      }),
    )
    const area = wrapper.get('textarea')

    expect(wrapper.get('label').attributes('for')).toBe(area.attributes('id'))
    expect(area.element.value).toBe('Quartier fictif\nDouala')
    expect(area.attributes('aria-invalid')).toBe('true')
    const described = (area.attributes('aria-describedby') ?? '').split(' ')
    expect(described.map((id) => wrapper.get(`[id="${id}"]`).text())).toEqual([
      'Quartier, rue ou repère.',
      '⚠ L’adresse est trop longue.',
    ])
    expect(area.classes()).toContain('min-h-11')

    await area.setValue('Ligne 1\nLigne 2 ')
    expect(wrapper.emitted('update:modelValue')).toEqual([['Ligne 1\nLigne 2 ']])
  })

  it('désactivé et sans erreur : ni aria-invalid ni description', () => {
    const wrapper = track(
      mount(TextAreaField, { props: { label: 'Adresse', modelValue: '', disabled: true } }),
    )
    const area = wrapper.get('textarea')

    expect(area.attributes('disabled')).toBeDefined()
    expect(area.attributes('aria-invalid')).toBeUndefined()
    expect(area.attributes('aria-describedby')).toBeUndefined()
  })
})

describe('PaginationControls', () => {
  it('n’affiche rien pour une seule page', () => {
    const wrapper = track(
      mount(PaginationControls, { props: { currentPage: 1, lastPage: 1, total: 3 } }),
    )
    expect(wrapper.find('nav').exists()).toBe(false)
  })

  it('dit la page et le total, et demande la page voisine', async () => {
    const wrapper = track(
      mount(PaginationControls, { props: { currentPage: 2, lastPage: 3, total: 61 } }),
    )

    expect(wrapper.get('nav').attributes('aria-label')).toBe('Pagination des résultats')
    expect(wrapper.text()).toContain('Page 2 sur 3 · 61 résultats')
    const [previous, next] = wrapper.findAll('button')
    expect(previous?.text()).toBe('Page précédente')
    expect(next?.text()).toBe('Page suivante')

    await previous?.trigger('click')
    await next?.trigger('click')
    expect(wrapper.emitted('page')).toEqual([[1], [3]])
  })

  it('désactive les bornes et tout pendant une lecture', () => {
    const first = track(
      mount(PaginationControls, { props: { currentPage: 1, lastPage: 3, total: 61 } }),
    )
    expect(
      first.findAll('button').map((button) => button.attributes('disabled') !== undefined),
    ).toEqual([true, false])
    const last = track(
      mount(PaginationControls, { props: { currentPage: 3, lastPage: 3, total: 61 } }),
    )
    expect(
      last.findAll('button').map((button) => button.attributes('disabled') !== undefined),
    ).toEqual([false, true])
    const busy = track(
      mount(PaginationControls, { props: { currentPage: 2, lastPage: 3, total: 61, busy: true } }),
    )
    expect(
      busy.findAll('button').every((button) => button.attributes('disabled') !== undefined),
    ).toBe(true)
  })
})

describe('DuplicateSuggestions', () => {
  const byName: DuplicateSuggestion = {
    patient: summary({ id: '01J9ZXK4M2Q8R7S6T5V4W30012', record_number: 'EE-2025-0042' }),
    reasons: ['name'],
    sameBirthDate: true,
  }
  const byPhone: DuplicateSuggestion = {
    patient: summary({
      id: '01J9ZXK4M2Q8R7S6T5V4W30013',
      record_number: 'EE-2025-0077',
      last_name: 'PROCHE FICTIF',
      first_names: null,
      birth_date: null,
      archived: true,
    }),
    reasons: ['phone'],
    sameBirthDate: false,
  }

  it('liste chaque fiche proche avec son numéro, son âge, son téléphone et la raison', () => {
    const wrapper = track(
      mount(DuplicateSuggestions, { props: { suggestions: [byName, byPhone] } }),
    )
    const items = wrapper.findAll('li').map((item) => item.text().replace(/\s+/g, ' '))

    expect(items).toHaveLength(2)
    expect(items[0]).toContain('PATIENT FICTIF Élodie')
    expect(items[0]).toContain('Dossier EE-2025-0042')
    expect(items[0]).toContain('36 ans')
    expect(items[0]).toContain('né(e) le 17/05/1990')
    expect(items[0]).toContain('+237600000001')
    expect(items[0]).toContain('Nom proche')
    expect(items[0]).toContain('Même date de naissance')
    expect(items[1]).toContain('PROCHE FICTIF')
    expect(items[1]).toContain('âge non renseigné')
    expect(items[1]).toContain('Même téléphone')
    expect(items[1]).toContain('Fiche archivée')
  })

  it('ouvre chaque fiche dans un nouvel onglet et ne propose aucune fusion', () => {
    const wrapper = track(
      mount(DuplicateSuggestions, { props: { suggestions: [byName, byPhone] } }),
    )

    const links = wrapper.findAll('a')
    expect(links.map((link) => link.attributes('href'))).toEqual([
      '/patients/01J9ZXK4M2Q8R7S6T5V4W30012',
      '/patients/01J9ZXK4M2Q8R7S6T5V4W30013',
    ])
    for (const link of links) {
      expect(link.attributes('target')).toBe('_blank')
      expect(link.attributes('rel')).toContain('noopener')
      expect(link.text()).toContain('nouvel onglet')
    }
    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.text()).not.toMatch(/fusionner|fusion de/i)
    expect(wrapper.text()).toContain('Aucune fiche n’est fusionnée ni modifiée')
  })

  it('rappelle qu’un téléphone partagé est permis seulement quand il y en a un', () => {
    const phoneOnly = track(mount(DuplicateSuggestions, { props: { suggestions: [byPhone] } }))
    expect(phoneOnly.text()).toContain('Un même téléphone peut servir à plusieurs patients')

    const nameOnly = track(mount(DuplicateSuggestions, { props: { suggestions: [byName] } }))
    expect(nameOnly.text()).not.toContain('Un même téléphone peut servir')
  })

  it('dit que d’autres fiches proches existent quand la liste est tronquée', () => {
    const wrapper = track(
      mount(DuplicateSuggestions, { props: { suggestions: [byName], more: true } }),
    )
    expect(wrapper.text()).toContain('D’autres fiches proches existent')
  })

  it('signale une fiche fusionnée', () => {
    const merged: DuplicateSuggestion = {
      patient: summary({ merged_into_patient_id: '01J9ZXK4M2Q8R7S6T5V4W30099' }),
      reasons: ['name'],
      sameBirthDate: false,
    }
    const wrapper = track(mount(DuplicateSuggestions, { props: { suggestions: [merged] } }))
    expect(wrapper.text()).toContain('Fiche fusionnée')
  })
})

describe('PatientIdentityHeader', () => {
  const base = {
    last_name: 'PATIENT FICTIF',
    first_names: 'Élodie',
    record_number: 'EE-2026-0001',
    sex: 'F' as const,
    birth_date: '1990-05-17',
    archived: false,
    merged_into_patient_id: null,
  }

  it('montre nom, numéro de dossier, sexe et âge calculé à la date du cabinet', () => {
    const wrapper = track(mount(PatientIdentityHeader, { props: { patient: base } }))
    const text = wrapper.text().replace(/\s+/g, ' ')

    expect(text).toContain('PATIENT FICTIF Élodie')
    expect(text).toContain('Dossier EE-2026-0001')
    expect(text).toContain('Féminin')
    expect(text).toContain('36 ans')
    expect(wrapper.find('h1').exists()).toBe(false)
  })

  it('« âge non renseigné » sans naissance, sans prénom, avec un titre de niveau choisi', () => {
    const wrapper = track(
      mount(PatientIdentityHeader, {
        props: {
          patient: { ...base, first_names: null, birth_date: null, sex: 'UNKNOWN' },
          as: 'h1',
          titleId: 'titre-fiche',
        },
      }),
    )

    const title = wrapper.get('h1')
    expect(title.text()).toBe('PATIENT FICTIF')
    expect(title.attributes('id')).toBe('titre-fiche')
    expect(wrapper.text()).toContain('âge non renseigné')
    expect(wrapper.text()).toContain('Non renseigné')
  })

  it('dit en texte et en symbole qu’une fiche est archivée ou fusionnée', () => {
    const wrapper = track(
      mount(PatientIdentityHeader, {
        props: {
          patient: {
            ...base,
            archived: true,
            merged_into_patient_id: '01J9ZXK4M2Q8R7S6T5V4W30099',
          },
        },
      }),
    )

    expect(wrapper.text()).toContain('Fiche archivée')
    expect(wrapper.text()).toContain('Fiche fusionnée dans une autre fiche')
    expect(wrapper.findAll('[aria-hidden="true"]').some((node) => node.text() === '⚠')).toBe(true)
  })

  it('n’affiche aucune donnée clinique : identité administrative seulement', () => {
    const wrapper = track(mount(PatientIdentityHeader, { props: { patient: base } }))
    expect(wrapper.text()).not.toMatch(/consultation|ordonnance|diagnostic|acuité|réfraction/i)
  })
})

describe('LinkButton', () => {
  it('est un vrai lien de 44 px, avec les teintes du bouton', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/patients', component: { render: () => null } }],
    })
    await router.push('/patients')
    const wrapper = track(
      mount(LinkButton, {
        props: { to: '/patients', variant: 'primary' },
        slots: { default: 'Nouveau patient' },
        global: { plugins: [router] },
      }),
    )

    const link = wrapper.get('a')
    expect(link.attributes('href')).toBe('/patients')
    expect(link.text()).toBe('Nouveau patient')
    expect(link.classes()).toEqual(expect.arrayContaining(['min-h-11', 'bg-primary']))
  })
})
