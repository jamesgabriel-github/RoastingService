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

export interface RegisterPayload {
  name: string
  email: string
  phone: string
  password: string
}

export async function registerCustomer(payload: RegisterPayload): Promise<Me> {
  await ensureCsrfCookie()
  const { data } = await api.post<Me>('/register', payload)
  return data
}

export async function loginCustomer(phone: string): Promise<void> {
  await ensureCsrfCookie()
  await api.post('/login', { phone })
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
