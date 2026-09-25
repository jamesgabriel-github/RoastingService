import { describe, expect, it } from 'vitest'
import { computeOrderTotal } from './orderTotal'

describe('computeOrderTotal', () => {
  it('sums rate times qty for each line', () => {
    expect(
      computeOrderTotal([
        { rate: 300, qty: 2 },
        { rate: 200, qty: 1 },
      ])
    ).toBe(800)
  })

  it('returns 0 for an empty list', () => {
    expect(computeOrderTotal([])).toBe(0)
  })

  it('rounds to 2 decimal places', () => {
    expect(computeOrderTotal([{ rate: 100.005, qty: 1 }])).toBe(100.01)
  })

  it('ignores a line with a zero quantity', () => {
    expect(computeOrderTotal([{ rate: 150, qty: 0 }])).toBe(0)
  })
})
