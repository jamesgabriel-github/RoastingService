import { zodResolver } from '@hookform/resolvers/zod'
import { isAxiosError } from 'axios'
import { useState } from 'react'
import { useFieldArray, useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { formatCurrency } from '@/lib/currency'
import { getGenericErrorMessage } from '@/lib/errors'
import { computeEstimatedTotal } from './estimate'
import { useBookableServices, useCreateBooking } from './hooks'
import type { Booking } from './types'

const itemSchema = z.object({
  service_id: z.number().min(1, 'Select an item'),
  est_weight_kg: z.number().min(0.01, 'Weight must be greater than 0').max(1000, 'Weight seems too high'),
})

const bookingSchema = z
  .object({
    items: z.array(itemSchema).min(1, 'Add at least one item'),
    fulfillment: z.enum(['pickup', 'delivery']),
    delivery_address: z.string().optional(),
    preferred_dropoff_at: z.string().min(1, 'Choose a drop-off date and time'),
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

type BookingFormValues = z.infer<typeof bookingSchema>

const emptyValues: BookingFormValues = {
  items: [{ service_id: 0, est_weight_kg: 0 }],
  fulfillment: 'pickup',
  delivery_address: '',
  preferred_dropoff_at: '',
  notes: '',
}

const selectClassName =
  'h-8 w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 md:text-sm'

function BookingConfirmation({ booking, onBookAnother }: { booking: Booking; onBookAnother: () => void }) {
  return (
    <div className="flex max-w-sm flex-col gap-3">
      <h1 className="text-xl font-semibold">Booking submitted</h1>
      <p>
        Your booking code is <span className="font-mono font-semibold">{booking.code}</span>.
      </p>
      <p>Status: Pending review</p>
      <p>Estimated total: {formatCurrency(booking.estimated_total)}</p>
      <Button onClick={onBookAnother} className="w-fit">
        Book another
      </Button>
    </div>
  )
}

export function NewBookingPage() {
  const { data: services, isLoading: servicesLoading } = useBookableServices()
  const createBooking = useCreateBooking()
  const [formError, setFormError] = useState<string | null>(null)
  const [confirmedBooking, setConfirmedBooking] = useState<Booking | null>(null)

  const {
    register,
    control,
    handleSubmit,
    watch,
    reset,
    formState: { errors },
  } = useForm<BookingFormValues>({
    resolver: zodResolver(bookingSchema),
    defaultValues: emptyValues,
  })

  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const items = watch('items')
  const fulfillment = watch('fulfillment')

  if (confirmedBooking) {
    return (
      <BookingConfirmation
        booking={confirmedBooking}
        onBookAnother={() => {
          setConfirmedBooking(null)
          reset(emptyValues)
        }}
      />
    )
  }

  const estimatedTotal = computeEstimatedTotal(
    items.map((item) => ({
      rate: Number(services?.find((service) => service.id === item.service_id)?.roasting_rate_per_kg ?? 0),
      estWeightKg: item.est_weight_kg || 0,
    }))
  )

  const onSubmit = handleSubmit((values) => {
    setFormError(null)

    createBooking.mutate(
      {
        items: values.items.map((item) => ({
          service_id: item.service_id,
          est_weight_kg: item.est_weight_kg,
        })),
        fulfillment: values.fulfillment,
        delivery_address: values.fulfillment === 'delivery' ? (values.delivery_address?.trim() ?? null) : null,
        preferred_dropoff_at: new Date(values.preferred_dropoff_at).toISOString(),
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
        <h1 className="mb-4 text-xl font-semibold">Bring your own booking</h1>
        <p className="text-sm text-muted-foreground">
          Bring your own raw food for roasting. Enter your estimated weight per item - the final
          price is set when the shop weighs it in at drop-off.
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
                    {service.name} ({service.roasting_rate_per_kg}/kg)
                  </option>
                ))}
              </select>
              {errors.items?.[index]?.service_id && (
                <p className="text-sm text-destructive">{errors.items[index]?.service_id?.message}</p>
              )}
            </div>

            <div className="flex flex-col gap-1">
              <Label htmlFor={`items.${index}.est_weight_kg`}>Estimated weight (kg)</Label>
              <Input
                id={`items.${index}.est_weight_kg`}
                type="number"
                step="0.01"
                {...register(`items.${index}.est_weight_kg`, { valueAsNumber: true })}
              />
              {errors.items?.[index]?.est_weight_kg && (
                <p className="text-sm text-destructive">{errors.items[index]?.est_weight_kg?.message}</p>
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

        <Button
          type="button"
          variant="outline"
          className="w-fit"
          onClick={() => append({ service_id: 0, est_weight_kg: 0 })}
        >
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
          <Label htmlFor="preferred_dropoff_at">Preferred drop-off</Label>
          <Input id="preferred_dropoff_at" type="datetime-local" {...register('preferred_dropoff_at')} />
          {errors.preferred_dropoff_at && (
            <p className="text-sm text-destructive">{errors.preferred_dropoff_at.message}</p>
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
          Estimated total: <span className="font-semibold">{formatCurrency(estimatedTotal)}</span>
        </p>
        <Button type="submit" disabled={createBooking.isPending} className="w-fit">
          {createBooking.isPending ? 'Submitting…' : 'Submit booking'}
        </Button>
      </div>
    </form>
  )
}
