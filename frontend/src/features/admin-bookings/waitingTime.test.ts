import { describe, expect, it } from 'vitest'
import { formatWaitingTime } from './waitingTime'

describe('formatWaitingTime', () => {
  it('shows minutes only under an hour', () => {
    expect(formatWaitingTime(45)).toBe('45m')
  })

  it('shows zero minutes as 0m', () => {
    expect(formatWaitingTime(0)).toBe('0m')
  })

  it('shows hours and minutes at or beyond an hour', () => {
    expect(formatWaitingTime(90)).toBe('1h 30m')
  })

  it('shows whole hours with 0 remaining minutes', () => {
    expect(formatWaitingTime(120)).toBe('2h 0m')
  })
})
