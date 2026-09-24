export type Role = 'super_admin' | 'admin' | 'customer'

export interface Me {
  id: number
  name: string
  email: string
  phone: string | null
  role: Role
  permissions: string[]
}
