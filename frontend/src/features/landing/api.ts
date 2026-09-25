import { api } from '@/lib/api'
import type { LandingService } from './types'

export async function fetchActiveServices(): Promise<LandingService[]> {
  const { data } = await api.get<LandingService[]>('/services')
  return data
}
