import { api, ensureCsrfCookie } from '@/lib/api'
import type { BookableService, Booking, Fulfillment } from './types'

export interface NewBookingPayload {
  items: { service_id: number; est_weight_kg: number }[]
  fulfillment: Fulfillment
  delivery_address: string | null
  preferred_dropoff_at: string
  preferred_pickup_at: string
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

export async function fetchMyBookings(): Promise<Booking[]> {
  const { data } = await api.get<Booking[]>('/bookings')
  return data
}

export async function fetchBookingDetail(id: number): Promise<Booking> {
  const { data } = await api.get<Booking>(`/bookings/${id}`)
  return data
}

export async function cancelBooking(id: number): Promise<Booking> {
  await ensureCsrfCookie()
  const { data } = await api.post<Booking>(`/bookings/${id}/cancel`)
  return data
}
