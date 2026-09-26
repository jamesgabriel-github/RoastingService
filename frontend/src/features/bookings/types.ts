export interface BookableService {
  id: number
  name: string
  description: string | null
  roasting_rate_per_kg: string | null
  est_minutes: number
  allow_customer_supplied: boolean
  allow_shop_supplied: boolean
}

export interface BookingItem {
  id: number
  service_id: number
  service_name: string | null
  qty: number
  est_weight_kg: string | null
  final_weight_kg: string | null
  rate: string
  subtotal: string
}

export type Fulfillment = 'pickup' | 'delivery'

export interface BookingStatusLog {
  id: number
  status: string
  changed_by_name: string | null
  remarks: string | null
  created_at: string
}

export interface Booking {
  id: number
  code: string
  is_order: boolean
  status: string
  fulfillment: Fulfillment
  delivery_address: string | null
  shipping_fee: string
  estimated_total: string
  total_amount: string | null
  preferred_dropoff_at: string | null
  preferred_pickup_at: string | null
  notes: string | null
  items: BookingItem[]
  status_logs?: BookingStatusLog[]
  created_at: string
}
