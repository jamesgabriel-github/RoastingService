import { Link } from 'react-router-dom'
import { formatCurrency } from '@/lib/currency'
import { useMyBookings } from './hooks'
import { humanizeStatus } from './status'

export function MyBookingsPage() {
  const { data: bookings, isLoading } = useMyBookings()

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-xl font-semibold">My bookings</h1>

      {isLoading && <p>Loading…</p>}
      {bookings && bookings.length === 0 && <p className="text-muted-foreground">No bookings yet.</p>}

      {bookings && bookings.length > 0 && (
        <ul className="flex flex-col gap-2">
          {bookings.map((booking) => (
            <li key={booking.id}>
              <Link
                to={`/bookings/${booking.id}`}
                className="flex items-center justify-between rounded-lg border p-3 hover:bg-muted"
              >
                <div>
                  <p className="font-mono font-semibold">{booking.code}</p>
                  <p className="text-sm text-muted-foreground">
                    {booking.is_order ? 'Is order' : 'Not order'}
                  </p>
                </div>
                <div className="text-right">
                  <p>{booking.status ? humanizeStatus(booking.status) : 'Mixed'}</p>
                  <p className="text-sm text-muted-foreground">
                    {formatCurrency(
                      booking.is_order ? (booking.total_amount ?? 0) : booking.estimated_total
                    )}
                  </p>
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
