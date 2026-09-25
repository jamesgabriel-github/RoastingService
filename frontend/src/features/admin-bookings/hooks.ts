import { useQuery } from '@tanstack/react-query'
import { fetchAdminBookingDetail, fetchAdminBookings, fetchBookingCounts } from './api'
import type { QueueStatus } from './types'

export function useBookingCounts() {
  return useQuery({
    queryKey: ['admin-booking-counts'],
    queryFn: fetchBookingCounts,
  })
}

export function useAdminBookings(status: QueueStatus, search: string, page: number) {
  return useQuery({
    queryKey: ['admin-bookings', status, search, page],
    queryFn: () => fetchAdminBookings(status, search, page),
  })
}

export function useAdminBookingDetail(id: number) {
  return useQuery({
    queryKey: ['admin-bookings', 'detail', id],
    queryFn: () => fetchAdminBookingDetail(id),
  })
}
