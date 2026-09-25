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

export interface Booking {
  id: number
  code: string
  source_type: 'customer_supplied' | 'shop_supplied'
  status: string
  fulfillment: Fulfillment
  delivery_address: string | null
  shipping_fee: string
  estimated_total: string
  total_amount: string | null
  preferred_dropoff_at: string
  notes: string | null
  items: BookingItem[]
  created_at: string
}
