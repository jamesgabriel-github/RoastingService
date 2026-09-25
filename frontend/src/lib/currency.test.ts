import { describe, expect, it } from 'vitest'
import { formatCurrency } from './currency'

describe('formatCurrency', () => {
  it('formats a number as PHP with two decimals', () => {
    expect(formatCurrency(600)).toBe('₱600.00')
  })

  it('formats a numeric string as PHP with two decimals', () => {
    expect(formatCurrency('1300.5')).toBe('₱1,300.50')
  })

  it('formats zero', () => {
    expect(formatCurrency(0)).toBe('₱0.00')
  })
})
