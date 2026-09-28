import { zodResolver } from '@hookform/resolvers/zod'
import { isAxiosError } from 'axios'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { cn } from 'cn'
import { z } from 'zod'
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
import { getGenericErrorMessage } from '@/lib/errors'
import type { ServicePayload } from './api'
import { useCreateService, useServices, useToggleService, useUpdateService } from './hooks'
import type { Service } from './types'

const serviceSchema = z
  .object({
    name: z.string().min(1, 'Name is required'),
    description: z.string().optional(),
    est_minutes: z.number().int('Cook time must be a whole number').min(1, 'Cook time must be at least 1 minute'),
    allow_customer_supplied: z.boolean(),
    allow_shop_supplied: z.boolean(),
    roasting_rate_per_kg: z.string().optional(),
    shop_price: z.string().optional(),
    low_stock_threshold: z.number().int('Must be a whole number').min(0, 'Must be at least 0'),
  })
  .superRefine((values, ctx) => {
    if (!values.allow_customer_supplied && !values.allow_shop_supplied) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['allow_shop_supplied'],
        message: 'At least one booking type must be enabled.',
      })
    }
    if (values.allow_customer_supplied && !values.roasting_rate_per_kg) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['roasting_rate_per_kg'],
        message: 'Rate is required when customer-supplied is enabled.',
      })
    }
    if (values.allow_shop_supplied && !values.shop_price) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['shop_price'],
        message: 'Price is required when shop-supplied is enabled.',
      })
    }
  })

type ServiceFormValues = z.infer<typeof serviceSchema>
type ModalMode = 'create' | 'view' | 'edit'

const emptyValues: ServiceFormValues = {
  name: '',
  description: '',
  est_minutes: 0,
  allow_customer_supplied: false,
  allow_shop_supplied: false,
  roasting_rate_per_kg: '',
  shop_price: '',
  low_stock_threshold: 5,
}

function toFormValues(service: Service): ServiceFormValues {
  return {
    name: service.name,
    description: service.description ?? '',
    est_minutes: service.est_minutes,
    allow_customer_supplied: service.allow_customer_supplied,
    allow_shop_supplied: service.allow_shop_supplied,
    roasting_rate_per_kg: service.roasting_rate_per_kg ?? '',
    shop_price: service.shop_price ?? '',
    low_stock_threshold: service.low_stock_threshold,
  }
}

function toPayload(values: ServiceFormValues): ServicePayload {
  return {
    name: values.name,
    description: values.description?.trim() ? values.description.trim() : null,
    est_minutes: values.est_minutes,
    allow_customer_supplied: values.allow_customer_supplied,
    allow_shop_supplied: values.allow_shop_supplied,
    roasting_rate_per_kg: values.allow_customer_supplied ? (values.roasting_rate_per_kg ?? null) : null,
    shop_price: values.allow_shop_supplied ? (values.shop_price ?? null) : null,
    low_stock_threshold: values.low_stock_threshold,
  }
}

function typesLabel(service: Service): string {
  return [
    service.allow_customer_supplied ? 'Bring your own' : null,
    service.allow_shop_supplied ? 'Shop stock' : null,
  ]
    .filter(Boolean)
    .join(', ')
}

function StatusPill({ isActive }: { isActive: boolean }) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
        isActive ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'
      )}
    >
      {isActive ? 'Active' : 'Inactive'}
    </span>
  )
}

function ServiceRow({
  service,
  onView,
  onEdit,
}: {
  service: Service
  onView: (service: Service) => void
  onEdit: (service: Service) => void
}) {
  const [rowError, setRowError] = useState<string | null>(null)
  const toggle = useToggleService()

  return (
    <tr className="border-b">
      <td className="p-2">{service.name}</td>
      <td className="p-2">{service.roasting_rate_per_kg ?? '—'}</td>
      <td className="p-2">{service.shop_price ?? '—'}</td>
      <td className="p-2">{service.est_minutes} min</td>
      <td className="p-2">
        {service.stock_qty}
        {service.is_low_stock && <span className="ml-1 text-xs text-destructive">Low</span>}
      </td>
      <td className="p-2">
        <StatusPill isActive={service.is_active} />
      </td>
      <td className="p-2">
        <div className="flex gap-2">
          <Button size="sm" variant="outline" onClick={() => onView(service)}>
            View
          </Button>
          <Button size="sm" variant="outline" onClick={() => onEdit(service)}>
            Edit
          </Button>
          <Button
            size="sm"
            variant={service.is_active ? 'destructive' : 'default'}
            disabled={toggle.isPending}
            onClick={() => {
              setRowError(null)
              toggle.mutate(service.id, { onError: (error) => setRowError(getGenericErrorMessage(error)) })
            }}
          >
            {service.is_active ? 'Deactivate' : 'Activate'}
          </Button>
        </div>
        {rowError && <p className="mt-1 text-sm text-destructive">{rowError}</p>}
      </td>
    </tr>
  )
}

