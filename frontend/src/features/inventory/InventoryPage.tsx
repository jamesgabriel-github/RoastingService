import { useMemo, useState, type FormEvent } from 'react'
import { cn } from 'cn'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import type { Service } from '@/features/services/types'
import { getGenericErrorMessage } from '@/lib/errors'
import { useAdjustService, useInventory, useInventoryLogs, useRestockService } from './hooks'

type ModalMode = 'view' | 'restock' | 'adjust'

function StatusPill({ isLowStock }: { isLowStock: boolean }) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
        isLowStock ? 'bg-destructive/10 text-destructive' : 'bg-primary/10 text-primary'
      )}
    >
      {isLowStock ? 'Low stock' : 'In stock'}
    </span>
  )
}

function InventoryLogTable() {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useInventoryLogs(page)

  return (
    <div>
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
  const restock = useRestockService()
  const adjust = useAdjustService()

  const [isOpen, setIsOpen] = useState(false)
  const [isLogOpen, setIsLogOpen] = useState(false)
  const [mode, setMode] = useState<ModalMode>('view')
  const [activeService, setActiveService] = useState<Service | null>(null)
  const [qty, setQty] = useState('')
  const [remarks, setRemarks] = useState('')
  const [formError, setFormError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [successMessage, setSuccessMessage] = useState<string | null>(null)

  const isSaving = restock.isPending || adjust.isPending

  const filteredServices = useMemo(() => {
    const query = search.trim().toLowerCase()
    if (!query) return services ?? []
    return (services ?? []).filter((service) => service.name.toLowerCase().includes(query))
  }, [services, search])

  const showSuccess = (message: string) => {
    setSuccessMessage(message)
    setTimeout(() => setSuccessMessage(null), 3000)
  }

  const resetForm = () => {
    setQty('')
    setRemarks('')
    setFormError(null)
  }

  const openView = (service: Service) => {
    setActiveService(service)
    setMode('view')
    resetForm()
    setIsOpen(true)
  }

  const openRestock = (service: Service) => {
    setActiveService(service)
    setMode('restock')
    resetForm()
    setIsOpen(true)
  }

  const openAdjust = (service: Service) => {
    setActiveService(service)
    setMode('adjust')
    resetForm()
    setIsOpen(true)
  }

  const switchToRestock = () => {
    resetForm()
    setMode('restock')
  }

  const switchToAdjust = () => {
    resetForm()
    setMode('adjust')
  }

  const closeModal = () => {
    setIsOpen(false)
    setActiveService(null)
    setMode('view')
    resetForm()
  }

  const parsedQty = Number(qty)
  const isValidQty = qty.trim() !== '' && Number.isInteger(parsedQty) && parsedQty !== 0

  const onSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!activeService) return

    if (mode === 'restock') {
      if (!isValidQty || parsedQty <= 0) {
        setFormError('Enter a positive quantity to restock.')
        return
      }
      setFormError(null)
      restock.mutate(
        { id: activeService.id, payload: { qty: parsedQty, remarks: remarks.trim() || null } },
        {
          onSuccess: () => {
            closeModal()
            showSuccess('Service restocked.')
          },
          onError: (error) => setFormError(getGenericErrorMessage(error)),
        }
      )
      return
    }

    if (mode === 'adjust') {
      if (!isValidQty) {
        setFormError('Enter a non-zero quantity to adjust (use a negative number to reduce stock).')
        return
      }
      setFormError(null)
      adjust.mutate(
        { id: activeService.id, payload: { change_qty: parsedQty, remarks: remarks.trim() || null } },
        {
          onSuccess: () => {
            closeModal()
            showSuccess('Stock adjusted.')
          },
          onError: (error) => setFormError(getGenericErrorMessage(error)),
        }
      )
    }
  }

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-col gap-4">
        <h1 className="text-xl font-semibold">Inventory</h1>

        {successMessage && (
          <div className="rounded-md bg-primary/10 px-3 py-2 text-sm text-primary">{successMessage}</div>
        )}

        <div className="flex items-center justify-between gap-2">
          <Input
            placeholder="Search inventory…"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            className="max-w-xs"
          />
          <Button type="button" variant="outline" onClick={() => setIsLogOpen(true)}>
            Inventory Log
          </Button>
        </div>

        {isLoading && <p>Loading…</p>}
        {services && (
          <table className="w-full border-collapse text-left">
            <thead>
              <tr className="border-b">
                <th className="p-2">Service</th>
                <th className="p-2">Stock</th>
                <th className="p-2">Status</th>
                <th className="p-2">Threshold</th>
                <th className="p-2">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredServices.map((service) => (
                <tr key={service.id} className="border-b">
                  <td className="p-2">{service.name}</td>
                  <td className="p-2">{service.stock_qty}</td>
                  <td className="p-2">
                    <StatusPill isLowStock={service.is_low_stock} />
                  </td>
                  <td className="p-2">{service.low_stock_threshold}</td>
                  <td className="p-2">
                    <div className="flex gap-2">
                      <Button size="sm" variant="outline" onClick={() => openView(service)}>
                        View
                      </Button>
                      <Button size="sm" variant="outline" onClick={() => openRestock(service)}>
                        Restock
                      </Button>
                      <Button size="sm" variant="outline" onClick={() => openAdjust(service)}>
                        Adjust
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <Dialog open={isOpen} onOpenChange={(open) => !open && closeModal()}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {mode === 'view' ? activeService?.name : mode === 'restock' ? 'Restock service' : 'Adjust stock'}
            </DialogTitle>
            <DialogDescription>
              {mode === 'view'
                ? 'Inventory details'
                : mode === 'restock'
                  ? 'Add stock for this service'
                  : 'Correct the stock count for this service'}
            </DialogDescription>
          </DialogHeader>

          {mode === 'view' && activeService && (
            <div className="flex flex-col gap-4">
              <div className="grid grid-cols-2 gap-2 rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                <span>Stock: {activeService.stock_qty}</span>
                <span>
                  <StatusPill isLowStock={activeService.is_low_stock} />
                </span>
                <span>Threshold: {activeService.low_stock_threshold}</span>
              </div>
              <DialogFooter>
                <DialogClose render={<Button type="button" variant="outline" />}>Close</DialogClose>
                <Button type="button" variant="outline" onClick={switchToRestock}>
                  Switch to Restock
                </Button>
                <Button type="button" onClick={switchToAdjust}>
                  Switch to Adjust
                </Button>
              </DialogFooter>
            </div>
          )}

          {(mode === 'restock' || mode === 'adjust') && (
            <form onSubmit={onSubmit} className="flex flex-col gap-4">
              {formError && <p className="text-sm text-destructive">{formError}</p>}

              <div className="flex flex-col gap-1">
                <Label htmlFor="qty">{mode === 'restock' ? 'Quantity to add' : 'Change in quantity'}</Label>
                <Input
                  id="qty"
                  type="number"
                  value={qty}
                  onChange={(event) => setQty(event.target.value)}
                  placeholder={mode === 'restock' ? 'e.g. 10' : 'e.g. -3'}
                />
              </div>

              <div className="flex flex-col gap-1">
                <Label htmlFor="remarks">Remarks (optional)</Label>
                <Input id="remarks" value={remarks} onChange={(event) => setRemarks(event.target.value)} />
              </div>

              <DialogFooter>
                <DialogClose render={<Button type="button" variant="outline" />}>Cancel</DialogClose>
                <Button type="submit" disabled={isSaving}>
                  {isSaving ? 'Saving…' : mode === 'restock' ? 'Confirm restock' : 'Save adjustment'}
                </Button>
              </DialogFooter>
            </form>
          )}
        </DialogContent>
      </Dialog>

      <Dialog open={isLogOpen} onOpenChange={setIsLogOpen}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Inventory log</DialogTitle>
          </DialogHeader>
          {isLogOpen && (
            <div className="overflow-x-auto">
              <InventoryLogTable />
            </div>
          )}
          <DialogFooter>
            <DialogClose render={<Button type="button" variant="outline" />}>Close</DialogClose>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
