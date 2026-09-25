import { Link, Outlet, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { useAdminLogout, useMe } from '@/features/auth/hooks'

export function AdminLayout() {
  const { data: me } = useMe()
  const adminLogout = useAdminLogout()
  const navigate = useNavigate()

  return (
    <div className="min-h-svh">
      <header className="flex items-center justify-between border-b p-4">
        <div className="flex items-center gap-4">
          <Link to="/admin" className="font-semibold">
            Admin
          </Link>
          {me?.role === 'super_admin' && <Link to="/admin/accounts">Admin accounts</Link>}
          {(me?.role === 'super_admin' || me?.permissions.includes('services')) && (
            <Link to="/admin/services">Services</Link>
          )}
          {(me?.role === 'super_admin' || me?.permissions.includes('inventory')) && (
            <Link to="/admin/inventory">Inventory</Link>
          )}
          {(me?.role === 'super_admin' || me?.permissions.includes('bookings')) && (
            <Link to="/admin/bookings">Bookings</Link>
          )}
        </div>
        <Button
          size="sm"
          variant="outline"
          disabled={adminLogout.isPending}
          onClick={() => adminLogout.mutate(undefined, { onSuccess: () => navigate('/admin/login') })}
        >
          Log out
        </Button>
      </header>
      <main className="p-4">
        <Outlet />
      </main>
    </div>
  )
}
