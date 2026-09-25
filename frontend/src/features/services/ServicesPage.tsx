import { zodResolver } from '@hookform/resolvers/zod'
import { isAxiosError } from 'axios'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
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

const emptyValues: ServiceFormValues = {
  name: '',
  description: '',
  est_minutes: 0,
  allow_customer_supplied: false,
  allow_shop_supplied: false,
  roasting_rate_per_kg: '',
  shop_price: '',
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
  }
}

function ServiceRow({ service, onEdit }: { service: Service; onEdit: (service: Service) => void }) {
  const [rowError, setRowError] = useState<string | null>(null)
  const toggle = useToggleService()

  return (
    <tr className="border-b">
      <td className="p-2">{service.name}</td>
      <td className="p-2">
        {[
          service.allow_customer_supplied ? 'Bring your own' : null,
          service.allow_shop_supplied ? 'Shop stock' : null,
        ]
          .filter(Boolean)
          .join(', ')}
      </td>
      <td className="p-2">{service.roasting_rate_per_kg ?? '—'}</td>
      <td className="p-2">{service.shop_price ?? '—'}</td>
      <td className="p-2">{service.est_minutes} min</td>
      <td className="p-2">{service.stock_qty}</td>
      <td className="p-2">{service.is_active ? 'Active' : 'Inactive'}</td>
      <td className="p-2">
        <div className="flex gap-2">
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
  const [editingId, setEditingId] = useState<number | null>(null)
  const [formError, setFormError] = useState<string | null>(null)

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

  const startEdit = (service: Service) => {
    setEditingId(service.id)
    setFormError(null)
    reset(toFormValues(service))
  }

  const cancelEdit = () => {
    setEditingId(null)
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

    if (editingId) {
      updateService.mutate(
        { id: editingId, payload },
        { onSuccess: () => cancelEdit(), onError }
      )
      return
    }

    createService.mutate(payload, { onSuccess: () => reset(emptyValues), onError })
  })

  return (
    <div className="flex flex-col gap-8">
      <div>
        <h1 className="mb-4 text-xl font-semibold">Services</h1>
        {isLoading && <p>Loading…</p>}
        {services && (
          <table className="w-full border-collapse text-left">
            <thead>
              <tr className="border-b">
                <th className="p-2">Name</th>
                <th className="p-2">Types</th>
                <th className="p-2">Rate/kg</th>
                <th className="p-2">Price</th>
                <th className="p-2">Cook time</th>
                <th className="p-2">Stock</th>
                <th className="p-2">Status</th>
                <th className="p-2">Actions</th>
              </tr>
            </thead>
            <tbody>
              {services.map((service) => (
                <ServiceRow key={service.id} service={service} onEdit={startEdit} />
              ))}
            </tbody>
          </table>
        )}
      </div>

      <form onSubmit={onSubmit} className="flex max-w-sm flex-col gap-4">
        <h2 className="font-semibold">{editingId ? 'Edit service' : 'Create service'}</h2>

        {formError && <p className="text-sm text-destructive">{formError}</p>}

        <div className="flex flex-col gap-1">
          <Label htmlFor="name">Name</Label>
          <Input id="name" {...register('name')} />
          {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
        </div>

        <div className="flex flex-col gap-1">
          <Label htmlFor="description">Description</Label>
          <Input id="description" {...register('description')} />
        </div>

        <div className="flex flex-col gap-1">
          <Label htmlFor="est_minutes">Cook time (minutes)</Label>
          <Input id="est_minutes" type="number" {...register('est_minutes', { valueAsNumber: true })} />
          {errors.est_minutes && <p className="text-sm text-destructive">{errors.est_minutes.message}</p>}
        </div>

        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" {...register('allow_customer_supplied')} />
          Bring your own (customer-supplied)
        </label>

        {allowCustomerSupplied && (
          <div className="flex flex-col gap-1">
            <Label htmlFor="roasting_rate_per_kg">Rate per kg</Label>
            <Input id="roasting_rate_per_kg" {...register('roasting_rate_per_kg')} />
            {errors.roasting_rate_per_kg && (
              <p className="text-sm text-destructive">{errors.roasting_rate_per_kg.message}</p>
            )}
          </div>
        )}

        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" {...register('allow_shop_supplied')} />
          Order from shop stock
        </label>

        {allowShopSupplied && (
          <div className="flex flex-col gap-1">
            <Label htmlFor="shop_price">Shop price</Label>
            <Input id="shop_price" {...register('shop_price')} />
            {errors.shop_price && <p className="text-sm text-destructive">{errors.shop_price.message}</p>}
          </div>
        )}

        {errors.allow_shop_supplied && !allowShopSupplied && (
          <p className="text-sm text-destructive">{errors.allow_shop_supplied.message}</p>
        )}

        <div className="flex gap-2">
          <Button type="submit" disabled={isSaving}>
            {isSaving ? 'Saving…' : editingId ? 'Save changes' : 'Create service'}
          </Button>
          {editingId && (
            <Button type="button" variant="outline" onClick={cancelEdit}>
              Cancel
            </Button>
          )}
        </div>
      </form>
    </div>
  )
}
