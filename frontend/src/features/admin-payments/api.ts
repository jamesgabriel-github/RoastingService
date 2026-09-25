import { api } from '@/lib/api'
import type { PaginatedPayments, PaymentMethod } from './types'

export async function fetchPayments(
  method: PaymentMethod | '',
  search: string,
  page: number
): Promise<PaginatedPayments> {
  const { data } = await api.get<PaginatedPayments>('/admin/payments', {
    params: { method: method || undefined, search: search.trim() || undefined, page },
  })
  return data
}
