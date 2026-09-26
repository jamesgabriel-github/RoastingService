import { isAxiosError } from 'axios'
import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { formatCurrency } from '@/lib/currency'
import { getGenericErrorMessage } from '@/lib/errors'
import { useBookingDetail, useCancelBooking } from './hooks'
import { humanizeStatus, isCancellable } from './status'

export function BookingDetailPage() {
  const params = useParams<{ id: string }>()
  const id = Number(params.id)
  const { data: booking, isLoading } = useBookingDetail(id)
  const cancelBooking = useCancelBooking()
  const [cancelError, setCancelError] = useState<string | null>(null)

  if (isLoading) {
    return <p>Loading…</p>
  }

  if (!booking) {
    return <p>Booking not found.</p>
  }

  const isOrder = booking.is_order

  return (
    <div className="flex max-w-lg flex-col gap-6">
      <div>
        <h1 className="text-xl font-semibold">{booking.code}</h1>
        <p className="text-muted-foreground">{isOrder ? 'Is order' : 'Not order'}</p>
      </div>

      <div className="flex flex-col gap-2 rounded-lg border p-3">
        <h2 className="font-semibold">Status timeline</h2>
        <ul className="flex flex-col gap-1">
          {booking.status_logs?.map((log) => (
            <li key={log.id} className="text-sm">
              <span className="font-medium">
                {booking.items.length > 1 && log.service_name ? `${log.service_name}: ` : ''}
                {humanizeStatus(log.status)}
              </span>{' '}
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
        {booking.items.map((item) => (
          <div key={item.id} className="flex justify-between text-sm">
            <span>
              {item.service_name}
              {!isOrder
                ? ` - est. ${item.est_weight_kg ?? '—'} kg${item.final_weight_kg ? `, final ${item.final_weight_kg} kg` : ''}`
                : ` x ${item.qty}`}
            </span>
            <span>{formatCurrency(item.subtotal)}</span>
          </div>
        ))}
      </div>

      <div className="flex flex-col gap-1 rounded-lg border p-3">
        <p>
          {!isOrder ? 'Estimated total' : 'Total'}:{' '}
          <span className="font-semibold">
            {formatCurrency(!isOrder ? booking.estimated_total : (booking.total_amount ?? 0))}
          </span>
        </p>
        {!isOrder && booking.total_amount && (
          <p>
            Final total: <span className="font-semibold">{formatCurrency(booking.total_amount)}</span>
          </p>
        )}
        <p>Fulfillment: {booking.fulfillment}</p>
        {booking.delivery_address && <p>Delivery address: {booking.delivery_address}</p>}
        {booking.preferred_pickup_at && (
          <p>Preferred pickup/delivery: {new Date(booking.preferred_pickup_at).toLocaleString()}</p>
        )}
        {booking.notes && <p>Notes: {booking.notes}</p>}
      </div>

      {cancelError && <p className="text-sm text-destructive">{cancelError}</p>}

      {isCancellable(booking) && (
        <Button
          variant="destructive"
          className="w-fit"
          disabled={cancelBooking.isPending}
          onClick={() => {
            setCancelError(null)
            cancelBooking.mutate(booking.id, {
              onError: (error) => {
                if (isAxiosError(error) && error.response?.status === 422) {
                  const fieldErrors = error.response.data?.errors as Record<string, string[]> | undefined
                  const message = Object.values(fieldErrors ?? {})[0]?.[0]
                  setCancelError(message ?? getGenericErrorMessage(error))
                  return
                }
                setCancelError(getGenericErrorMessage(error))
              },
            })
          }}
        >
          {cancelBooking.isPending ? 'Cancelling…' : 'Cancel booking'}
        </Button>
      )}
    </div>
  )
}
