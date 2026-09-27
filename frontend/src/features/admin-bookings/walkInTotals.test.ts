import { describe, expect, it } from 'vitest'
import { computeBalanceDue, computeOrderTotal, resolveQuickTenderAmount } from './walkInTotals'

describe('computeOrderTotal', () => {
  it('sums rate times qty for each line', () => {
    expect(
      computeOrderTotal([
        { rate: 200, qty: 2 },
        { rate: 350, qty: 1 },
      ])
    ).toBe(750)
  })

  it('returns 0 for an empty list', () => {
    expect(computeOrderTotal([])).toBe(0)
  })

  it('rounds to 2 decimal places', () => {
    expect(computeOrderTotal([{ rate: 100.005, qty: 1 }])).toBe(100.01)
  })

  it('ignores a line with a zero quantity', () => {
    expect(computeOrderTotal([{ rate: 180, qty: 0 }])).toBe(0)
  })
})

describe('computeBalanceDue', () => {
  it('subtracts the paid amount from the total', () => {
    expect(computeBalanceDue(200, 50)).toBe(150)
  })

  it('clamps at 0 when paid exceeds the total', () => {
    expect(computeBalanceDue(200, 250)).toBe(0)
  })
})

describe('resolveQuickTenderAmount', () => {
  it('returns 0 for unpaid', () => {
    expect(resolveQuickTenderAmount('unpaid', 200)).toBe(0)
  })

  it('returns half the total, rounded', () => {
    expect(resolveQuickTenderAmount('half', 150.03)).toBe(75.02)
  })

  it('returns the full total', () => {
    expect(resolveQuickTenderAmount('full', 199.999)).toBe(200)
  })
})