export function ServicesPage() {
  const { data: services, isLoading } = useServices()
  const createService = useCreateService()
  const updateService = useUpdateService()
  const [isOpen, setIsOpen] = useState(false)
  const [mode, setMode] = useState<ModalMode>('create')
  const [activeService, setActiveService] = useState<Service | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [successMessage, setSuccessMessage] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    reset,
    watch,
    formState: { errors },
    setError,
  } = useForm<ServiceFormValues>({
    resolver: zodResolver(serviceSchema),
    defaultValues: emptyValues,
  })

  const allowCustomerSupplied = watch('allow_customer_supplied')
  const allowShopSupplied = watch('allow_shop_supplied')
  const isSaving = createService.isPending || updateService.isPending
  const isViewing = mode === 'view'

  const filteredServices = useMemo(() => {
    const query = search.trim().toLowerCase()
    if (!query) return services ?? []
    return (services ?? []).filter(
      (service) => service.name.toLowerCase().includes(query) || typesLabel(service).toLowerCase().includes(query)
    )
  }, [services, search])

  const showSuccess = (message: string) => {
    setSuccessMessage(message)
    setTimeout(() => setSuccessMessage(null), 3000)
  }

  const openCreate = () => {
    setActiveService(null)
    setMode('create')
    setFormError(null)
    reset(emptyValues)
    setIsOpen(true)
  }

  const openView = (service: Service) => {
    setActiveService(service)
    setMode('view')
    setFormError(null)
    reset(toFormValues(service))
    setIsOpen(true)
  }

  const openEdit = (service: Service) => {
    setActiveService(service)
    setMode('edit')
    setFormError(null)
    reset(toFormValues(service))
    setIsOpen(true)
  }

  const switchToEdit = () => setMode('edit')

  const closeModal = () => {
    setIsOpen(false)
    setActiveService(null)
    setMode('create')
    setFormError(null)
    reset(emptyValues)
  }

  const onSubmit = handleSubmit((values) => {
    setFormError(null)
    const payload = toPayload(values)

    const onError = (error: unknown) => {
      if (isAxiosError(error) && error.response?.status === 422) {
        const fieldErrors = error.response.data?.errors as Record<string, string[]> | undefined
        for (const [name, messages] of Object.entries(fieldErrors ?? {})) {
          setError(name as keyof ServiceFormValues, { message: messages[0] })
        }
        return
      }
      setFormError(getGenericErrorMessage(error))
    }

    if (activeService) {
      updateService.mutate(
        { id: activeService.id, payload },
        {
          onSuccess: () => {
            closeModal()
            showSuccess('Service updated.')
          },
          onError,
        }
      )
      return
    }

    createService.mutate(payload, {
      onSuccess: () => {
        closeModal()
        showSuccess('Service created.')
      },
      onError,
    })
  })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-semibold">Services</h1>
        <Button onClick={openCreate}>New Service</Button>
      </div>

      {successMessage && (
        <div className="rounded-md bg-primary/10 px-3 py-2 text-sm text-primary">{successMessage}</div>
      )}

      <Input
        placeholder="Search services…"
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        className="max-w-xs"
      />

      {isLoading && <p>Loading…</p>}
      {services && (
        <table className="w-full border-collapse text-left">
          <thead>
            <tr className="border-b">
              <th className="p-2">Name</th>
              <th className="p-2">Rate/kg</th>
              <th className="p-2">Price</th>
              <th className="p-2">Cook time</th>
              <th className="p-2">Stock</th>
              <th className="p-2">Status</th>
              <th className="p-2">Actions</th>
            </tr>
          </thead>
          <tbody>
            {filteredServices.map((service) => (
              <ServiceRow key={service.id} service={service} onView={openView} onEdit={openEdit} />
            ))}
          </tbody>
        </table>
      )}

      <Dialog open={isOpen} onOpenChange={(open) => !open && closeModal()}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {mode === 'create' ? 'Create Service' : mode === 'view' ? activeService?.name : 'Edit Service'}
            </DialogTitle>
            <DialogDescription>
              {mode === 'view'
                ? 'Service details'
                : mode === 'edit'
                  ? 'Update service details'
                  : 'Add a new service'}
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={onSubmit} className="flex flex-col gap-4">
            {formError && <p className="text-sm text-destructive">{formError}</p>}

            <div className="flex flex-col gap-1">
              <Label htmlFor="name">Name</Label>
              <Input id="name" disabled={isViewing} {...register('name')} />
              {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
            </div>

            <div className="flex flex-col gap-1">
              <Label htmlFor="description">Description</Label>
              <Input id="description" disabled={isViewing} {...register('description')} />
            </div>

            <div className="flex flex-col gap-1">
              <Label htmlFor="est_minutes">Cook time (minutes)</Label>
              <Input
                id="est_minutes"
                type="number"
                disabled={isViewing}
                {...register('est_minutes', { valueAsNumber: true })}
              />
              {errors.est_minutes && <p className="text-sm text-destructive">{errors.est_minutes.message}</p>}
            </div>

            <div className="flex flex-col gap-1">
              <Label htmlFor="low_stock_threshold">Low-stock threshold</Label>
              <Input
                id="low_stock_threshold"
                type="number"
                disabled={isViewing}
                {...register('low_stock_threshold', { valueAsNumber: true })}
              />
              {errors.low_stock_threshold && (
                <p className="text-sm text-destructive">{errors.low_stock_threshold.message}</p>
              )}
            </div>

            <Label>
              <input type="checkbox" disabled={isViewing} {...register('allow_customer_supplied')} />
              Bring your own (customer-supplied)
            </Label>

            {allowCustomerSupplied && (
              <div className="flex flex-col gap-1">
                <Label htmlFor="roasting_rate_per_kg">Rate per kg</Label>
                <Input id="roasting_rate_per_kg" disabled={isViewing} {...register('roasting_rate_per_kg')} />
                {errors.roasting_rate_per_kg && (
                  <p className="text-sm text-destructive">{errors.roasting_rate_per_kg.message}</p>
                )}
              </div>
            )}

            <Label>
              <input type="checkbox" disabled={isViewing} {...register('allow_shop_supplied')} />
              Order from shop stock
            </Label>

            {allowShopSupplied && (
              <div className="flex flex-col gap-1">
                <Label htmlFor="shop_price">Shop price</Label>
                <Input id="shop_price" disabled={isViewing} {...register('shop_price')} />
                {errors.shop_price && <p className="text-sm text-destructive">{errors.shop_price.message}</p>}
              </div>
            )}

            {errors.allow_shop_supplied && !allowShopSupplied && (
              <p className="text-sm text-destructive">{errors.allow_shop_supplied.message}</p>
            )}

            {mode !== 'create' && activeService && (
              <div className="grid grid-cols-2 gap-2 rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                <span>Stock: {activeService.stock_qty}</span>
                <span>{activeService.is_low_stock ? 'Low stock' : 'Stock OK'}</span>
                <span>Status: {activeService.is_active ? 'Active' : 'Inactive'}</span>
              </div>
            )}

            <DialogFooter>
              {mode === 'view' ? (
                <>
                  <DialogClose render={<Button type="button" variant="outline" />}>Close</DialogClose>
                  <Button type="button" onClick={switchToEdit}>
                    Switch to Edit
                  </Button>
                </>
              ) : (
                <>
                  <DialogClose render={<Button type="button" variant="outline" />}>Cancel</DialogClose>
                  <Button type="submit" disabled={isSaving}>
                    {isSaving ? 'Saving…' : mode === 'edit' ? 'Save changes' : 'Create service'}
                  </Button>
                </>
              )}
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  )
}
