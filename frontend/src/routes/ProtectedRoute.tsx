import { Navigate, Outlet } from 'react-router-dom'
import { useMe } from '@/features/auth/hooks'

export function ProtectedRoute() {
  const { data: me, isLoading } = useMe()

  if (isLoading) {
    return null
  }

  if (!me) {
    return <Navigate to="/login" replace />
  }

  return <Outlet />
}
