import type { Me } from './types'

export function isProfileComplete(me: Me): boolean {
  return Boolean(me.first_name && me.last_name)
}

/** Converts a blank or whitespace-only optional field to `null` for submission. */
export function toNullableField(value: string | undefined): string | null {
  const trimmed = value?.trim()
  return trimmed ? trimmed : null
}
