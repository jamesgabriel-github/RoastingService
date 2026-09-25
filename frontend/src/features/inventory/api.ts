import { api, ensureCsrfCookie } from '@/lib/api'
import type { Service } from '@/features/services/types'
import type { PaginatedInventoryLogs } from './types'

export interface RestockPayload {
  qty: number
  remarks: string | null
}

export interface AdjustPayload {
  change_qty: number
  remarks: string | null
}

export async function fetchInventory(): Promise<Service[]> {
  const { data } = await api.get<Service[]>('/admin/inventory')
  return data
}

export async function restockService(id: number, payload: RestockPayload): Promise<Service> {
  await ensureCsrfCookie()
  const { data } = await api.post<Service>(`/admin/inventory/${id}/restock`, payload)
  return data
}

export async function adjustService(id: number, payload: AdjustPayload): Promise<Service> {
  await ensureCsrfCookie()
  const { data } = await api.post<Service>(`/admin/inventory/${id}/adjust`, payload)
  return data
}

export async function fetchInventoryLogs(page: number): Promise<PaginatedInventoryLogs> {
  const { data } = await api.get<PaginatedInventoryLogs>('/admin/inventory/logs', { params: { page } })
  return data
}
