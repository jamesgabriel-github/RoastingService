import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ApprovePayload, RejectPayload, RemarksPayload, WeighInPayload } from './api'
import {
  approveBooking,
  cancelBooking,
  completeBooking,
  confirmOrder,
  fetchAdminBookingDetail,
  fetchAdminBookings,
  fetchBookingCounts,
  markOutForDelivery,
  markReady,
  noShowBooking,
  rejectBooking,
  rejectOrder,
  startCooking,
  weighInBooking,
} from './api'
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

function useBookingActionInvalidation() {
  const queryClient = useQueryClient()

  return (id: number) => {
    queryClient.invalidateQueries({ queryKey: ['admin-booking-counts'] })
    queryClient.invalidateQueries({ queryKey: ['admin-bookings'] })
    queryClient.invalidateQueries({ queryKey: ['admin-bookings', 'detail', id] })
  }
}

export function useApproveBooking() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: ApprovePayload }) => approveBooking(id, payload),
    onSuccess: (_data, { id }) => invalidate(id),
  })
}

export function useRejectBooking() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: RejectPayload }) => rejectBooking(id, payload),
    onSuccess: (_data, { id }) => invalidate(id),
  })
}

export function useWeighInBooking() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: WeighInPayload }) => weighInBooking(id, payload),
    onSuccess: (_data, { id }) => invalidate(id),
  })
}

export function useConfirmOrder() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: (id: number) => confirmOrder(id),
    onSuccess: (_data, id) => invalidate(id),
  })
}

export function useRejectOrder() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: RejectPayload }) => rejectOrder(id, payload),
    onSuccess: (_data, { id }) => invalidate(id),
  })
}

export function useStartCooking() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: (id: number) => startCooking(id),
    onSuccess: (_data, id) => invalidate(id),
  })
}

export function useMarkReady() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: (id: number) => markReady(id),
    onSuccess: (_data, id) => invalidate(id),
  })
}

export function useMarkOutForDelivery() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: (id: number) => markOutForDelivery(id),
    onSuccess: (_data, id) => invalidate(id),
  })
}

export function useCompleteBooking() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: (id: number) => completeBooking(id),
    onSuccess: (_data, id) => invalidate(id),
  })
}

export function useNoShowBooking() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: RemarksPayload }) => noShowBooking(id, payload),
    onSuccess: (_data, { id }) => invalidate(id),
  })
}

export function useCancelBooking() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: RemarksPayload }) => cancelBooking(id, payload),
    onSuccess: (_data, { id }) => invalidate(id),
  })
}
