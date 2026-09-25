import { formatWaitingTime } from '@/features/admin-bookings/waitingTime'
import { formatCurrency } from '@/lib/currency'
import { useDashboard } from './hooks'
import { STATUS_LABELS } from './types'
import type { SalesPeriod } from './types'

function SalesCard({ title, period }: { title: string; period: SalesPeriod }) {
  return (
    <div className="flex flex-col gap-2 rounded-lg border p-4">
      <h3 className="font-semibold">{title}</h3>
      <div className="flex justify-between text-sm">
        <span className="text-muted-foreground">Bring your own</span>
        <span>{formatCurrency(period.customer_supplied)}</span>
      </div>
      <div className="flex justify-between text-sm">
        <span className="text-muted-foreground">Shop</span>
        <span>{formatCurrency(period.shop_supplied)}</span>
      </div>
      <div className="flex justify-between border-t pt-2 font-semibold">
        <span>Total</span>
        <span>{formatCurrency(period.total)}</span>
      </div>
    </div>
  )
}

export function DashboardPage() {
  const { data, isLoading } = useDashboard()

  if (isLoading) {
    return <p>Loading…</p>
  }

  if (!data) {
    return null
  }

  return (
    <div className="flex flex-col gap-8">
      <div>
        <h1 className="mb-4 text-xl font-semibold">Dashboard</h1>
        <div className="grid gap-4 sm:grid-cols-3">
          <SalesCard title="Today" period={data.sales.today} />
          <SalesCard title="This week" period={data.sales.week} />
          <SalesCard title="This month" period={data.sales.month} />
        </div>
      </div>

      <div>
        <h2 className="mb-4 font-semibold">Bookings by status</h2>
        <table className="w-full border-collapse text-left">
          <thead>
            <tr className="border-b">
              <th className="p-2">Status</th>
              <th className="p-2">Count</th>
            </tr>
          </thead>
          <tbody>
            {(Object.keys(STATUS_LABELS) as (keyof typeof STATUS_LABELS)[]).map((status) => (
              <tr key={status} className="border-b">
                <td className="p-2">{STATUS_LABELS[status]}</td>
                <td className="p-2">{data.bookings_by_status[status]}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div>
        <h2 className="mb-4 font-semibold">Cooking / ready queue</h2>
        {data.active_queue.length === 0 ? (
          <p className="text-muted-foreground">None right now.</p>
        ) : (
          <table className="w-full border-collapse text-left">
            <thead>
              <tr className="border-b">
                <th className="p-2">Code</th>
                <th className="p-2">Customer</th>
                <th className="p-2">Fulfillment</th>
                <th className="p-2">Waiting</th>
              </tr>
            </thead>
            <tbody>
              {data.active_queue.map((entry) => (
                <tr key={entry.id} className="border-b">
                  <td className="p-2 font-mono">{entry.code}</td>
                  <td className="p-2">{entry.customer_name ?? '—'}</td>
                  <td className="p-2 capitalize">{entry.fulfillment}</td>
                  <td className="p-2">{formatWaitingTime(entry.waiting_minutes)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div>
        <h2 className="mb-4 font-semibold">Top-selling items</h2>
        {data.top_items.length === 0 ? (
          <p className="text-muted-foreground">None right now.</p>
        ) : (
          <table className="w-full border-collapse text-left">
            <thead>
              <tr className="border-b">
                <th className="p-2">Item</th>
                <th className="p-2">Qty sold</th>
              </tr>
            </thead>
            <tbody>
              {data.top_items.map((item) => (
                <tr key={item.service_id} className="border-b">
                  <td className="p-2">{item.name}</td>
                  <td className="p-2">{item.qty_sold}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div>
        <h2 className="mb-4 font-semibold">Low stock</h2>
        {data.low_stock.length === 0 ? (
          <p className="text-muted-foreground">None right now.</p>
        ) : (
          <table className="w-full border-collapse text-left">
            <thead>
              <tr className="border-b">
                <th className="p-2">Service</th>
                <th className="p-2">Stock</th>
                <th className="p-2">Threshold</th>
                <th className="p-2"></th>
              </tr>
            </thead>
            <tbody>
              {data.low_stock.map((service) => (
                <tr key={service.id} className="border-b">
                  <td className="p-2">{service.name}</td>
                  <td className="p-2">{service.stock_qty}</td>
                  <td className="p-2">{service.low_stock_threshold}</td>
                  <td className="p-2">
                    <span className="rounded bg-destructive/10 px-2 py-1 text-sm text-destructive">Low stock</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
