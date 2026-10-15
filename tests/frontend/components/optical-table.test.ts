import { mount } from '@vue/test-utils'
import { h } from 'vue'
import { describe, expect, it } from 'vitest'

import OpticalTable, { type OpticalCellProps } from '@/components/clinical/OpticalTable.vue'

const COLUMNS = [
  { key: 'sphere', label: 'Sphère', unit: 'D' },
  { key: 'cylinder', label: 'Cylindre', unit: 'D' },
]

function eyeGroups(wrapper: ReturnType<typeof mount>): string[] {
  return wrapper.findAll('fieldset').map((fieldset) => fieldset.get('legend').text())
}

describe('OpticalTable (fichier 04 : OD avant OG, OU pour l’acuité seulement)', () => {
  it('présente toujours OD avant OG, quel que soit l’ordre demandé', () => {
    const wrapper = mount(OpticalTable, {
      props: {
        caption: 'Réfraction subjective',
        columns: COLUMNS,
        eyes: ['OG', 'OD'],
        modelValue: {},
      },
    })

    expect(eyeGroups(wrapper)).toEqual(['Œil droit (OD)', 'Œil gauche (OG)'])
  })

  it('n’ajoute OU que sur demande (acuité)', () => {
    const refraction = mount(OpticalTable, {
      props: { caption: 'Réfraction', columns: COLUMNS, modelValue: {} },
    })
    const acuity = mount(OpticalTable, {
      props: {
        caption: 'Acuité de loin',
        columns: COLUMNS,
        eyes: ['OU', 'OG', 'OD'],
        modelValue: {},
      },
    })

    expect(eyeGroups(refraction)).toEqual(['Œil droit (OD)', 'Œil gauche (OG)'])
    expect(eyeGroups(acuity)).toEqual(['Œil droit (OD)', 'Œil gauche (OG)', 'Les deux yeux (OU)'])
  })

  it('donne à chaque champ un libellé complet : mesure, unité et œil', () => {
    const wrapper = mount(OpticalTable, {
      props: { caption: 'Réfraction', columns: COLUMNS, modelValue: {} },
    })

    expect(wrapper.findAll('label').map((label) => label.text())).toEqual([
      'Sphère (D), Œil droit (OD)',
      'Cylindre (D), Œil droit (OD)',
      'Sphère (D), Œil gauche (OG)',
      'Cylindre (D), Œil gauche (OG)',
    ])
    // Sur grand écran, l'en-tête de colonne montre la mesure : le libellé n'est masqué que là.
    expect(wrapper.findAll('label').every((label) => label.classes().includes('sm:sr-only'))).toBe(
      true,
    )
  })

  it('modifie le seul œil saisi : aucune recopie vers l’autre œil', async () => {
    const wrapper = mount(OpticalTable, {
      props: {
        caption: 'Réfraction',
        columns: COLUMNS,
        modelValue: {
          OD: { sphere: null, cylinder: null },
          OG: { sphere: '-1.00', cylinder: null },
        },
      },
    })

    const odSphere = wrapper.findAll('input')[0]
    await odSphere?.setValue('+2,25')
    await odSphere?.trigger('blur')

    expect(wrapper.emitted('update:modelValue')).toEqual([
      [{ OD: { sphere: '2.25', cylinder: null }, OG: { sphere: '-1.00', cylinder: null } }],
    ])
  })

  it('affiche l’erreur d’une cellule sous son champ', () => {
    const wrapper = mount(OpticalTable, {
      props: {
        caption: 'Réfraction',
        columns: COLUMNS,
        modelValue: {},
        errors: { 'OG.cylinder': 'Valeur hors des bornes admises.' },
      },
    })

    const inputs = wrapper.findAll('input')
    expect(inputs[3]?.attributes('aria-invalid')).toBe('true')
    expect(inputs[0]?.attributes('aria-invalid')).toBeUndefined()
    expect(wrapper.text()).toContain('Valeur hors des bornes admises.')
  })

  it('accepte une cellule fournie pour une colonne d’une autre nature (axe en degrés)', async () => {
    const wrapper = mount(OpticalTable, {
      props: {
        caption: 'Réfraction',
        columns: [...COLUMNS, { key: 'axis', label: 'Axe', unit: '°' }],
        modelValue: { OD: { axis: 90 } },
      },
      slots: {
        'cellule-axis': (cell: OpticalCellProps) =>
          h('label', [
            cell.label,
            h('input', {
              class: 'axe',
              value: String(cell.value ?? ''),
              onInput: (event: Event) =>
                cell.update(Number((event.target as HTMLInputElement).value)),
            }),
          ]),
      },
    })

    const axes = wrapper.findAll('input.axe')
    expect(axes).toHaveLength(2)
    expect((axes[0]?.element as HTMLInputElement).value).toBe('90')
    expect(wrapper.text()).toContain('Axe (°), Œil gauche (OG)')

    await axes[1]?.setValue('180')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([
      { OD: { axis: 90 }, OG: { axis: 180 } },
    ])
  })
})
