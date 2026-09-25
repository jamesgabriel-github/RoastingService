import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { formatCurrency } from '@/lib/currency'
import { useAdminBookings, useBookingCounts } from './hooks'
import { QUEUE_TABS } from './types'
import type { QueueStatus } from './types'
import { formatWaitingTime } from './waitingTime'

export function AdminBookingsPage() {
  const [status, setStatus] = useState<QueueStatus>('pending_review')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const { data: counts } = useBookingCounts()
  const { data, isLoading } = useAdminBookings(status, search, page)

  const selectTab = (next: QueueStatus) => {
    setStatus(next)
    setPage(1)
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-semibold">Bookings</h1>
        <Link to="/admin/bookings/walk-in" className="text-primary underline-offset-4 hover:underline">
          New walk-in
        </Link>
      </div>

      <div className="flex flex-wrap gap-2">
        {QUEUE_TABS.map((tab) => (
          <Button
            key={tab.status}
            size="sm"
            variant={status === tab.status ? 'default' : 'outline'}
            onClick={() => selectTab(tab.status)}
          >
            {tab.label} ({counts?.[tab.status] ?? 0})
          </Button>
        ))}
      </div>

      <Input
        className="max-w-sm"
        placeholder="Search by code, name, or phone"
        value={search}
        onChange={(event) => {
          setSearch(event.target.value)
          setPage(1)
        }}
      />

      {isLoading && <p>Loading…</p>}
      {data && data.data.length === 0 && <p className="text-muted-foreground">No bookings in this queue.</p>}

      {data && data.data.length > 0 && (
        <>
          <table className="w-full border-collapse text-left">
            <thead>
              <tr className="border-b">
                <th className="p-2">Code</th>
                <th className="p-2">Customer</th>
                <th className="p-2">Type</th>
                <th className="p-2">Total</th>
                <th className="p-2">Waiting</th>
                <th className="p-2"></th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((booking) => (
                <tr key={booking.id} className="border-b">
                  <td className="p-2 font-mono">{booking.code}</td>
                  <td className="p-2">
                    <div>{booking.customer_name ?? '—'}</div>
                    <div className="text-sm text-muted-foreground">{booking.customer_phone ?? '—'}</div>
                  </td>
                  <td className="p-2">{booking.source_type === 'customer_supplied' ? 'Bring your own' : 'Shop'}</td>
                  <td className="p-2">
                    {formatCurrency(
                      booking.source_type === 'customer_supplied' ? booking.estimated_total : (booking.total_amount ?? 0)
                    )}
                  </td>
                  <td className="p-2">{formatWaitingTime(booking.waiting_minutes)}</td>
                  <td className="p-2">
                    <Link to={`/admin/bookings/${booking.id}`} className="text-primary underline-offset-4 hover:underline">
                      View
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="flex items-center gap-2">
            <Button
              size="sm"
              variant="outline"
              disabled={data.meta.current_page <= 1}
              onClick={() => setPage((current) => current - 1)}
            >
              Previous
            </Button>
            <span className="text-sm">
              Page {data.meta.current_page} of {data.meta.last_page}
            </span>
            <Button
              size="sm"
              variant="outline"
              disabled={data.meta.current_page >= data.meta.last_page}
              onClick={() => setPage((current) => current + 1)}
            >
              Next
            </Button>
          </div>
        </>
      )}
    </div>
  )
}
