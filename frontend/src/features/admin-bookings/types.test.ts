import { describe, expect, it } from 'vitest'
import { parseQueueStatus } from './types'

describe('parseQueueStatus', () => {
  it('passes through a valid status unchanged', () => {
    expect(parseQueueStatus('cooking')).toBe('cooking')
  })

  it('falls back to pending_review when missing', () => {
    expect(parseQueueStatus(null)).toBe('pending_review')
  })

  it('falls back to pending_review when invalid', () => {
    expect(parseQueueStatus('not-a-status')).toBe('pending_review')
  })
})
