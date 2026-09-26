import { zodResolver } from '@hookform/resolvers/zod'
import { isAxiosError } from 'axios'
import { useState } from 'react'
import { useFieldArray, useForm } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { useBookableServices } from '@/features/bookings/hooks'
import { useShoppableServices } from '@/features/orders/hooks'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { getGenericErrorMessage } from '@/lib/errors'
import { useCreateWalkInRoastingBooking, useCreateWalkInShopOrder, useSearchWalkInCustomers } from './hooks'
import type { WalkInCustomer } from './types'

const itemSchema = z.object({
  service_id: z.number().min(1, 'Select an item'),
  final_weight_kg: z.number().optional(),
  qty: z.number().optional(),
})

const walkInSchema = z
  .object({
    bookingType: z.enum(['roasting', 'shop']),
    customerMode: z.enum(['registered', 'guest']),
    customer_id: z.number().nullable(),
    guest_name: z.string().optional(),
    guest_phone: z.string().optional(),
    items: z.array(itemSchema).min(1, 'Add at least one item'),
    fulfillment: z.enum(['pickup', 'delivery']),
    delivery_address: z.string().optional(),
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

    if (values.customerMode === 'registered' && !values.customer_id) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['customer_id'], message: 'Select a registered customer' })
    }

    if (values.customerMode === 'guest') {
      if (!values.guest_name?.trim()) {
        ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['guest_name'], message: 'Guest name is required' })
      }
      if (!values.guest_phone?.trim()) {
        ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['guest_phone'], message: 'Guest phone is required' })
      }
    }

    values.items.forEach((item, index) => {
      if (values.bookingType === 'roasting') {
        if (!item.final_weight_kg || item.final_weight_kg <= 0 || item.final_weight_kg > 1000) {
          ctx.addIssue({
            code: z.ZodIssueCode.custom,
            path: ['items', index, 'final_weight_kg'],
            message: 'Weight must be greater than 0',
          })
        }
      } else {
        if (!item.qty || item.qty < 1 || item.qty > 1000) {
          ctx.addIssue({
            code: z.ZodIssueCode.custom,
            path: ['items', index, 'qty'],
            message: 'Quantity must be at least 1',
          })
        }
      }
    })
  })

type WalkInFormValues = z.infer<typeof walkInSchema>

const emptyValues: WalkInFormValues = {
  bookingType: 'roasting',
  customerMode: 'guest',
  customer_id: null,
  guest_name: '',
  guest_phone: '',
  items: [{ service_id: 0 }],
  fulfillment: 'pickup',
  delivery_address: '',
  notes: '',
}

const selectClassName =
  'h-8 w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 md:text-sm'

