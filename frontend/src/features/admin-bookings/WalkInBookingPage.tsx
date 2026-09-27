import { zodResolver } from '@hookform/resolvers/zod'
import { isAxiosError } from 'axios'
import { useState } from 'react'
import { useFieldArray, useForm } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { formatCurrency } from '@/lib/currency'
import { getGenericErrorMessage } from '@/lib/errors'
import { cn } from 'cn'
import {
  useCreateWalkInRoastingBooking,
  useCreateWalkInShopOrder,
  useSearchWalkInCustomers,
  useWalkInServices,
} from './hooks'
import type { AdminBooking, WalkInCustomer, WalkInService } from './types'
import { computeBalanceDue, computeOrderTotal, resolveQuickTenderAmount } from './walkInTotals'
import type { QuickTenderType } from './walkInTotals'

const itemSchema = z.object({
  service_id: z.number().min(1, 'Select an item'),
  final_weight_kg: z.number().optional(),
  qty: z.number().optional(),
  est_minutes: z.number().min(1).max(1440).optional(),
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
    preferred_pickup_date: z.string().min(1, 'Choose a date'),
    preferred_pickup_time: z.string().min(1, 'Choose a time slot'),
    notes: z.string().optional(),
    paid_amount: z.number().min(0).optional(),
    payment_method: z.enum(['cash', 'gcash']).optional(),
    payment_reference_no: z.string().optional(),
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

    if ((values.paid_amount ?? 0) > 0 && !values.payment_method) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['payment_method'],
        message: 'Select how the payment was tendered',
      })
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

