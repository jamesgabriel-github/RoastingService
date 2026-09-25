import type { Booking, Fulfillment } from '@/features/bookings/types'
import { api, ensureCsrfCookie } from '@/lib/api'
import type { ShoppableService } from './types'

export interface NewOrderPayload {
  items: { service_id: number; qty: number }[]
  fulfillment: Fulfillment
  delivery_address: string | null
  notes: string | null
}

export async function fetchShoppableServices(): Promise<ShoppableService[]> {
  const { data } = await api.get<ShoppableService[]>('/services')
  return data.filter((service) => service.allow_shop_supplied && service.in_stock)
}

export async function createOrder(payload: NewOrderPayload): Promise<Booking> {
  await ensureCsrfCookie()
  const { data } = await api.post<Booking>('/orders', payload)
  return data
}
