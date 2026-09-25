import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { formatCurrency } from '@/lib/currency'
import { usePayments } from './hooks'
import { METHOD_OPTIONS } from './types'
import type { PaymentMethod } from './types'

export function PaymentsListPage() {
  const [method, setMethod] = useState<PaymentMethod | ''>('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = usePayments(method, search, page)

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-xl font-semibold">Payments</h1>

      <div className="flex flex-wrap gap-2">
        <select
          className="h-8 rounded-lg border border-input bg-transparent px-2.5 py-1 text-base outline-none md:text-sm"
          value={method}
          onChange={(event) => {
            setMethod(event.target.value as PaymentMethod | '')
            setPage(1)
          }}
        >
          {METHOD_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>

        <Input
          className="max-w-sm"
          placeholder="Search by booking code, name, or phone"
          value={search}
          onChange={(event) => {
            setSearch(event.target.value)
            setPage(1)
          }}
        />
      </div>

      {isLoading && <p>Loading…</p>}
      {data && data.data.length === 0 && <p className="text-muted-foreground">No payments recorded yet.</p>}

      {data && data.data.length > 0 && (
        <>
          <table className="w-full border-collapse text-left">
            <thead>
              <tr className="border-b">
                <th className="p-2">Booking</th>
                <th className="p-2">Customer</th>
                <th className="p-2">Amount</th>
                <th className="p-2">Method</th>
                <th className="p-2">Reference no.</th>
                <th className="p-2">Paid at</th>
                <th className="p-2">Recorded by</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((payment) => (
                <tr key={payment.id} className="border-b">
                  <td className="p-2 font-mono">
                    <Link to={`/admin/bookings/${payment.booking_id}`} className="text-primary underline-offset-4 hover:underline">
                      {payment.booking_code}
                    </Link>
                  </td>
                  <td className="p-2">
                    <div>{payment.customer_name ?? '—'}</div>
                    <div className="text-sm text-muted-foreground">{payment.customer_phone ?? '—'}</div>
                  </td>
                  <td className="p-2">{formatCurrency(payment.amount)}</td>
                  <td className="p-2 capitalize">{payment.method}</td>
                  <td className="p-2">{payment.reference_no ?? '—'}</td>
                  <td className="p-2">{payment.paid_at ? new Date(payment.paid_at).toLocaleString() : '—'}</td>
                  <td className="p-2">{payment.recorded_by_name ?? '—'}</td>
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
