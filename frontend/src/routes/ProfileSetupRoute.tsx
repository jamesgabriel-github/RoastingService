import { Navigate, Outlet } from 'react-router-dom'
import { useMe } from '@/features/auth/hooks'
import { isProfileComplete } from '@/features/auth/profile'

export function ProfileSetupRoute() {
  const { data: me, isLoading } = useMe()

  if (isLoading) {
    return null
  }

  if (!me) {
    return <Navigate to="/login" replace />
  }

  if (me.role !== 'customer') {
    return <Navigate to="/admin" replace />
  }

  if (isProfileComplete(me)) {
    return <Navigate to="/account" replace />
  }

  return <Outlet />
}
