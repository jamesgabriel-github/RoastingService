import { useQuery } from '@tanstack/react-query'
import { fetchPayments } from './api'
import type { PaymentMethod } from './types'

export function usePayments(method: PaymentMethod | '', search: string, page: number) {
  return useQuery({
    queryKey: ['admin-payments', method, search, page],
    queryFn: () => fetchPayments(method, search, page),
  })
}
