import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { Service } from '@/features/services/types'
import { getGenericErrorMessage } from '@/lib/errors'
import { useAdjustService, useInventory, useInventoryLogs, useRestockService } from './hooks'

function InventoryRow({ service }: { service: Service }) {
  const [qty, setQty] = useState('')
  const [remarks, setRemarks] = useState('')
  const [rowError, setRowError] = useState<string | null>(null)
  const restock = useRestockService()
  const adjust = useAdjustService()
  const isSaving = restock.isPending || adjust.isPending

  const parsedQty = Number(qty)
  const canSubmit = qty.trim() !== '' && Number.isInteger(parsedQty) && parsedQty !== 0

  const reset = () => {
    setQty('')
    setRemarks('')
  }

  const onRestock = () => {
    if (!canSubmit || parsedQty <= 0) {
      setRowError('Enter a positive quantity to restock.')
      return
    }
    setRowError(null)
    restock.mutate(
      { id: service.id, payload: { qty: parsedQty, remarks: remarks.trim() || null } },
      { onSuccess: reset, onError: (error) => setRowError(getGenericErrorMessage(error)) }
    )
  }

  const onAdjust = () => {
    if (!canSubmit) {
      setRowError('Enter a non-zero quantity to adjust (use a negative number to reduce stock).')
      return
    }
    setRowError(null)
    adjust.mutate(
      { id: service.id, payload: { change_qty: parsedQty, remarks: remarks.trim() || null } },
      { onSuccess: reset, onError: (error) => setRowError(getGenericErrorMessage(error)) }
    )
  }

  return (
    <tr className="border-b align-top">
      <td className="p-2">{service.name}</td>
      <td className="p-2">{service.stock_qty}</td>
      <td className="p-2">
        {service.is_low_stock ? (
          <span className="rounded bg-destructive/10 px-2 py-1 text-sm text-destructive">Low stock</span>
        ) : (
          '—'
        )}
      </td>
      <td className="p-2">{service.low_stock_threshold}</td>
      <td className="p-2">
        <div className="flex flex-col gap-2">
          <div className="flex gap-2">
            <Input
              className="w-24"
              type="number"
              placeholder="Qty"
              value={qty}
              onChange={(event) => setQty(event.target.value)}
            />
            <Input
              className="w-40"
              placeholder="Remarks (optional)"
              value={remarks}
              onChange={(event) => setRemarks(event.target.value)}
            />
          </div>
          <div className="flex gap-2">
            <Button size="sm" disabled={isSaving} onClick={onRestock}>
              Restock
            </Button>
            <Button size="sm" variant="outline" disabled={isSaving} onClick={onAdjust}>
              Adjust
            </Button>
          </div>
          {rowError && <p className="text-sm text-destructive">{rowError}</p>}
        </div>
      </td>
    </tr>
  )
}

function InventoryLogTable() {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useInventoryLogs(page)

  return (
    <div>
      <h2 className="mb-4 font-semibold">Inventory log</h2>
      {isLoading && <p>Loading…</p>}
      {data && (
        <>
          <table className="w-full border-collapse text-left">
            <thead>
              <tr className="border-b">
                <th className="p-2">Service</th>
                <th className="p-2">Change</th>
                <th className="p-2">Reason</th>
                <th className="p-2">Remarks</th>
                <th className="p-2">By</th>
                <th className="p-2">When</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((log) => (
                <tr key={log.id} className="border-b">
                  <td className="p-2">{log.service_name}</td>
                  <td className="p-2">{log.change_qty > 0 ? `+${log.change_qty}` : log.change_qty}</td>
                  <td className="p-2">{log.reason}</td>
                  <td className="p-2">{log.remarks ?? '—'}</td>
                  <td className="p-2">{log.created_by_name}</td>
                  <td className="p-2">{new Date(log.created_at).toLocaleString()}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="mt-4 flex items-center gap-2">
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

export function InventoryPage() {
  const { data: services, isLoading } = useInventory()

  return (
    <div className="flex flex-col gap-8">
      <div>
        <h1 className="mb-4 text-xl font-semibold">Inventory</h1>
        {isLoading && <p>Loading…</p>}
        {services && (
          <table className="w-full border-collapse text-left">
            <thead>
              <tr className="border-b">
                <th className="p-2">Service</th>
                <th className="p-2">Stock</th>
                <th className="p-2">Status</th>
                <th className="p-2">Threshold</th>
                <th className="p-2">Restock / adjust</th>
              </tr>
            </thead>
            <tbody>
              {services.map((service) => (
                <InventoryRow key={service.id} service={service} />
              ))}
            </tbody>
          </table>
        )}
      </div>

      <InventoryLogTable />
    </div>
  )
}
