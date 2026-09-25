import { Navigate, Outlet } from 'react-router-dom'
import type { Module } from '@/features/admin-accounts/types'
import { useMe } from '@/features/auth/hooks'
import type { Role } from '@/features/auth/types'

export function RoleRoute({ allow, requireModule }: { allow: Role[]; requireModule?: Module }) {
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

  if (requireModule && !me.permissions.includes(requireModule)) {
    return <p className="p-8">You are not authorized to view this page.</p>
  }

  return <Outlet />
}
