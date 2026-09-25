export interface ShoppableService {
  id: number
  name: string
  description: string | null
  shop_price: string | null
  est_minutes: number
  allow_customer_supplied: boolean
  allow_shop_supplied: boolean
  in_stock: boolean | null
}
