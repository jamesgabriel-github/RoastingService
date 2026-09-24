import { Navigate, Outlet } from 'react-router-dom'
import { useMe } from '@/features/auth/hooks'
import type { Role } from '@/features/auth/types'

export function RoleRoute({ allow }: { allow: Role[] }) {
  const { data: me, isLoading } = useMe()

  if (isLoading) {
    return null
  }

  if (!me) {
    return <Navigate to="/admin/login" replace />
  }

  if (!allow.includes(me.role)) {
    return <p className="p-8">You are not authorized to view this page.</p>
  }

  return <Outlet />
}
