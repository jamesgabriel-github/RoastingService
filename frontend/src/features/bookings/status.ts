import type { Booking, BookingItem } from './types'

const CANCELLABLE_STATUSES: Record<'false' | 'true', readonly string[]> = {
  false: ['pending_review', 'approved', 'confirmed'],
  true: ['pending_confirmation', 'confirmed'],
}

/** The status shared by every item, or `null` once they've diverged (only possible from Cooking on). */
export function commonStatus(items: Pick<BookingItem, 'status'>[]): string | null {
  const statuses = new Set(items.map((item) => item.status))
  return statuses.size === 1 ? items[0].status : null
}

/** UI-only convenience mirroring BookingStatusEngine's cancellable-from set; the server is authoritative. */
export function isCancellable(booking: Pick<Booking, 'is_order'> & { items: Pick<BookingItem, 'status'>[] }): boolean {
  const status = commonStatus(booking.items)

  return status !== null && CANCELLABLE_STATUSES[booking.is_order ? 'true' : 'false'].includes(status)
}

export function humanizeStatus(status: string): string {
  return status
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}
