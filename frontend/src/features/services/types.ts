export interface Service {
  id: number
  name: string
  description: string | null
  roasting_rate_per_kg: string | null
  shop_price: string | null
  est_minutes: number
  allow_customer_supplied: boolean
  allow_shop_supplied: boolean
  stock_qty: number
  low_stock_threshold: number
  is_low_stock: boolean
  is_active: boolean
}
