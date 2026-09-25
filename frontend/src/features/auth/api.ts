import { isAxiosError } from 'axios'
import { api, ensureCsrfCookie } from '@/lib/api'
import type { Me } from './types'

export async function fetchMe(): Promise<Me | null> {
  try {
    const { data } = await api.get<Me>('/me')
    return data
  } catch (error) {
    if (isAxiosError(error) && error.response?.status === 401) {
      return null
    }
    throw error
  }
}

export async function loginCustomer(phone: string): Promise<Me> {
  await ensureCsrfCookie()
  const { data } = await api.post<Me>('/login', { phone })
  return data
}

export interface CompleteProfilePayload {
  first_name: string
  middle_name?: string
  last_name: string
  address: string
}

export async function completeProfile(payload: CompleteProfilePayload): Promise<Me> {
  await ensureCsrfCookie()
  const { data } = await api.post<Me>('/profile/complete', payload)
  return data
}

export interface UpdateProfilePayload {
  first_name: string
  middle_name: string | null
  last_name: string
  address: string
}

export async function updateProfile(payload: UpdateProfilePayload): Promise<Me> {
  await ensureCsrfCookie()
  const { data } = await api.patch<Me>('/profile', payload)
  return data
}

export async function adminLogin(email: string, password: string): Promise<void> {
  await ensureCsrfCookie()
  await api.post('/admin/login', { email, password })
}

export async function logout(): Promise<void> {
  await ensureCsrfCookie()
  await api.post('/logout')
}

export async function adminLogout(): Promise<void> {
  await ensureCsrfCookie()
  await api.post('/admin/logout')
}
