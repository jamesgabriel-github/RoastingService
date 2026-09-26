export type QueueStatus =
  | 'pending_review'
  | 'pending_confirmation'
  | 'approved'
  | 'confirmed'
  | 'cooking'
  | 'ready'
  | 'out_for_delivery'

export const QUEUE_TABS: { status: QueueStatus; label: string }[] = [
  { status: 'pending_review', label: 'Pending review' },
  { status: 'pending_confirmation', label: 'Pending confirmation' },
  { status: 'approved', label: 'Awaiting drop-off' },
  { status: 'confirmed', label: 'Confirmed' },
  { status: 'cooking', label: 'Cooking' },
  { status: 'ready', label: 'Ready' },
  { status: 'out_for_delivery', label: 'Out for delivery' },
]

export function parseQueueStatus(raw: string | null): QueueStatus {
  return QUEUE_TABS.some((tab) => tab.status === raw) ? (raw as QueueStatus) : 'pending_review'
}

export type BookingCounts = Record<QueueStatus, number>

export interface AdminBookingItem {
  id: number
  service_id: number
  service_name: string | null
  qty: number
  est_weight_kg: string | null
  final_weight_kg: string | null
  rate: string
  subtotal: string
}

export interface AdminBookingStatusLog {
  id: number
  status: string
  changed_by_name: string | null
  remarks: string | null
  created_at: string
}

export interface AdminBooking {
  id: number
  code: string
  is_order: boolean
  status: string
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
  approved_at: string | null
  approved_by_name: string | null
  reject_reason: string | null
  confirmed_at: string | null
  confirmed_by_name: string | null
  weighed_at: string | null
  cooking_started_at: string | null
  est_ready_at: string | null
  completed_at: string | null
  notes: string | null
  waiting_minutes: number
  items?: AdminBookingItem[]
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

export interface PaginatedAdminBookings {
  data: AdminBooking[]
  meta: {
    current_page: number
    last_page: number
  }
}

const CANCELLABLE_STATUSES: Record<'false' | 'true', readonly string[]> = {
  false: ['pending_review', 'approved', 'confirmed'],
  true: ['pending_confirmation', 'confirmed'],
}

/** UI-only convenience mirroring BookingStatusEngine's cancellable-from set; the server is authoritative. */
export function isAdminCancellable(booking: Pick<AdminBooking, 'is_order' | 'status'>): boolean {
  return CANCELLABLE_STATUSES[booking.is_order ? 'true' : 'false'].includes(booking.status)
}
