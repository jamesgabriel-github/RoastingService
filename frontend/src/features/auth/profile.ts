import type { Me } from './types'

export function isProfileComplete(me: Me): boolean {
  return Boolean(me.first_name && me.last_name)
}
