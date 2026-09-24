import { Link, Outlet } from 'react-router-dom'

export function PublicLayout() {
  return (
    <div className="min-h-svh">
      <header className="flex items-center justify-between border-b p-4">
        <Link to="/" className="font-semibold">
          Roasting Service
        </Link>
        <nav className="flex gap-4 text-sm">
          <Link to="/login">Log in</Link>
          <Link to="/register">Register</Link>
        </nav>
      </header>
      <main className="p-4">
        <Outlet />
      </main>
    </div>
  )
}
