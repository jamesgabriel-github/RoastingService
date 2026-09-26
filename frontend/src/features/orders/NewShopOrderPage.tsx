import { zodResolver } from '@hookform/resolvers/zod'
import { isAxiosError } from 'axios'
import { useState } from 'react'
import { useFieldArray, useForm } from 'react-hook-form'
import { z } from 'zod'
import type { Booking } from '@/features/bookings/types'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { formatCurrency } from '@/lib/currency'
import { getGenericErrorMessage } from '@/lib/errors'
import { useCreateOrder, useShoppableServices } from './hooks'
import { computeOrderTotal } from './orderTotal'

const itemSchema = z.object({
  service_id: z.number().min(1, 'Select an item'),
  qty: z.number().int('Quantity must be a whole number').min(1, 'Quantity must be at least 1').max(1000, 'Quantity seems too high'),
})

const orderSchema = z
  .object({
    items: z.array(itemSchema).min(1, 'Add at least one item'),
    fulfillment: z.enum(['pickup', 'delivery']),
    delivery_address: z.string().optional(),
    preferred_pickup_at: z.string().min(1, 'Choose a pickup or delivery date and time'),
    notes: z.string().optional(),
  })
  .superRefine((values, ctx) => {
    if (values.fulfillment === 'delivery' && !values.delivery_address?.trim()) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['delivery_address'],
        message: 'Delivery address is required for delivery',
      })
    }
  })

type OrderFormValues = z.infer<typeof orderSchema>

const emptyValues: OrderFormValues = {
  items: [{ service_id: 0, qty: 1 }],
  fulfillment: 'pickup',
  delivery_address: '',
  preferred_pickup_at: '',
  notes: '',
}

const selectClassName =
  'h-8 w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 md:text-sm'

function OrderConfirmation({ booking, onOrderAnother }: { booking: Booking; onOrderAnother: () => void }) {
  return (
    <div className="flex max-w-sm flex-col gap-3">
      <h1 className="text-xl font-semibold">Order placed</h1>
      <p>
        Your order code is <span className="font-mono font-semibold">{booking.code}</span>.
      </p>
      <p>Status: Pending confirmation</p>
      <p>Total: {formatCurrency(booking.total_amount ?? 0)}</p>
      <Button onClick={onOrderAnother} className="w-fit">
        Order again
      </Button>
    </div>
  )
}

