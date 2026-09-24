import { Outlet } from 'react-router-dom'

export function CustomerLayout() {
  return (
    <div className="min-h-svh">
      <header className="border-b p-4">
        <span className="font-semibold">My Account</span>
      </header>
      <main className="p-4">
        <Outlet />
      </main>
    </div>
  )
}
