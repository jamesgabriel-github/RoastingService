import { describe, expect, it } from 'vitest'
import { computeEstimatedTotal } from './estimate'

describe('computeEstimatedTotal', () => {
  it('sums rate times weight for each line', () => {
    expect(
      computeEstimatedTotal([
        { rate: 150, estWeightKg: 2 },
        { rate: 200, estWeightKg: 5 },
      ])
    ).toBe(1300)
  })

  it('returns 0 for an empty list', () => {
    expect(computeEstimatedTotal([])).toBe(0)
  })

  it('rounds to 2 decimal places', () => {
    expect(computeEstimatedTotal([{ rate: 100.005, estWeightKg: 1 }])).toBe(100.01)
  })

  it('ignores a line with a zero weight', () => {
    expect(computeEstimatedTotal([{ rate: 150, estWeightKg: 0 }])).toBe(0)
  })
})
