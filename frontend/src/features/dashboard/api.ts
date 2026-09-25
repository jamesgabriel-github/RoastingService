import { api } from '@/lib/api'
import type { Dashboard } from './types'

export async function fetchDashboard(): Promise<Dashboard> {
  const { data } = await api.get<Dashboard>('/admin/dashboard')
  return data
}
