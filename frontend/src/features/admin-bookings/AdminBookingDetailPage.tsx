import { isAxiosError } from 'axios'
import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useMe } from '@/features/auth/hooks'
import { formatCurrency } from '@/lib/currency'
import { getGenericErrorMessage } from '@/lib/errors'
import type { RecordPaymentPayload } from './api'
import {
  useAdminBookingDetail,
  useApproveBooking,
  useCancelBooking,
  useCompleteBooking,
  useConfirmOrder,
  useMarkOutForDelivery,
  useMarkReady,
  useNoShowBooking,
  useRecordPayment,
  useRejectBooking,
  useRejectOrder,
  useStartCooking,
  useWeighInBooking,
} from './hooks'
import type { AdminBooking, AdminBookingItem } from './types'
import { getCommonStatus, isAdminCancellable } from './types'

const PAYMENT_ELIGIBLE_STATUSES = ['confirmed', 'cooking', 'ready', 'out_for_delivery', 'completed']

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

  const items = booking.items
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

function StartCookingAction({ item }: { item: AdminBookingItem }) {
  const [error, setError] = useState<string | null>(null)
  const startCooking = useStartCooking()

  const onStart = () => {
    setError(null)
    startCooking.mutate(item.id, { onError: (err) => setError(getActionErrorMessage(err)) })
  }

  return (
    <div className="flex flex-col gap-1">
      <Button size="sm" disabled={startCooking.isPending} onClick={onStart}>
        Start cooking
      </Button>
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}

function CookingActions({ booking, item }: { booking: AdminBooking; item: AdminBookingItem }) {
  const [error, setError] = useState<string | null>(null)
  const markReady = useMarkReady()
  const markOutForDelivery = useMarkOutForDelivery()
  const isSaving = markReady.isPending || markOutForDelivery.isPending

  const onAdvance = () => {
    setError(null)
    if (booking.fulfillment === 'pickup') {
      markReady.mutate(item.id, { onError: (err) => setError(getActionErrorMessage(err)) })
    } else {
      markOutForDelivery.mutate(item.id, { onError: (err) => setError(getActionErrorMessage(err)) })
    }
  }

  return (
    <div className="flex flex-col gap-1">
      <Button size="sm" disabled={isSaving} onClick={onAdvance}>
        {booking.fulfillment === 'pickup' ? 'Mark ready' : 'Out for delivery'}
      </Button>
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}

function CompleteAction({ item }: { item: AdminBookingItem }) {
  const [error, setError] = useState<string | null>(null)
  const complete = useCompleteBooking()

  const onComplete = () => {
    setError(null)
    complete.mutate(item.id, { onError: (err) => setError(getActionErrorMessage(err)) })
  }

  return (
    <div className="flex flex-col gap-1">
      <Button size="sm" disabled={complete.isPending} onClick={onComplete}>
        Complete
      </Button>
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}

function ItemFulfillmentActions({ booking, item }: { booking: AdminBooking; item: AdminBookingItem }) {
  if (item.status === 'confirmed') {
    return <StartCookingAction item={item} />
  }
  if (item.status === 'cooking') {
    return <CookingActions booking={booking} item={item} />
  }
  if (item.status === 'ready' || item.status === 'out_for_delivery') {
    return <CompleteAction item={item} />
  }
  return null
}

function NoShowAction({ booking }: { booking: AdminBooking }) {
  const [remarks, setRemarks] = useState('')
  const [error, setError] = useState<string | null>(null)
  const noShow = useNoShowBooking()

  const onNoShow = () => {
    setError(null)
    noShow.mutate(
      { id: booking.id, payload: { remarks: remarks.trim() || undefined } },
      { onError: (err) => setError(getActionErrorMessage(err)) }
    )
  }

  return (
    <div className="flex flex-col gap-3 rounded-lg border p-3">
      <h2 className="font-semibold">No-show</h2>
      <div className="flex flex-col gap-1">
        <Label htmlFor="no_show_remarks">Remarks (optional)</Label>
        <div className="flex gap-2">
          <Input id="no_show_remarks" value={remarks} onChange={(event) => setRemarks(event.target.value)} />
          <Button variant="outline" disabled={noShow.isPending} onClick={onNoShow}>
            Mark no-show
          </Button>
        </div>
      </div>
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}

function CancelAction({ booking }: { booking: AdminBooking }) {
  const [remarks, setRemarks] = useState('')
  const [error, setError] = useState<string | null>(null)
  const cancel = useCancelBooking()

  const onCancel = () => {
    setError(null)
    cancel.mutate(
      { id: booking.id, payload: { remarks: remarks.trim() || undefined } },
      { onError: (err) => setError(getActionErrorMessage(err)) }
    )
  }

  return (
    <div className="flex flex-col gap-3 rounded-lg border p-3">
      <h2 className="font-semibold">Cancel</h2>
      <div className="flex flex-col gap-1">
        <Label htmlFor="cancel_remarks">Remarks (optional)</Label>
        <div className="flex gap-2">
          <Input id="cancel_remarks" value={remarks} onChange={(event) => setRemarks(event.target.value)} />
          <Button variant="outline" disabled={cancel.isPending} onClick={onCancel}>
            Cancel booking
          </Button>
        </div>
      </div>
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}

function RecordPaymentActions({ booking }: { booking: AdminBooking }) {
  const [method, setMethod] = useState<RecordPaymentPayload['method']>('cash')
  const [referenceNo, setReferenceNo] = useState('')
  const [error, setError] = useState<string | null>(null)
  const recordPayment = useRecordPayment()

  const onRecordPayment = () => {
    setError(null)
    recordPayment.mutate(
      { id: booking.id, payload: { method, reference_no: referenceNo.trim() || null } },
      { onError: (err) => setError(getActionErrorMessage(err)) }
    )
  }

  return (
    <div className="flex flex-col gap-3 rounded-lg border p-3">
      <h2 className="font-semibold">Record payment</h2>

      <div className="flex flex-col gap-1">
        <Label htmlFor="payment_method">Method</Label>
        <select
          id="payment_method"
          className="h-8 w-full rounded-lg border border-input bg-transparent px-2.5 py-1 text-base outline-none md:text-sm"
          value={method}
          onChange={(event) => setMethod(event.target.value as RecordPaymentPayload['method'])}
        >
          <option value="cash">Cash</option>
          <option value="gcash">GCash</option>
          <option value="card">Card</option>
        </select>
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor="payment_reference_no">Reference number (optional)</Label>
        <Input
          id="payment_reference_no"
          value={referenceNo}
          onChange={(event) => setReferenceNo(event.target.value)}
        />
      </div>

      <Button disabled={recordPayment.isPending} onClick={onRecordPayment}>
        Record payment
      </Button>
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}

export function AdminBookingDetailPage() {
  const params = useParams<{ id: string }>()
  const id = Number(params.id)
  const { data: booking, isLoading } = useAdminBookingDetail(id)
  const { data: me } = useMe()

  if (isLoading) {
    return <p>Loading…</p>
  }

  if (!booking) {
    return <p>Booking not found.</p>
  }

  const isOrder = booking.is_order
  const commonStatus = getCommonStatus(booking.items)
  const canRecordPayment =
    booking.items.every((item) => PAYMENT_ELIGIBLE_STATUSES.includes(item.status)) &&
    Number(booking.balance) > 0 &&
    (me?.role === 'super_admin' || me?.permissions.includes('payments'))

  return (
    <div className="flex max-w-lg flex-col gap-6">
      <div>
        <h1 className="text-xl font-semibold">{booking.code}</h1>
        <p className="text-muted-foreground">{isOrder ? 'Is order' : 'Not order'}</p>
      </div>

      <div className="flex flex-col gap-1 rounded-lg border p-3">
        <h2 className="font-semibold">Customer</h2>
        <p>{booking.customer_name ?? '—'}</p>
        <p className="text-sm text-muted-foreground">{booking.customer_phone ?? '—'}</p>
        <p>Fulfillment: {booking.fulfillment}</p>
        {booking.delivery_address && <p>Delivery address: {booking.delivery_address}</p>}
        {booking.preferred_pickup_at && (
          <p>Preferred pickup/delivery: {new Date(booking.preferred_pickup_at).toLocaleString()}</p>
        )}
        {booking.notes && <p>Notes: {booking.notes}</p>}
        {booking.dropoff_at && <p>Scheduled drop-off: {new Date(booking.dropoff_at).toLocaleString()}</p>}
      </div>

      {!isOrder && commonStatus === 'pending_review' && <ApproveRejectActions booking={booking} />}
      {!isOrder && commonStatus === 'approved' && <WeighInActions booking={booking} />}
      {!isOrder && commonStatus === 'approved' && <NoShowAction booking={booking} />}
      {isOrder && commonStatus === 'pending_confirmation' && <ConfirmRejectOrderActions booking={booking} />}
      {isAdminCancellable(booking) && <CancelAction booking={booking} />}

      <div className="flex flex-col gap-2 rounded-lg border p-3">
        <h2 className="font-semibold">Status timeline</h2>
        <ul className="flex flex-col gap-1">
          {booking.status_logs?.map((log) => (
            <li key={log.id} className="text-sm">
              <span className="font-medium">
                {booking.items.length > 1 && log.service_name ? `${log.service_name}: ` : ''}
                {log.status}
              </span>{' '}
              <span className="text-muted-foreground">
                - {new Date(log.created_at).toLocaleString()}
                {log.changed_by_name ? ` by ${log.changed_by_name}` : ''}
              </span>
            </li>
          ))}
        </ul>
      </div>

      <div className="flex flex-col gap-3 rounded-lg border p-3">
        <h2 className="font-semibold">Items</h2>
        {booking.items.map((item) => (
          <div key={item.id} className="flex flex-col gap-1 border-b pb-3 last:border-b-0 last:pb-0">
            <div className="flex justify-between text-sm">
              <span>
                {item.service_name}
                {!isOrder
                  ? ` - est. ${item.est_weight_kg ?? '—'} kg${item.final_weight_kg ? `, final ${item.final_weight_kg} kg` : ''}`
                  : ` x ${item.qty}`}
              </span>
              <span>{formatCurrency(item.subtotal)}</span>
            </div>
            <p className="text-sm text-muted-foreground">
              Status: {item.status}
              {item.reject_reason ? ` - ${item.reject_reason}` : ''}
            </p>
            {item.approved_at && (
              <p className="text-sm text-muted-foreground">
                Approved: {new Date(item.approved_at).toLocaleString()}
                {item.approved_by_name ? ` by ${item.approved_by_name}` : ''}
              </p>
            )}
            {item.weighed_at && (
              <p className="text-sm text-muted-foreground">
                Weighed in: {new Date(item.weighed_at).toLocaleString()}
                {item.confirmed_by_name ? ` - confirmed by ${item.confirmed_by_name}` : ''}
              </p>
            )}
            {item.cooking_started_at && (
              <p className="text-sm text-muted-foreground">
                Cooking started: {new Date(item.cooking_started_at).toLocaleString()}
              </p>
            )}
            {item.est_ready_at && (
              <p className="text-sm text-muted-foreground">
                Estimated ready: {new Date(item.est_ready_at).toLocaleString()}
              </p>
            )}
            {item.completed_at && (
              <p className="text-sm text-muted-foreground">
                Completed: {new Date(item.completed_at).toLocaleString()}
              </p>
            )}
            <ItemFulfillmentActions booking={booking} item={item} />
          </div>
        ))}
      </div>

      <div className="rounded-lg border p-3">
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
        {booking.total_amount && (
          <>
            <p>
              Paid: <span className="font-semibold">{formatCurrency(booking.paid_amount)}</span>
            </p>
            <p>
              Balance: <span className="font-semibold">{formatCurrency(booking.balance ?? 0)}</span>
            </p>
          </>
        )}
      </div>

      {canRecordPayment && <RecordPaymentActions booking={booking} />}
    </div>
  )
}
