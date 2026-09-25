import { describe, expect, it } from 'vitest'
import { humanizeStatus, isCancellable } from './status'

describe('humanizeStatus', () => {
  it('title-cases each underscore-separated word', () => {
    expect(humanizeStatus('pending_review')).toBe('Pending Review')
  })

  it('handles a single word', () => {
    expect(humanizeStatus('cancelled')).toBe('Cancelled')
  })
})

describe('isCancellable', () => {
  it('is true for a pending_review customer_supplied booking', () => {
    expect(isCancellable({ source_type: 'customer_supplied', status: 'pending_review' })).toBe(true)
  })

  it('is false once a customer_supplied booking is cooking', () => {
    expect(isCancellable({ source_type: 'customer_supplied', status: 'cooking' })).toBe(false)
  })

  it('is true for a pending_confirmation shop_supplied booking', () => {
    expect(isCancellable({ source_type: 'shop_supplied', status: 'pending_confirmation' })).toBe(true)
  })

  it('is false for a completed booking', () => {
    expect(isCancellable({ source_type: 'shop_supplied', status: 'completed' })).toBe(false)
  })
})
