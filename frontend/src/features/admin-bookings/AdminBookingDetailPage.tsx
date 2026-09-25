import { isAxiosError } from 'axios'
import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { formatCurrency } from '@/lib/currency'
import { getGenericErrorMessage } from '@/lib/errors'
import {
  useAdminBookingDetail,
  useApproveBooking,
  useConfirmOrder,
  useRejectBooking,
  useRejectOrder,
  useWeighInBooking,
} from './hooks'
import type { AdminBooking } from './types'

function getActionErrorMessage(error: unknown): string {
  if (isAxiosError(error) && error.response?.status === 422) {
    const errors = error.response.data?.errors as Record<string, string[]> | undefined
    const firstMessage = errors ? Object.values(errors)[0]?.[0] : undefined
    if (firstMessage) {
      return firstMessage
    }
  }
  return getGenericErrorMessage(error)
}

function ApproveRejectActions({ booking }: { booking: AdminBooking }) {
  const [dropoffAt, setDropoffAt] = useState('')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const approve = useApproveBooking()
  const reject = useRejectBooking()
  const isSaving = approve.isPending || reject.isPending

  const onApprove = () => {
    if (!dropoffAt) {
      setError('Choose a drop-off date and time.')
      return
    }
    setError(null)
    approve.mutate(
      { id: booking.id, payload: { dropoff_at: new Date(dropoffAt).toISOString() } },
      { onError: (err) => setError(getActionErrorMessage(err)) }
    )
  }

  const onReject = () => {
    if (!reason.trim()) {
      setError('Enter a reason for rejecting this booking.')
      return
    }
    setError(null)
    reject.mutate(
      { id: booking.id, payload: { reason: reason.trim() } },
      { onError: (err) => setError(getActionErrorMessage(err)) }
    )
  }

  return (
    <div className="flex flex-col gap-3 rounded-lg border p-3">
      <h2 className="font-semibold">Actions</h2>

      <div className="flex flex-col gap-1">
        <Label htmlFor="dropoff_at">Schedule drop-off</Label>
        <div className="flex gap-2">
          <Input
            id="dropoff_at"
            type="datetime-local"
            value={dropoffAt}
            onChange={(event) => setDropoffAt(event.target.value)}
          />
          <Button disabled={isSaving} onClick={onApprove}>
            Approve
          </Button>
        </div>
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor="reject_reason">Reject reason</Label>
        <div className="flex gap-2">
          <Input id="reject_reason" value={reason} onChange={(event) => setReason(event.target.value)} />
          <Button variant="outline" disabled={isSaving} onClick={onReject}>
            Reject
          </Button>
        </div>
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}

function ConfirmRejectOrderActions({ booking }: { booking: AdminBooking }) {
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const confirm = useConfirmOrder()
  const reject = useRejectOrder()
  const isSaving = confirm.isPending || reject.isPending

  const onConfirm = () => {
    setError(null)
    confirm.mutate(booking.id, { onError: (err) => setError(getActionErrorMessage(err)) })
  }

  const onReject = () => {
    if (!reason.trim()) {
      setError('Enter a reason for rejecting this order.')
      return
    }
    setError(null)
    reject.mutate(
      { id: booking.id, payload: { reason: reason.trim() } },
      { onError: (err) => setError(getActionErrorMessage(err)) }
    )
  }

  return (
    <div className="flex flex-col gap-3 rounded-lg border p-3">
      <h2 className="font-semibold">Actions</h2>

      <Button disabled={isSaving} onClick={onConfirm}>
        Confirm order
      </Button>

      <div className="flex flex-col gap-1">
        <Label htmlFor="reject_order_reason">Reject reason</Label>
        <div className="flex gap-2">
          <Input id="reject_order_reason" value={reason} onChange={(event) => setReason(event.target.value)} />
          <Button variant="outline" disabled={isSaving} onClick={onReject}>
            Reject
          </Button>
        </div>
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}

function WeighInActions({ booking }: { booking: AdminBooking }) {
  const [weights, setWeights] = useState<Record<number, string>>({})
  const [error, setError] = useState<string | null>(null)
  const weighIn = useWeighInBooking()

  const items = booking.items ?? []
  const canSubmit = items.every((item) => Number(weights[item.id]) > 0)

  const onWeighIn = () => {
    if (!canSubmit) {
      setError('Enter a final weight for every item.')
      return
    }
    setError(null)
    weighIn.mutate(
      {
        id: booking.id,
        payload: {
          items: items.map((item) => ({ id: item.id, final_weight_kg: Number(weights[item.id]) })),
        },
      },
      { onError: (err) => setError(getActionErrorMessage(err)) }
    )
  }

  return (
    <div className="flex flex-col gap-3 rounded-lg border p-3">
      <h2 className="font-semibold">Weigh in</h2>
      {items.map((item) => (
        <div key={item.id} className="flex flex-col gap-1">
          <Label htmlFor={`weight-${item.id}`}>{item.service_name} - final weight (kg)</Label>
          <Input
            id={`weight-${item.id}`}
            type="number"
            step="0.01"
            min="0.01"
            placeholder={item.est_weight_kg ?? undefined}
            value={weights[item.id] ?? ''}
            onChange={(event) => setWeights((current) => ({ ...current, [item.id]: event.target.value }))}
          />
        </div>
      ))}
      <Button disabled={weighIn.isPending || !canSubmit} onClick={onWeighIn}>
        Confirm weigh-in
      </Button>
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}

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
        {booking.dropoff_at && (
          <p>
            Scheduled drop-off: {new Date(booking.dropoff_at).toLocaleString()}
            {booking.approved_by_name ? ` - approved by ${booking.approved_by_name}` : ''}
          </p>
        )}
        {booking.status === 'rejected' && booking.reject_reason && <p>Rejected: {booking.reject_reason}</p>}
        {booking.weighed_at && (
          <p>
            Weighed in: {new Date(booking.weighed_at).toLocaleString()}
            {booking.confirmed_by_name ? ` - confirmed by ${booking.confirmed_by_name}` : ''}
          </p>
        )}
      </div>

      {isRoasting && booking.status === 'pending_review' && <ApproveRejectActions booking={booking} />}
      {isRoasting && booking.status === 'approved' && <WeighInActions booking={booking} />}
      {!isRoasting && booking.status === 'pending_confirmation' && <ConfirmRejectOrderActions booking={booking} />}

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
