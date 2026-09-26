export interface SalesPeriod {
  not_order: string
  is_order: string
  total: string
}

export interface BookingsByStatus {
  pending_review: number
  pending_confirmation: number
  approved: number
  confirmed: number
  cooking: number
  ready: number
  out_for_delivery: number
  completed: number
  rejected: number
  no_show: number
  cancelled: number
}

export interface ActiveQueueEntry {
  id: number
  code: string
  is_order: boolean
  status: 'cooking' | 'ready'
  customer_name: string | null
  fulfillment: 'pickup' | 'delivery'
  cooking_started_at: string | null
  est_ready_at: string | null
  waiting_minutes: number
}

export interface TopItem {
  service_id: number
  name: string
  qty_sold: number
}

export interface LowStockEntry {
  id: number
  name: string
  stock_qty: number
  low_stock_threshold: number
}

export const STATUS_LABELS: Record<keyof BookingsByStatus, string> = {
  pending_review: 'Pending review',
  pending_confirmation: 'Pending confirmation',
  approved: 'Awaiting drop-off',
  confirmed: 'Confirmed',
  cooking: 'Cooking',
  ready: 'Ready',
  out_for_delivery: 'Out for delivery',
  completed: 'Completed',
  rejected: 'Rejected',
  no_show: 'No-show',
  cancelled: 'Cancelled',
}

export interface Dashboard {
  sales: {
    today: SalesPeriod
    week: SalesPeriod
    month: SalesPeriod
  }
  bookings_by_status: BookingsByStatus
  active_queue: ActiveQueueEntry[]
  top_items: TopItem[]
  low_stock: LowStockEntry[]
}
