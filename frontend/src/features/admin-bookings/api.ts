import { api, ensureCsrfCookie } from '@/lib/api'
import type {
  AdminBooking,
  BookingCounts,
  PaginatedAdminBookingRows,
  QueueGroup,
  WalkInCustomer,
  WalkInGuestOrCustomer,
} from './types'

export interface ApprovePayload {
  dropoff_at: string
}

export interface RejectPayload {
  reason: string
}

export interface WeighInPayload {
  items: { id: number; final_weight_kg: number }[]
}

export interface RemarksPayload {
  remarks?: string
}

export interface RecordPaymentPayload {
  method: 'cash' | 'gcash' | 'card'
  reference_no?: string | null
}

export async function fetchBookingCounts(): Promise<BookingCounts> {
  const { data } = await api.get<BookingCounts>('/admin/bookings/counts')
  return data
}

export async function fetchAdminBookings(
  group: QueueGroup,
  search: string,
  page: number,
  date: string
): Promise<PaginatedAdminBookingRows> {
  const { data } = await api.get<PaginatedAdminBookingRows>('/admin/bookings', {
    params: { group, search: search.trim() || undefined, page, date },
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

export async function confirmOrder(id: number): Promise<AdminBooking> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminBooking>(`/admin/bookings/${id}/confirm-order`)
  return data
}

export async function rejectOrder(id: number, payload: RejectPayload): Promise<AdminBooking> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminBooking>(`/admin/bookings/${id}/reject-order`, payload)
  return data
}

export async function startCooking(itemId: number): Promise<AdminBooking> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminBooking>(`/admin/booking-items/${itemId}/start-cooking`)
  return data
}

export async function markReady(itemId: number): Promise<AdminBooking> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminBooking>(`/admin/booking-items/${itemId}/ready`)
  return data
}

export async function markOutForDelivery(itemId: number): Promise<AdminBooking> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminBooking>(`/admin/booking-items/${itemId}/out-for-delivery`)
  return data
}

export async function completeBooking(itemId: number): Promise<AdminBooking> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminBooking>(`/admin/booking-items/${itemId}/complete`)
  return data
}

export async function noShowBooking(id: number, payload: RemarksPayload): Promise<AdminBooking> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminBooking>(`/admin/bookings/${id}/no-show`, payload)
  return data
}

export async function cancelBooking(id: number, payload: RemarksPayload): Promise<AdminBooking> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminBooking>(`/admin/bookings/${id}/cancel`, payload)
  return data
}

export async function recordPayment(id: number, payload: RecordPaymentPayload): Promise<AdminBooking> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminBooking>(`/admin/bookings/${id}/payments`, payload)
  return data
}

export async function searchWalkInCustomers(search: string): Promise<WalkInCustomer[]> {
  const { data } = await api.get<WalkInCustomer[]>('/admin/bookings/customers', { params: { search } })
  return data
}

export interface WalkInRoastingPayload extends WalkInGuestOrCustomer {
  items: { service_id: number; final_weight_kg: number }[]
  fulfillment: AdminBooking['fulfillment']
  delivery_address: string | null
  preferred_pickup_at: string
  notes: string | null
}

export async function createWalkInRoastingBooking(payload: WalkInRoastingPayload): Promise<AdminBooking> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminBooking>('/admin/bookings/walk-in-roasting', payload)
  return data
}

export interface WalkInShopPayload extends WalkInGuestOrCustomer {
  items: { service_id: number; qty: number }[]
  fulfillment: AdminBooking['fulfillment']
  delivery_address: string | null
  preferred_pickup_at: string
  notes: string | null
}

export async function createWalkInShopOrder(payload: WalkInShopPayload): Promise<AdminBooking> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminBooking>('/admin/bookings/walk-in-shop', payload)
  return data
}
