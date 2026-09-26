export type QueueGroup = 'draft' | 'pending' | 'cooking' | 'ready' | 'completed' | 'cancelled'

export const QUEUE_TABS: { group: QueueGroup; label: string }[] = [
  { group: 'draft', label: 'Draft' },
  { group: 'pending', label: 'Pending' },
  { group: 'cooking', label: 'Cooking' },
  { group: 'ready', label: 'Ready' },
  { group: 'completed', label: 'Completed' },
  { group: 'cancelled', label: 'Cancelled' },
]

export function parseQueueGroup(raw: string | null): QueueGroup {
  return QUEUE_TABS.some((tab) => tab.group === raw) ? (raw as QueueGroup) : 'draft'
}

export type BookingCounts = Record<QueueGroup, number>

export interface AdminBookingQueueRow {
  id: number
  booking_id: number
  code: string
  is_order: boolean
  status: string
  service_name: string | null
  qty: number
  est_weight_kg: string | null
  final_weight_kg: string | null
  subtotal: string
  fulfillment: 'pickup' | 'delivery'
  customer_name: string | null
  customer_phone: string | null
  waiting_minutes: number
}

export interface AdminBookingItem {
  id: number
  service_id: number
  service_name: string | null
  qty: number
  est_weight_kg: string | null
  final_weight_kg: string | null
  rate: string
  subtotal: string
  status: string
  approved_at: string | null
  approved_by_name: string | null
  confirmed_at: string | null
  confirmed_by_name: string | null
  weighed_at: string | null
  cooking_started_at: string | null
  est_ready_at: string | null
  completed_at: string | null
  reject_reason: string | null
  waiting_minutes: number
}

export interface AdminBookingStatusLog {
  id: number
  status: string
  service_name: string | null
  changed_by_name: string | null
  remarks: string | null
  created_at: string
}

export interface AdminBooking {
  id: number
  code: string
  is_order: boolean
  fulfillment: 'pickup' | 'delivery'
  delivery_address: string | null
  customer_name: string | null
  customer_phone: string | null
  estimated_total: string
  total_amount: string | null
  paid_amount: string
  balance: string | null
  preferred_dropoff_at: string | null
  preferred_pickup_at: string | null
  dropoff_at: string | null
  notes: string | null
  items: AdminBookingItem[]
  status_logs?: AdminBookingStatusLog[]
  created_at: string
}

export interface WalkInCustomer {
  id: number
  name: string
  phone: string
}

export interface WalkInGuestOrCustomer {
  customer_id: number | null
  guest_name: string | null
  guest_phone: string | null
}

export interface PaginatedAdminBookingRows {
  data: AdminBookingQueueRow[]
  meta: {
    current_page: number
    last_page: number
  }
}

/** The status shared by every item, or `null` once they've diverged (only possible from Cooking on). */
export function getCommonStatus(items: Pick<AdminBookingItem, 'status'>[]): string | null {
  const statuses = new Set(items.map((item) => item.status))
  return statuses.size === 1 ? items[0].status : null
}

const CANCELLABLE_STATUSES: Record<'false' | 'true', readonly string[]> = {
  false: ['pending_review', 'approved', 'confirmed'],
  true: ['pending_confirmation', 'confirmed'],
}

/** UI-only convenience mirroring BookingStatusEngine's cancellable-from set; the server is authoritative. */
export function isAdminCancellable(
  booking: Pick<AdminBooking, 'is_order'> & { items: Pick<AdminBookingItem, 'status'>[] }
): boolean {
  const status = getCommonStatus(booking.items)

  return status !== null && CANCELLABLE_STATUSES[booking.is_order ? 'true' : 'false'].includes(status)
}
