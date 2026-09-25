import type { Booking } from './types'

const CANCELLABLE_STATUSES: Record<Booking['source_type'], readonly string[]> = {
  customer_supplied: ['pending_review', 'approved', 'confirmed'],
  shop_supplied: ['pending_confirmation', 'confirmed'],
}

/** UI-only convenience mirroring BookingStatusEngine's cancellable-from set; the server is authoritative. */
export function isCancellable(booking: Pick<Booking, 'source_type' | 'status'>): boolean {
  return CANCELLABLE_STATUSES[booking.source_type].includes(booking.status)
}

export function humanizeStatus(status: string): string {
  return status
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}
