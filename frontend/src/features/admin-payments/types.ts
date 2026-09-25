export type PaymentMethod = 'cash' | 'gcash' | 'card'

export const METHOD_OPTIONS: { value: PaymentMethod | ''; label: string }[] = [
  { value: '', label: 'All methods' },
  { value: 'cash', label: 'Cash' },
  { value: 'gcash', label: 'GCash' },
  { value: 'card', label: 'Card' },
]

export interface Payment {
  id: number
  booking_id: number
  booking_code: string
  customer_name: string | null
  customer_phone: string | null
  source_type: 'customer_supplied' | 'shop_supplied'
  type: 'full' | 'downpayment' | 'balance'
  amount: string
  method: PaymentMethod
  reference_no: string | null
  status: 'pending' | 'paid' | 'refunded'
  paid_at: string | null
  recorded_by_name: string | null
  created_at: string
}

export interface PaginatedPayments {
  data: Payment[]
  meta: {
    current_page: number
    last_page: number
  }
}
