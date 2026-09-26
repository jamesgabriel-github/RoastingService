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
  it('is true for a pending_review non-order booking', () => {
    expect(isCancellable({ is_order: false, status: 'pending_review' })).toBe(true)
  })

  it('is false once a non-order booking is cooking', () => {
    expect(isCancellable({ is_order: false, status: 'cooking' })).toBe(false)
  })

  it('is true for a pending_confirmation order booking', () => {
    expect(isCancellable({ is_order: true, status: 'pending_confirmation' })).toBe(true)
  })

  it('is false for a completed booking', () => {
    expect(isCancellable({ is_order: true, status: 'completed' })).toBe(false)
  })
})
