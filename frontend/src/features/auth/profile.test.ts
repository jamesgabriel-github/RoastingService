import { describe, expect, it } from 'vitest'
import { isProfileComplete, toNullableField } from './profile'
import type { Me } from './types'

function customer(overrides: Partial<Me>): Me {
  return {
    id: 1,
    name: null,
    email: null,
    phone: '09171234567',
    first_name: null,
    middle_name: null,
    last_name: null,
    address: null,
    role: 'customer',
    permissions: [],
    ...overrides,
  }
}

describe('isProfileComplete', () => {
  it('is true when both first and last name are set', () => {
    expect(isProfileComplete(customer({ first_name: 'Juan', last_name: 'Dela Cruz' }))).toBe(true)
  })

  it('is false when first name is missing', () => {
    expect(isProfileComplete(customer({ last_name: 'Dela Cruz' }))).toBe(false)
  })

  it('is false when last name is missing', () => {
    expect(isProfileComplete(customer({ first_name: 'Juan' }))).toBe(false)
  })

  it('is false when neither name is set', () => {
    expect(isProfileComplete(customer({}))).toBe(false)
  })
})

describe('toNullableField', () => {
  it('converts an empty string to null', () => {
    expect(toNullableField('')).toBeNull()
  })

  it('converts a whitespace-only string to null', () => {
    expect(toNullableField('   ')).toBeNull()
  })

  it('returns a real value trimmed', () => {
    expect(toNullableField('  Santos  ')).toBe('Santos')
  })

  it('converts undefined to null', () => {
    expect(toNullableField(undefined)).toBeNull()
  })
})