function toDateInputValue(date: Date): string {
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

const emptyValues: WalkInFormValues = {
  bookingType: 'roasting',
  customerMode: 'guest',
  customer_id: null,
  guest_name: '',
  guest_phone: '',
  items: [],
  fulfillment: 'pickup',
  delivery_address: '',
  preferred_pickup_date: toDateInputValue(new Date()),
  preferred_pickup_time: '11:30',
  notes: '',
  paid_amount: undefined,
  payment_method: undefined,
  payment_reference_no: '',
}

function getServiceRate(service: WalkInService, bookingType: WalkInFormValues['bookingType']): number {
  return Number((bookingType === 'roasting' ? service.roasting_rate_per_kg : service.shop_price) ?? 0)
}

export function WalkInBookingPage() {
  const navigate = useNavigate()
  const { data: allServices } = useWalkInServices()
  const createRoasting = useCreateWalkInRoastingBooking()
  const createShop = useCreateWalkInShopOrder()
  const [formError, setFormError] = useState<string | null>(null)
  const [customerSearch, setCustomerSearch] = useState('')
  const [selectedCustomer, setSelectedCustomer] = useState<WalkInCustomer | null>(null)
  const [confirmedBooking, setConfirmedBooking] = useState<AdminBooking | null>(null)
  const { data: customerResults, isFetching: customersLoading } = useSearchWalkInCustomers(customerSearch)

  const {
    register,
    control,
    handleSubmit,
    watch,
    setValue,
    reset,
    formState: { errors },
  } = useForm<WalkInFormValues>({
    resolver: zodResolver(walkInSchema),
    defaultValues: emptyValues,
  })

  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const bookingType = watch('bookingType')
  const customerMode = watch('customerMode')
  const fulfillment = watch('fulfillment')
  const paidAmount = watch('paid_amount')
  const paymentMethod = watch('payment_method')
  const watchedItems = watch('items')

  const services = allServices?.filter((service) =>
    bookingType === 'roasting' ? service.allow_customer_supplied : service.allow_shop_supplied
  )
  const isSubmitting = createRoasting.isPending || createShop.isPending
  const safePaidAmount = Number.isFinite(paidAmount) ? (paidAmount as number) : 0

  const orderTotal = computeOrderTotal(
    watchedItems.flatMap((item) => {
      const service = services?.find((candidate) => candidate.id === item.service_id)
      if (!service) return []

      const rate = getServiceRate(service, bookingType)
      const qty = bookingType === 'roasting' ? (item.final_weight_kg ?? 0) : (item.qty ?? 0)

      return [{ rate, qty }]
    })
  )

  const balanceDue = computeBalanceDue(orderTotal, safePaidAmount)

  const selectBookingType = (next: WalkInFormValues['bookingType']) => {
    setValue('bookingType', next)
    setValue('items', [])
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

  const setQuickTender = (type: QuickTenderType) => {
    setValue('paid_amount', resolveQuickTenderAmount(type, orderTotal))
  }

  const closeConfirmation = () => {
    setConfirmedBooking(null)
    setSelectedCustomer(null)
    setCustomerSearch('')
    reset({ ...emptyValues, preferred_pickup_date: toDateInputValue(new Date()) })
    navigate('/admin/bookings?group=pending')
  }

  const onSubmit = handleSubmit((values) => {
    setFormError(null)

    const hasPayment = (values.paid_amount ?? 0) > 0

    const basePayload = {
      customer_id: values.customerMode === 'registered' ? values.customer_id : null,
      guest_name: values.customerMode === 'guest' && values.guest_name?.trim() ? values.guest_name.trim() : null,
      guest_phone: values.customerMode === 'guest' && values.guest_phone?.trim() ? values.guest_phone.trim() : null,
      fulfillment: values.fulfillment,
      delivery_address: values.fulfillment === 'delivery' ? (values.delivery_address?.trim() ?? null) : null,
      preferred_pickup_at: new Date(`${values.preferred_pickup_date}T${values.preferred_pickup_time}`).toISOString(),
      notes: values.notes?.trim() ? values.notes.trim() : null,
      paid_amount: hasPayment ? values.paid_amount! : null,
      payment_method: hasPayment ? (values.payment_method ?? null) : null,
      payment_reference_no: hasPayment ? (values.payment_reference_no?.trim() || null) : null,
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
            est_minutes: item.est_minutes ?? null,
          })),
        },
        {
          onSuccess: (booking) => setConfirmedBooking(booking),
          onError,
        }
      )
      return
    }

    createShop.mutate(
      {
        ...basePayload,
        items: values.items.map((item) => ({
          service_id: item.service_id,
          qty: item.qty ?? 0,
          est_minutes: item.est_minutes ?? null,
        })),
      },
      {
        onSuccess: (booking) => setConfirmedBooking(booking),
        onError,
      }
    )
  })

  return (
    <>
      <form onSubmit={onSubmit} className="grid max-w-5xl grid-cols-1 gap-6 lg:grid-cols-5">
        <div className="flex flex-col gap-8 lg:col-span-3">
          <div>
            <h1 className="mb-4 text-xl font-semibold">Walk-in booking</h1>
            <p className="text-sm text-muted-foreground">
              Record a walk-in at the counter. The food or items are already on hand, so this books straight
              through to confirmed.
            </p>
          </div>

          {formError && <p className="text-sm text-destructive">{formError}</p>}

          <div className="flex flex-col gap-2">
            <h2 className="font-semibold">Sourcing type</h2>
            <p className="text-sm text-muted-foreground">Is the item provided by the customer or bought from the shop?</p>
            <div className="flex gap-2">
              <Button
                type="button"
                size="sm"
                variant={bookingType === 'roasting' ? 'default' : 'outline'}
                onClick={() => selectBookingType('roasting')}
              >
                Customer BYO
              </Button>
              <Button
                type="button"
                size="sm"
                variant={bookingType === 'shop' ? 'default' : 'outline'}
                onClick={() => selectBookingType('shop')}
              >
                Shop order
              </Button>
            </div>
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
                  <Label htmlFor="guest_name">Guest name (optional)</Label>
                  <Input id="guest_name" {...register('guest_name')} />
                </div>
                <div className="flex flex-col gap-1">
                  <Label htmlFor="guest_phone">Guest phone (optional)</Label>
                  <Input id="guest_phone" {...register('guest_phone')} />
                </div>
              </div>
            )}
          </div>

          <div className="flex flex-col gap-4">
            <div>
              <h2 className="font-semibold">Items</h2>
              <p className="text-sm text-muted-foreground">Tap an item to add it to the booking</p>
            </div>

            <div className="flex gap-2 overflow-x-auto pb-2">
              {services?.map((service) => {
                const outOfStock = bookingType === 'shop' && service.in_stock === false

                return (
                  <button
                    key={service.id}
                    type="button"
                    disabled={outOfStock}
                    onClick={() =>
                      append(
                        bookingType === 'roasting'
                          ? { service_id: service.id, final_weight_kg: 1, est_minutes: service.est_minutes }
                          : { service_id: service.id, qty: 1, est_minutes: service.est_minutes }
                      )
                    }
                    className={cn(
                      'flex w-28 shrink-0 flex-col gap-1 rounded-lg border p-2.5 text-left transition-colors',
                      outOfStock ? 'cursor-not-allowed opacity-50' : 'hover:border-primary hover:bg-accent'
                    )}
                  >
                    <span className="text-[11px] text-muted-foreground">~{service.est_minutes} min</span>
                    <span className="text-xs leading-tight font-semibold">{service.name}</span>
                    <span className="font-mono text-[11px] font-semibold text-primary">
                      {formatCurrency(getServiceRate(service, bookingType))}
                      {bookingType === 'roasting' ? ' / kg' : ' each'}
                    </span>
                    {outOfStock && <span className="text-[10px] font-semibold text-destructive">Out of stock</span>}
                  </button>
                )
              })}
            </div>

            {errors.items?.root && <p className="text-sm text-destructive">{errors.items.root.message}</p>}

            <div className="flex flex-col gap-2">
              <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                Selected items
              </span>

              {fields.length === 0 && (
                <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                  No items selected yet. Tap an item above to add it.
                </p>
              )}

              {fields.map((field, index) => {
                const currentItem = watchedItems[index]
                const service = services?.find((candidate) => candidate.id === currentItem?.service_id)
                const rate = service ? getServiceRate(service, bookingType) : 0
                const quantity = bookingType === 'roasting' ? (currentItem?.final_weight_kg ?? 0) : (currentItem?.qty ?? 0)
                const subtotal = rate * quantity

                return (
                  <div
                    key={field.id}
                    className="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-center sm:justify-between"
                  >
                    <div className="min-w-0">
                      <div className="text-sm font-semibold">{service?.name ?? 'Unknown item'}</div>
                      <div className="text-xs text-muted-foreground">
                        {formatCurrency(rate)}
                        {bookingType === 'roasting' ? ' / kg' : ' each'}
                      </div>
                    </div>

                    <div className="flex items-end gap-3">
                      {bookingType === 'roasting' ? (
                        <div className="flex flex-col gap-1">
                          <Label htmlFor={`items.${index}.final_weight_kg`} className="text-xs">
                            Final weight (kg)
                          </Label>
                          <Input
                            id={`items.${index}.final_weight_kg`}
                            type="number"
                            step="0.01"
                            className="w-24"
                            {...register(`items.${index}.final_weight_kg`, { valueAsNumber: true })}
                          />
                          {errors.items?.[index]?.final_weight_kg && (
                            <p className="text-sm text-destructive">{errors.items[index]?.final_weight_kg?.message}</p>
                          )}
                        </div>
                      ) : (
                        <div className="flex flex-col gap-1">
                          <Label htmlFor={`items.${index}.qty`} className="text-xs">
                            Qty
                          </Label>
                          <Input
                            id={`items.${index}.qty`}
                            type="number"
                            className="w-20"
                            {...register(`items.${index}.qty`, { valueAsNumber: true })}
                          />
                          {errors.items?.[index]?.qty && (
                            <p className="text-sm text-destructive">{errors.items[index]?.qty?.message}</p>
                          )}
                        </div>
                      )}

                      <div className="flex flex-col gap-1">
                        <Label htmlFor={`items.${index}.est_minutes`} className="text-xs">
                          Cooking time (min)
                        </Label>
                        <Input
                          id={`items.${index}.est_minutes`}
                          type="number"
                          min={1}
                          max={1440}
                          className="w-24"
                          {...register(`items.${index}.est_minutes`, {
                            setValueAs: (value) => (value === '' ? undefined : Number(value)),
                          })}
                        />
                        {errors.items?.[index]?.est_minutes && (
                          <p className="text-sm text-destructive">{errors.items[index]?.est_minutes?.message}</p>
                        )}
                      </div>

                      <div className="w-20 text-right font-mono text-sm font-semibold">{formatCurrency(subtotal)}</div>

                      <Button type="button" variant="outline" size="sm" onClick={() => remove(index)}>
                        Remove
                      </Button>
                    </div>
                  </div>
                )
              })}
            </div>
          </div>

          <div className="flex flex-col gap-4">
            <h2 className="font-semibold">Pickup or delivery</h2>

            <div className="flex gap-2">
              <Button
                type="button"
                size="sm"
                variant={fulfillment === 'pickup' ? 'default' : 'outline'}
                onClick={() => setValue('fulfillment', 'pickup')}
              >
                Pickup
              </Button>
              <Button
                type="button"
                size="sm"
                variant={fulfillment === 'delivery' ? 'default' : 'outline'}
                onClick={() => setValue('fulfillment', 'delivery')}
              >
                Delivery
              </Button>
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

            <div className="grid grid-cols-2 gap-3">
              <div className="flex flex-col gap-1">
                <Label htmlFor="preferred_pickup_date">Ready date</Label>
                <Input id="preferred_pickup_date" type="date" {...register('preferred_pickup_date')} />
                {errors.preferred_pickup_date && (
                  <p className="text-sm text-destructive">{errors.preferred_pickup_date.message}</p>
                )}
              </div>
              <div className="flex flex-col gap-1">
                <Label htmlFor="preferred_pickup_time">Target ready time</Label>
                <Input id="preferred_pickup_time" type="time" {...register('preferred_pickup_time')} />
                {errors.preferred_pickup_time && (
                  <p className="text-sm text-destructive">{errors.preferred_pickup_time.message}</p>
                )}
              </div>
            </div>

            <div className="flex flex-col gap-1">
              <Label htmlFor="notes">Notes (optional)</Label>
              <Input id="notes" {...register('notes')} />
            </div>
          </div>
        </div>

        <div className="flex flex-col gap-6 self-start lg:sticky lg:top-6 lg:col-span-2">
          <div className="flex flex-col gap-3 rounded-lg border p-4">
            <h2 className="font-semibold">Payment tendered</h2>

            <div className="flex flex-col gap-1">
              <Label htmlFor="paid_amount">Amount paid now</Label>
              <Input
                id="paid_amount"
                type="number"
                step="0.01"
                min={0}
                {...register('paid_amount', { setValueAs: (value) => (value === '' ? undefined : Number(value)) })}
              />
              {errors.paid_amount && <p className="text-sm text-destructive">{errors.paid_amount.message}</p>}
            </div>

            <div className="flex gap-2">
              <Button type="button" size="sm" variant="outline" onClick={() => setQuickTender('unpaid')}>
                Unpaid
              </Button>
              <Button type="button" size="sm" variant="outline" onClick={() => setQuickTender('half')}>
                Half now
              </Button>
              <Button type="button" size="sm" variant="outline" onClick={() => setQuickTender('full')}>
                Full total
              </Button>
            </div>

            <div className="flex flex-col gap-1">
              <Label>Payment method</Label>
              <div className="flex gap-2">
                <Button
                  type="button"
                  size="sm"
                  variant={paymentMethod === 'cash' ? 'default' : 'outline'}
                  onClick={() => setValue('payment_method', 'cash')}
                >
                  Cash
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant={paymentMethod === 'gcash' ? 'default' : 'outline'}
                  onClick={() => setValue('payment_method', 'gcash')}
                >
                  GCash
                </Button>
              </div>
              {errors.payment_method && <p className="text-sm text-destructive">{errors.payment_method.message}</p>}
            </div>

            {paymentMethod === 'gcash' && (
              <div className="flex flex-col gap-1">
                <Label htmlFor="payment_reference_no">GCash reference number (optional)</Label>
                <Input id="payment_reference_no" {...register('payment_reference_no')} />
              </div>
            )}
          </div>

          <div className="flex flex-col gap-2 rounded-lg border p-4">
            <h2 className="font-semibold">Order total</h2>
            <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">Order total</span>
              <span className="font-semibold">{formatCurrency(orderTotal)}</span>
            </div>
            <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">Amount paid</span>
              <span className="font-semibold">{formatCurrency(safePaidAmount)}</span>
            </div>
            <div className="flex justify-between text-base">
              <span className="font-semibold">Balance due</span>
              <span className="font-bold">{formatCurrency(balanceDue)}</span>
            </div>
          </div>

          <Button type="submit" disabled={isSubmitting} className="w-full">
            {isSubmitting ? 'Submitting…' : 'Create walk-in booking'}
          </Button>
        </div>
      </form>

      <Dialog open={confirmedBooking !== null} onOpenChange={(open) => !open && closeConfirmation()}>
        <DialogContent showCloseButton={false}>
          <DialogHeader>
            <DialogTitle>Booking confirmed</DialogTitle>
            <DialogDescription>Give the customer this code as their pickup/delivery reference.</DialogDescription>
          </DialogHeader>

          {confirmedBooking && (
            <div className="flex flex-col gap-3">
              <div className="rounded-lg border bg-muted/40 p-3 text-center">
                <div className="text-xs text-muted-foreground">Walk-in booking code</div>
                <div className="font-mono text-lg font-semibold">{confirmedBooking.code}</div>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Balance due at handover</span>
                <span className="font-semibold">{formatCurrency(confirmedBooking.balance ?? confirmedBooking.total_amount ?? 0)}</span>
              </div>
            </div>
          )}

          <DialogFooter>
            <Button onClick={closeConfirmation}>Done / Next order</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
