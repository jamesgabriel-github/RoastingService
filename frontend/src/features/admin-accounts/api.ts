import { api, ensureCsrfCookie } from '@/lib/api'
import type { AdminAccount } from './types'

export async function fetchAdminAccounts(): Promise<AdminAccount[]> {
  const { data } = await api.get<AdminAccount[]>('/admin/accounts')
  return data
}

export interface CreateAdminAccountPayload {
  name: string
  email: string
  password: string
  permissions?: string[]
}

export async function createAdminAccount(
  payload: CreateAdminAccountPayload
): Promise<AdminAccount> {
  await ensureCsrfCookie()
  const { data } = await api.post<AdminAccount>('/admin/accounts', payload)
  return data
}

export interface UpdateAdminAccountPayload {
  is_active?: boolean
  permissions?: string[]
}

export async function updateAdminAccount(
  id: number,
  payload: UpdateAdminAccountPayload
): Promise<AdminAccount> {
  await ensureCsrfCookie()
  const { data } = await api.patch<AdminAccount>(`/admin/accounts/${id}`, payload)
  return data
}
