import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type {
  ApprovePayload,
  RecordPaymentPayload,
  RejectPayload,
  RemarksPayload,
  WalkInRoastingPayload,
  WalkInShopPayload,
  WeighInPayload,
} from './api'
import {
  approveBooking,
  cancelBooking,
  completeBooking,
  confirmOrder,
  createWalkInRoastingBooking,
  createWalkInShopOrder,
  fetchAdminBookingDetail,
  fetchAdminBookings,
  fetchBookingCounts,
  markOutForDelivery,
  markReady,
  noShowBooking,
  recordPayment,
  rejectBooking,
  rejectOrder,
  searchWalkInCustomers,
  startCooking,
  weighInBooking,
} from './api'
import type { QueueGroup } from './types'

export function useBookingCounts() {
  return useQuery({
    queryKey: ['admin-booking-counts'],
    queryFn: fetchBookingCounts,
  })
}

export function useAdminBookings(group: QueueGroup, search: string, page: number, date: string) {
  return useQuery({
    queryKey: ['admin-bookings', group, search, page, date],
    queryFn: () => fetchAdminBookings(group, search, page, date),
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
    mutationFn: (itemId: number) => startCooking(itemId),
    onSuccess: (booking) => invalidate(booking.id),
  })
}

export function useMarkReady() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: (itemId: number) => markReady(itemId),
    onSuccess: (booking) => invalidate(booking.id),
  })
}

export function useMarkOutForDelivery() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: (itemId: number) => markOutForDelivery(itemId),
    onSuccess: (booking) => invalidate(booking.id),
  })
}

export function useCompleteBooking() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: (itemId: number) => completeBooking(itemId),
    onSuccess: (booking) => invalidate(booking.id),
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

export function useRecordPayment() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: RecordPaymentPayload }) => recordPayment(id, payload),
    onSuccess: (_data, { id }) => invalidate(id),
  })
}

export function useSearchWalkInCustomers(search: string) {
  return useQuery({
    queryKey: ['walk-in-customers', search],
    queryFn: () => searchWalkInCustomers(search),
    enabled: search.trim().length >= 2,
  })
}

export function useCreateWalkInRoastingBooking() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: (payload: WalkInRoastingPayload) => createWalkInRoastingBooking(payload),
    onSuccess: (booking) => invalidate(booking.id),
  })
}

export function useCreateWalkInShopOrder() {
  const invalidate = useBookingActionInvalidation()

  return useMutation({
    mutationFn: (payload: WalkInShopPayload) => createWalkInShopOrder(payload),
    onSuccess: (booking) => invalidate(booking.id),
  })
}
