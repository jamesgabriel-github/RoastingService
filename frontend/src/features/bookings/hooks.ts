import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { cancelBooking, createBooking, fetchBookableServices, fetchBookingDetail, fetchMyBookings } from './api'

const REFRESH_INTERVAL_MS = 10000

export function useBookableServices() {
  return useQuery({
    queryKey: ['bookable-services'],
    queryFn: fetchBookableServices,
  })
}

export function useCreateBooking() {
  return useMutation({
    mutationFn: createBooking,
  })
}

export function useMyBookings() {
  return useQuery({
    queryKey: ['my-bookings'],
    queryFn: fetchMyBookings,
    refetchInterval: REFRESH_INTERVAL_MS,
  })
}

export function useBookingDetail(id: number) {
  return useQuery({
    queryKey: ['my-bookings', id],
    queryFn: () => fetchBookingDetail(id),
    refetchInterval: REFRESH_INTERVAL_MS,
  })
}

export function useCancelBooking() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: cancelBooking,
    onSuccess: (booking) => {
      queryClient.invalidateQueries({ queryKey: ['my-bookings'] })
      queryClient.setQueryData(['my-bookings', booking.id], booking)
    },
  })
}
