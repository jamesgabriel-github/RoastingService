export const MODULES = ['services', 'inventory', 'bookings', 'payments', 'dashboard'] as const

export type Module = (typeof MODULES)[number]

export interface AdminAccount {
  id: number
  name: string
  email: string
  phone: string | null
  role: 'admin'
  is_active: boolean
  permissions: string[]
}
