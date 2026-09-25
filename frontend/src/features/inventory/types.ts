export type { Service } from '@/features/services/types'

export interface InventoryLog {
  id: number
  service_id: number
  service_name: string
  change_qty: number
  reason: 'restock' | 'reserve' | 'release' | 'adjust'
  remarks: string | null
  created_by_name: string
  created_at: string
}

export interface PaginatedInventoryLogs {
  data: InventoryLog[]
  meta: {
    current_page: number
    last_page: number
  }
}
