import type { Booking } from './types'

const CANCELLABLE_STATUSES: Record<'false' | 'true', readonly string[]> = {
  false: ['pending_review', 'approved', 'confirmed'],
  true: ['pending_confirmation', 'confirmed'],
}

/** UI-only convenience mirroring BookingStatusEngine's cancellable-from set; the server is authoritative. */
export function isCancellable(booking: Pick<Booking, 'is_order' | 'status'>): boolean {
  return CANCELLABLE_STATUSES[booking.is_order ? 'true' : 'false'].includes(booking.status)
}

export function humanizeStatus(status: string): string {
  return status
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}
