import { AxiosError, AxiosHeaders } from 'axios'
import { describe, expect, it } from 'vitest'
import { getGenericErrorMessage } from './errors'

function axiosErrorWithStatus(status: number): AxiosError {
  return new AxiosError('Request failed', undefined, undefined, undefined, {
    status,
    statusText: '',
    headers: new AxiosHeaders(),
    config: { headers: new AxiosHeaders() },
    data: undefined,
  })
}

describe('getGenericErrorMessage', () => {
  it('reports throttling for a 429 response', () => {
    expect(getGenericErrorMessage(axiosErrorWithStatus(429))).toBe(
      'Too many attempts. Please wait a moment and try again.',
    )
  })

  it('reports an expired session for a 419 response', () => {
    expect(getGenericErrorMessage(axiosErrorWithStatus(419))).toBe(
      'Your session expired. Please refresh the page and try again.',
    )
  })

  it('reports a generic server error for a 5xx response', () => {
    expect(getGenericErrorMessage(axiosErrorWithStatus(500))).toBe(
      'Something went wrong on our end. Please try again.',
    )
  })

  it('falls back to a generic message for a non-axios error', () => {
    expect(getGenericErrorMessage(new Error('boom'))).toBe(
      'Something went wrong. Please try again.',
    )
  })
})
