import { useParams } from 'react-router-dom'
import { formatCurrency } from '@/lib/currency'
import { useAdminBookingDetail } from './hooks'

export function AdminBookingDetailPage() {
  const params = useParams<{ id: string }>()
  const id = Number(params.id)
  const { data: booking, isLoading } = useAdminBookingDetail(id)

  if (isLoading) {
    return <p>Loading…</p>
  }

  if (!booking) {
    return <p>Booking not found.</p>
  }

  const isRoasting = booking.source_type === 'customer_supplied'

  return (
    <div className="flex max-w-lg flex-col gap-6">
      <div>
        <h1 className="text-xl font-semibold">{booking.code}</h1>
        <p className="text-muted-foreground">{isRoasting ? 'Bring your own' : 'Shop order'}</p>
      </div>

      <div className="flex flex-col gap-1 rounded-lg border p-3">
        <h2 className="font-semibold">Customer</h2>
        <p>{booking.customer_name ?? '—'}</p>
        <p className="text-sm text-muted-foreground">{booking.customer_phone ?? '—'}</p>
        <p>Fulfillment: {booking.fulfillment}</p>
        {booking.delivery_address && <p>Delivery address: {booking.delivery_address}</p>}
        {booking.notes && <p>Notes: {booking.notes}</p>}
      </div>

      <div className="flex flex-col gap-2 rounded-lg border p-3">
        <h2 className="font-semibold">Status timeline</h2>
        <ul className="flex flex-col gap-1">
          {booking.status_logs?.map((log) => (
            <li key={log.id} className="text-sm">
              <span className="font-medium">{log.status}</span>{' '}
              <span className="text-muted-foreground">
                - {new Date(log.created_at).toLocaleString()}
                {log.changed_by_name ? ` by ${log.changed_by_name}` : ''}
              </span>
            </li>
          ))}
        </ul>
      </div>

      <div className="flex flex-col gap-2 rounded-lg border p-3">
        <h2 className="font-semibold">Items</h2>
        {booking.items?.map((item) => (
          <div key={item.id} className="flex justify-between text-sm">
            <span>
              {item.service_name}
              {isRoasting
                ? ` - est. ${item.est_weight_kg ?? '—'} kg${item.final_weight_kg ? `, final ${item.final_weight_kg} kg` : ''}`
                : ` x ${item.qty}`}
            </span>
            <span>{formatCurrency(item.subtotal)}</span>
          </div>
        ))}
      </div>

      <div className="rounded-lg border p-3">
        <p>
          {isRoasting ? 'Estimated total' : 'Total'}:{' '}
          <span className="font-semibold">
            {formatCurrency(isRoasting ? booking.estimated_total : (booking.total_amount ?? 0))}
          </span>
        </p>
        {isRoasting && booking.total_amount && (
          <p>
            Final total: <span className="font-semibold">{formatCurrency(booking.total_amount)}</span>
          </p>
        )}
      </div>
    </div>
  )
}
