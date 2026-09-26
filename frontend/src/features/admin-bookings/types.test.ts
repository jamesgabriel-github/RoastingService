import { describe, expect, it } from 'vitest'
import { getCommonStatus, isAdminCancellable, parseQueueGroup } from './types'

describe('parseQueueGroup', () => {
  it('passes through a valid group unchanged', () => {
    expect(parseQueueGroup('cooking')).toBe('cooking')
  })

  it('falls back to draft when missing', () => {
    expect(parseQueueGroup(null)).toBe('draft')
  })

  it('falls back to draft when invalid', () => {
    expect(parseQueueGroup('not-a-group')).toBe('draft')
  })
})

describe('getCommonStatus', () => {
  it('returns the shared status when every item matches', () => {
    expect(getCommonStatus([{ status: 'cooking' }, { status: 'cooking' }])).toBe('cooking')
  })

  it('returns null once items have diverged', () => {
    expect(getCommonStatus([{ status: 'cooking' }, { status: 'ready' }])).toBeNull()
  })
})

describe('isAdminCancellable', () => {
  it('is true for a pending_review non-order booking', () => {
    expect(isAdminCancellable({ is_order: false, items: [{ status: 'pending_review' }] })).toBe(true)
  })

  it('is false once a non-order booking is cooking', () => {
    expect(isAdminCancellable({ is_order: false, items: [{ status: 'cooking' }] })).toBe(false)
  })

  it('is true for a pending_confirmation order booking', () => {
    expect(isAdminCancellable({ is_order: true, items: [{ status: 'pending_confirmation' }] })).toBe(true)
  })

  it('is false for a completed booking', () => {
    expect(isAdminCancellable({ is_order: true, items: [{ status: 'completed' }] })).toBe(false)
  })

  it("is false once a booking's items have diverged", () => {
    expect(
      isAdminCancellable({
        is_order: false,
        items: [{ status: 'cooking' }, { status: 'confirmed' }],
      })
    ).toBe(false)
  })
})
