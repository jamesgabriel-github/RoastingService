import { api } from '@/lib/api'
import type { AdminBooking, BookingCounts, PaginatedAdminBookings, QueueStatus } from './types'

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
