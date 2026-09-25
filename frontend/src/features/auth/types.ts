export type Role = 'super_admin' | 'admin' | 'customer'

export interface Me {
  id: number
  name: string | null
  email: string | null
  phone: string | null
  first_name: string | null
  middle_name: string | null
  last_name: string | null
  address: string | null
  role: Role
  permissions: string[]
}