export function NewShopOrderPage() {
  const { data: services, isLoading: servicesLoading } = useShoppableServices()
  const createOrder = useCreateOrder()
  const [formError, setFormError] = useState<string | null>(null)
  const [confirmedBooking, setConfirmedBooking] = useState<Booking | null>(null)

  const {
    register,
    control,
    handleSubmit,
    watch,
    reset,
    formState: { errors },
  } = useForm<OrderFormValues>({
    resolver: zodResolver(orderSchema),
    defaultValues: emptyValues,
  })

  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const items = watch('items')
  const fulfillment = watch('fulfillment')

  if (confirmedBooking) {
    return (
      <OrderConfirmation
        booking={confirmedBooking}
        onOrderAnother={() => {
          setConfirmedBooking(null)
          reset(emptyValues)
        }}
      />
    )
  }

  const total = computeOrderTotal(
    items.map((item) => ({
      rate: Number(services?.find((service) => service.id === item.service_id)?.shop_price ?? 0),
      qty: item.qty || 0,
    }))
  )

  const onSubmit = handleSubmit((values) => {
    setFormError(null)

    createOrder.mutate(
      {
        items: values.items.map((item) => ({ service_id: item.service_id, qty: item.qty })),
        fulfillment: values.fulfillment,
        delivery_address: values.fulfillment === 'delivery' ? (values.delivery_address?.trim() ?? null) : null,
        preferred_pickup_at: new Date(values.preferred_pickup_at).toISOString(),
        notes: values.notes?.trim() ? values.notes.trim() : null,
      },
      {
        onSuccess: (booking) => setConfirmedBooking(booking),
        onError: (error) => {
          if (isAxiosError(error) && error.response?.status === 422) {
            const fieldErrors = error.response.data?.errors as Record<string, string[]> | undefined
            const message = Object.values(fieldErrors ?? {})[0]?.[0]
            setFormError(message ?? getGenericErrorMessage(error))
            return
          }
          setFormError(getGenericErrorMessage(error))
        },
      }
    )
  })

  return (
    <form onSubmit={onSubmit} className="flex max-w-lg flex-col gap-8">
      <div>
        <h1 className="mb-4 text-xl font-semibold">Order from the shop</h1>
        <p className="text-sm text-muted-foreground">
          Order roasted items already in stock. The price shown is the final total for pickup or
          delivery.
        </p>
      </div>

      {formError && <p className="text-sm text-destructive">{formError}</p>}

      <div className="flex flex-col gap-4">
        <h2 className="font-semibold">Items</h2>
        {servicesLoading && <p>Loading services…</p>}

        {fields.map((field, index) => (
          <div key={field.id} className="flex flex-col gap-2 rounded-lg border p-3">
            <div className="flex flex-col gap-1">
              <Label htmlFor={`items.${index}.service_id`}>Item</Label>
              <select
                id={`items.${index}.service_id`}
                className={selectClassName}
                {...register(`items.${index}.service_id`, { valueAsNumber: true })}
              >
                <option value={0}>Select an item</option>
                {services?.map((service) => (
                  <option key={service.id} value={service.id}>
                    {service.name} ({formatCurrency(service.shop_price ?? 0)})
                  </option>
                ))}
              </select>
              {errors.items?.[index]?.service_id && (
                <p className="text-sm text-destructive">{errors.items[index]?.service_id?.message}</p>
              )}
            </div>

            <div className="flex flex-col gap-1">
              <Label htmlFor={`items.${index}.qty`}>Quantity</Label>
              <Input
                id={`items.${index}.qty`}
                type="number"
                {...register(`items.${index}.qty`, { valueAsNumber: true })}
              />
              {errors.items?.[index]?.qty && (
                <p className="text-sm text-destructive">{errors.items[index]?.qty?.message}</p>
              )}
            </div>

            {fields.length > 1 && (
              <Button type="button" variant="outline" size="sm" className="w-fit" onClick={() => remove(index)}>
                Remove
              </Button>
            )}
          </div>
        ))}

        {errors.items?.root && <p className="text-sm text-destructive">{errors.items.root.message}</p>}

        <Button type="button" variant="outline" className="w-fit" onClick={() => append({ service_id: 0, qty: 1 })}>
          Add another item
        </Button>
      </div>

      <div className="flex flex-col gap-4">
        <h2 className="font-semibold">Pickup or delivery</h2>

        <div className="flex gap-4">
          <label className="flex items-center gap-2 text-sm">
            <input type="radio" value="pickup" {...register('fulfillment')} />
            Pickup
          </label>
          <label className="flex items-center gap-2 text-sm">
            <input type="radio" value="delivery" {...register('fulfillment')} />
            Delivery
          </label>
        </div>

        {fulfillment === 'delivery' && (
          <div className="flex flex-col gap-1">
            <Label htmlFor="delivery_address">Delivery address</Label>
            <Input id="delivery_address" {...register('delivery_address')} />
            {errors.delivery_address && (
              <p className="text-sm text-destructive">{errors.delivery_address.message}</p>
            )}
          </div>
        )}

        <div className="flex flex-col gap-1">
          <Label htmlFor="preferred_pickup_at">Preferred pickup/delivery</Label>
          <Input id="preferred_pickup_at" type="datetime-local" {...register('preferred_pickup_at')} />
          {errors.preferred_pickup_at && (
            <p className="text-sm text-destructive">{errors.preferred_pickup_at.message}</p>
          )}
        </div>

        <div className="flex flex-col gap-1">
          <Label htmlFor="notes">Notes (optional)</Label>
          <Input id="notes" {...register('notes')} />
        </div>
      </div>

      <div className="flex flex-col gap-2 rounded-lg border p-3">
        <h2 className="font-semibold">Review</h2>
        <p>
          Total: <span className="font-semibold">{formatCurrency(total)}</span>
        </p>
        <Button type="submit" disabled={createOrder.isPending} className="w-fit">
          {createOrder.isPending ? 'Placing order…' : 'Place order'}
        </Button>
      </div>
    </form>
  )
}
