import { Link, Outlet } from 'react-router-dom'

export function CustomerLayout() {
  return (
    <div className="min-h-svh">
      <header className="flex items-center justify-between border-b p-4">
        <span className="font-semibold">My Account</span>
        <nav className="flex gap-4 text-sm">
          <Link to="/book">Book Now</Link>
          <Link to="/account">Account</Link>
        </nav>
      </header>
      <main className="p-4">
        <Outlet />
      </main>
    </div>
  )
}