export function WalkInBookingPage() {
  const navigate = useNavigate()
  const { data: roastingServices } = useBookableServices()
  const { data: shopServices } = useShoppableServices()
  const createRoasting = useCreateWalkInRoastingBooking()
  const createShop = useCreateWalkInShopOrder()
  const [formError, setFormError] = useState<string | null>(null)
  const [customerSearch, setCustomerSearch] = useState('')
  const [selectedCustomer, setSelectedCustomer] = useState<WalkInCustomer | null>(null)
  const { data: customerResults, isFetching: customersLoading } = useSearchWalkInCustomers(customerSearch)

  const {
    register,
    control,
    handleSubmit,
    watch,
    setValue,
    formState: { errors },
  } = useForm<WalkInFormValues>({
    resolver: zodResolver(walkInSchema),
    defaultValues: emptyValues,
  })

  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const bookingType = watch('bookingType')
  const customerMode = watch('customerMode')
  const fulfillment = watch('fulfillment')

  const services = bookingType === 'roasting' ? roastingServices : shopServices
  const isSubmitting = createRoasting.isPending || createShop.isPending

  const selectBookingType = (next: WalkInFormValues['bookingType']) => {
    setValue('bookingType', next)
    setValue('items', [{ service_id: 0 }])
  }

  const selectCustomerMode = (next: WalkInFormValues['customerMode']) => {
    setValue('customerMode', next)
    if (next === 'guest') {
      setValue('customer_id', null)
      setSelectedCustomer(null)
    } else {
      setValue('guest_name', '')
      setValue('guest_phone', '')
    }
  }

  const pickCustomer = (customer: WalkInCustomer) => {
    setSelectedCustomer(customer)
    setValue('customer_id', customer.id)
    setCustomerSearch('')
  }

  const clearCustomer = () => {
    setSelectedCustomer(null)
    setValue('customer_id', null)
  }

  const onSubmit = handleSubmit((values) => {
    setFormError(null)

    const basePayload = {
      customer_id: values.customerMode === 'registered' ? values.customer_id : null,
      guest_name: values.customerMode === 'guest' ? (values.guest_name?.trim() ?? null) : null,
      guest_phone: values.customerMode === 'guest' ? (values.guest_phone?.trim() ?? null) : null,
      fulfillment: values.fulfillment,
      delivery_address: values.fulfillment === 'delivery' ? (values.delivery_address?.trim() ?? null) : null,
      notes: values.notes?.trim() ? values.notes.trim() : null,
    }

    const onError = (error: unknown) => {
      if (isAxiosError(error) && error.response?.status === 422) {
        const fieldErrors = error.response.data?.errors as Record<string, string[]> | undefined
        const message = Object.values(fieldErrors ?? {})[0]?.[0]
        setFormError(message ?? getGenericErrorMessage(error))
        return
      }
      setFormError(getGenericErrorMessage(error))
    }

    if (values.bookingType === 'roasting') {
      createRoasting.mutate(
        {
          ...basePayload,
          items: values.items.map((item) => ({
            service_id: item.service_id,
            final_weight_kg: item.final_weight_kg ?? 0,
          })),
        },
        {
          onSuccess: (booking) => navigate(`/admin/bookings/${booking.id}`),
          onError,
        }
      )
      return
    }

    createShop.mutate(
      {
        ...basePayload,
        items: values.items.map((item) => ({ service_id: item.service_id, qty: item.qty ?? 0 })),
      },
      {
        onSuccess: (booking) => navigate(`/admin/bookings/${booking.id}`),
        onError,
      }
    )
  })

  return (
    <form onSubmit={onSubmit} className="flex max-w-lg flex-col gap-8">
      <div>
        <h1 className="mb-4 text-xl font-semibold">Walk-in booking</h1>
        <p className="text-sm text-muted-foreground">
          Record a walk-in at the counter. The food or items are already on hand, so this books straight
          through to confirmed.
        </p>
      </div>

      {formError && <p className="text-sm text-destructive">{formError}</p>}

      <div className="flex flex-col gap-2">
        <h2 className="font-semibold">Booking type</h2>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="h-4 w-4"
            checked={bookingType === 'shop'}
            onChange={(event) => selectBookingType(event.target.checked ? 'shop' : 'roasting')}
          />
          Is order (customer is buying a shop item)
        </label>
        <p className="text-sm text-muted-foreground">Leave unchecked for a bring-your-own booking.</p>
      </div>

      <div className="flex flex-col gap-2">
        <h2 className="font-semibold">Customer</h2>
        <div className="flex gap-2">
          <Button
            type="button"
            size="sm"
            variant={customerMode === 'registered' ? 'default' : 'outline'}
            onClick={() => selectCustomerMode('registered')}
          >
            Registered customer
          </Button>
          <Button
            type="button"
            size="sm"
            variant={customerMode === 'guest' ? 'default' : 'outline'}
            onClick={() => selectCustomerMode('guest')}
          >
            Guest
          </Button>
        </div>

        {customerMode === 'registered' && (
          <div className="flex flex-col gap-2">
            {selectedCustomer ? (
              <div className="flex items-center justify-between rounded-lg border p-2">
                <div>
                  <div>{selectedCustomer.name}</div>
                  <div className="text-sm text-muted-foreground">{selectedCustomer.phone}</div>
                </div>
                <Button type="button" variant="outline" size="sm" onClick={clearCustomer}>
                  Change
                </Button>
              </div>
            ) : (
              <>
                <Input
                  placeholder="Search by name or phone"
                  value={customerSearch}
                  onChange={(event) => setCustomerSearch(event.target.value)}
                />
                {customersLoading && <p className="text-sm text-muted-foreground">Searching…</p>}
                {customerResults && customerResults.length > 0 && (
                  <ul className="flex flex-col gap-1 rounded-lg border">
                    {customerResults.map((customer) => (
                      <li key={customer.id}>
                        <button
                          type="button"
                          className="flex w-full flex-col items-start p-2 text-left hover:bg-accent"
                          onClick={() => pickCustomer(customer)}
                        >
                          <span>{customer.name}</span>
                          <span className="text-sm text-muted-foreground">{customer.phone}</span>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </>
            )}
            {errors.customer_id && <p className="text-sm text-destructive">{errors.customer_id.message}</p>}
          </div>
        )}

        {customerMode === 'guest' && (
          <div className="flex flex-col gap-2">
            <div className="flex flex-col gap-1">
              <Label htmlFor="guest_name">Guest name</Label>
              <Input id="guest_name" {...register('guest_name')} />
              {errors.guest_name && <p className="text-sm text-destructive">{errors.guest_name.message}</p>}
            </div>
            <div className="flex flex-col gap-1">
              <Label htmlFor="guest_phone">Guest phone</Label>
              <Input id="guest_phone" {...register('guest_phone')} />
              {errors.guest_phone && <p className="text-sm text-destructive">{errors.guest_phone.message}</p>}
            </div>
          </div>
        )}
      </div>

      <div className="flex flex-col gap-4">
        <h2 className="font-semibold">Items</h2>

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
                    {service.name}
                  </option>
                ))}
              </select>
              {errors.items?.[index]?.service_id && (
                <p className="text-sm text-destructive">{errors.items[index]?.service_id?.message}</p>
              )}
            </div>

            {bookingType === 'roasting' ? (
              <div className="flex flex-col gap-1">
                <Label htmlFor={`items.${index}.final_weight_kg`}>Final weight (kg)</Label>
                <Input
                  id={`items.${index}.final_weight_kg`}
                  type="number"
                  step="0.01"
                  {...register(`items.${index}.final_weight_kg`, { valueAsNumber: true })}
                />
                {errors.items?.[index]?.final_weight_kg && (
                  <p className="text-sm text-destructive">{errors.items[index]?.final_weight_kg?.message}</p>
                )}
              </div>
            ) : (
              <div className="flex flex-col gap-1">
                <Label htmlFor={`items.${index}.qty`}>Quantity</Label>
                <Input id={`items.${index}.qty`} type="number" {...register(`items.${index}.qty`, { valueAsNumber: true })} />
                {errors.items?.[index]?.qty && (
                  <p className="text-sm text-destructive">{errors.items[index]?.qty?.message}</p>
                )}
              </div>
            )}

            {fields.length > 1 && (
              <Button type="button" variant="outline" size="sm" className="w-fit" onClick={() => remove(index)}>
                Remove
              </Button>
            )}
          </div>
        ))}

        {errors.items?.root && <p className="text-sm text-destructive">{errors.items.root.message}</p>}

        <Button type="button" variant="outline" className="w-fit" onClick={() => append({ service_id: 0 })}>
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
          <Label htmlFor="notes">Notes (optional)</Label>
          <Input id="notes" {...register('notes')} />
        </div>
      </div>

      <Button type="submit" disabled={isSubmitting} className="w-fit">
        {isSubmitting ? 'Submitting…' : 'Create walk-in booking'}
      </Button>
    </form>
  )
}
