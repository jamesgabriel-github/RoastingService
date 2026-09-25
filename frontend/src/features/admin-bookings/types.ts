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
  source_type: 'customer_supplied' | 'shop_supplied'
  status: string
  fulfillment: 'pickup' | 'delivery'
  delivery_address: string | null
  customer_name: string | null
  customer_phone: string | null
  estimated_total: string
  total_amount: string | null
  preferred_dropoff_at: string | null
  notes: string | null
  waiting_minutes: number
  items?: AdminBookingItem[]
  status_logs?: AdminBookingStatusLog[]
  created_at: string
}

export interface PaginatedAdminBookings {
  data: AdminBooking[]
  meta: {
    current_page: number
    last_page: number
  }
}
