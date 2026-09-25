import { api, ensureCsrfCookie } from '@/lib/api'
import type { BookableService, Booking, Fulfillment } from './types'

export interface NewBookingPayload {
  items: { service_id: number; est_weight_kg: number }[]
  fulfillment: Fulfillment
  delivery_address: string | null
  preferred_dropoff_at: string
  notes: string | null
}

export async function fetchBookableServices(): Promise<BookableService[]> {
  const { data } = await api.get<BookableService[]>('/services')
  return data.filter((service) => service.allow_customer_supplied)
}

export async function createBooking(payload: NewBookingPayload): Promise<Booking> {
  await ensureCsrfCookie()
  const { data } = await api.post<Booking>('/bookings', payload)
  return data
}
