import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { parseQueueStatus, QUEUE_TABS } from '@/features/admin-bookings/types'
import { useAdminLogout, useMe } from '@/features/auth/hooks'

const navLinkClass = ({ isActive }: { isActive: boolean }) =>
  `block rounded px-3 py-2 text-sm ${isActive ? 'bg-muted font-medium' : 'hover:bg-muted'}`

const subLinkClass = (isActive: boolean) =>
  `block rounded px-3 py-2 text-sm ${isActive ? 'bg-muted font-medium' : 'hover:bg-muted'}`

export function AdminLayout() {
  const { data: me } = useMe()
  const adminLogout = useAdminLogout()
  const navigate = useNavigate()
  const location = useLocation()

  const canSeeBookings = me?.role === 'super_admin' || me?.permissions.includes('bookings')
  const activeBookingStatus =
    location.pathname === '/admin/bookings' ? parseQueueStatus(new URLSearchParams(location.search).get('status')) : null

  return (
    <div className="flex min-h-svh">
      <aside className="flex w-56 shrink-0 flex-col border-r p-4">
        <NavLink to="/admin" className="mb-6 block font-semibold" end>
          Admin
        </NavLink>

        <nav className="flex flex-1 flex-col gap-1">
          {(me?.role === 'super_admin' || me?.permissions.includes('dashboard')) && (
            <NavLink to="/admin/dashboard" className={navLinkClass}>
              Dashboard
            </NavLink>
          )}

          {canSeeBookings && (
            <div>
              <div className="px-3 py-2 text-sm text-muted-foreground">Booking</div>
              <div className="flex flex-col gap-1 pl-3">
                {QUEUE_TABS.map((tab) => (
                  <Link
                    key={tab.status}
                    to={`/admin/bookings?status=${tab.status}`}
                    className={subLinkClass(activeBookingStatus === tab.status)}
                  >
                    {tab.label}
                  </Link>
                ))}
              </div>
            </div>
          )}

          {(me?.role === 'super_admin' || me?.permissions.includes('services')) && (
            <NavLink to="/admin/services" className={navLinkClass}>
              Services
            </NavLink>
          )}

          {(me?.role === 'super_admin' || me?.permissions.includes('inventory')) && (
            <NavLink to="/admin/inventory" className={navLinkClass}>
              Inventory
            </NavLink>
          )}

          {(me?.role === 'super_admin' || me?.permissions.includes('payments')) && (
            <NavLink to="/admin/payments" className={navLinkClass}>
              Payments
            </NavLink>
          )}

          {me?.role === 'super_admin' && (
            <NavLink to="/admin/accounts" className={navLinkClass}>
              Admin accounts
            </NavLink>
          )}
        </nav>

        <Button
          size="sm"
          variant="outline"
          disabled={adminLogout.isPending}
          onClick={() => adminLogout.mutate(undefined, { onSuccess: () => navigate('/admin/login') })}
        >
          Log out
        </Button>
      </aside>

      <main className="flex-1 p-4">
        <Outlet />
      </main>
    </div>
  )
}
