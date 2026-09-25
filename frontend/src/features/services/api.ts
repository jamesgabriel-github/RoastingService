import { api, ensureCsrfCookie } from '@/lib/api'
import type { Service } from './types'

export interface ServicePayload {
  name: string
  description: string | null
  est_minutes: number
  allow_customer_supplied: boolean
  allow_shop_supplied: boolean
  roasting_rate_per_kg: string | null
  shop_price: string | null
}

export async function fetchServices(): Promise<Service[]> {
  const { data } = await api.get<Service[]>('/admin/services')
  return data
}

export async function createService(payload: ServicePayload): Promise<Service> {
  await ensureCsrfCookie()
  const { data } = await api.post<Service>('/admin/services', payload)
  return data
}

export async function updateService(id: number, payload: ServicePayload): Promise<Service> {
  await ensureCsrfCookie()
  const { data } = await api.put<Service>(`/admin/services/${id}`, payload)
  return data
}

export async function toggleService(id: number): Promise<Service> {
  await ensureCsrfCookie()
  const { data } = await api.patch<Service>(`/admin/services/${id}/toggle`)
  return data
}
