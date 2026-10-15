import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AmountSummary from '@/components/finance/AmountSummary.vue'

const NNBSP = '\u{202F}'
const NBSP = '\u{A0}'

describe('AmountSummary : Total, Versé, Reste (fichier 04)', () => {
  it('affiche les trois montants donnés, en FCFA entiers, le reste mis en évidence', () => {
    const wrapper = mount(AmountSummary, {
      props: { total: 125000, paid: 50000, remaining: 75000, title: 'Montants de la facture' },
    })

    expect(wrapper.attributes('role')).toBe('group')
    expect(wrapper.attributes('aria-label')).toBe('Montants de la facture')
    const rows = wrapper
      .findAll('dl > div')
      .map((row) => [row.get('dt').text(), row.get('dd').text()])
    expect(rows).toEqual([
      ['Total', `125${NNBSP}000${NBSP}FCFA`],
      ['Versé', `50${NNBSP}000${NBSP}FCFA`],
      ['Reste', `75${NNBSP}000${NBSP}FCFA`],
    ])
    expect(wrapper.findAll('dd')[2]?.classes()).toContain('font-bold')
  })

  it('ne calcule aucun solde : il montre ce que la projection fournit', () => {
    // Un avoir peut réduire le reste sans paiement : la valeur fournie est affichée telle quelle.
    const wrapper = mount(AmountSummary, { props: { total: 100000, paid: 0, remaining: 40000 } })

    expect(wrapper.findAll('dd')[2]?.text()).toBe(`40${NNBSP}000${NBSP}FCFA`)
  })

  it('affiche le plus grand montant admis sans le tronquer', () => {
    const wrapper = mount(AmountSummary, {
      props: { total: 1_000_000_000_000, paid: 0, remaining: 1_000_000_000_000 },
    })

    expect(wrapper.findAll('dd')[0]?.text()).toBe(
      ['1', '000', '000', '000', '000'].join(NNBSP) + `${NBSP}FCFA`,
    )
    expect(wrapper.html()).not.toMatch(/truncate|text-ellipsis|overflow-hidden/)
  })

  it('refuse un montant non entier plutôt que de l’arrondir', () => {
    expect(() =>
      mount(AmountSummary, { props: { total: 10.5, paid: 0, remaining: 10.5 } }),
    ).toThrow()
  })
})
