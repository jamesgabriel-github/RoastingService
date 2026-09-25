import { api, ensureCsrfCookie } from '@/lib/api'
import type { AdminBooking, BookingCounts, PaginatedAdminBookings, QueueStatus } from './types'

export interface ApprovePayload {
  dropoff_at: string
}

export interface RejectPayload {
  reason: string
}

export interface WeighInPayload {
  items: { id: number; final_weight_kg: number }[]
}

export async function fetchBookingCounts(): Promise<BookingCounts> {
  const { data } = await api.get<BookingCounts>('/admin/bookings/counts')
  return data
}

export async function fetchAdminBookings(
  status: QueueStatus,
  search: string,
  page: number
): Promise<PaginatedAdminBookings> {
  const { data } = await api.get<PaginatedAdminBookings>('/admin/bookings', {
    params: { status, search: search.trim() || undefined, page },
  })
  return data
}

export async function fetchAdminBookingDetail(id: number): Promise<AdminBooking> {
  const { data } = await api.get<AdminBooking>(`/admin/bookings/${id}`)
  return data
}

export async function approveBooking(id: number, payload: ApprovePayload): Promise<AdminBooking> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminBooking>(`/admin/bookings/${id}/approve`, payload)
  return data
}

export async function rejectBooking(id: number, payload: RejectPayload): Promise<AdminBooking> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminBooking>(`/admin/bookings/${id}/reject`, payload)
  return data
}

export async function weighInBooking(id: number, payload: WeighInPayload): Promise<AdminBooking> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminBooking>(`/admin/bookings/${id}/weigh-in`, payload)
  return data
}
