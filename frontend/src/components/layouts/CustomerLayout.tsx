import { Link, Outlet } from 'react-router-dom'

export function CustomerLayout() {
  return (
    <div className="flex h-svh flex-col overflow-hidden">
      <header className="flex shrink-0 items-center justify-between border-b p-4">
        <span className="font-semibold">My Account</span>
        <nav className="flex gap-4 text-sm">
          <Link to="/book">Book Now</Link>
          <Link to="/bookings">My Bookings</Link>
          <Link to="/account">Account</Link>
        </nav>
      </header>
      <main className="flex-1 overflow-y-auto p-4">
        <Outlet />
      </main>
    </div>
  )
}
