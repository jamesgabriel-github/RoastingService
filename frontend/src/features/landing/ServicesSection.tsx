import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { formatCurrency } from '@/lib/currency'
import { useActiveServices } from './hooks'

export function ServicesSection() {
  const { data: services, isLoading, isError } = useActiveServices()

  return (
    <section className="flex flex-col gap-4 py-8">
      <h2 className="text-xl font-semibold text-stone-900">Our services</h2>

      {isLoading && <p className="text-sm text-stone-600">Loading services…</p>}

      {isError && (
        <p className="text-sm text-stone-600">
          We couldn&apos;t load our services. Please try again shortly.
        </p>
      )}

      {services && services.length === 0 && (
        <p className="text-sm text-stone-600">No services available right now.</p>
      )}

      {services && services.length > 0 && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {services.map((service) => (
            <Card key={service.id} className="border border-amber-200 bg-white">
              <CardHeader>
                <CardTitle>{service.name}</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-2">
                {service.description && (
                  <p className="text-sm text-stone-600">{service.description}</p>
                )}
                <p className="text-sm text-stone-700">{service.est_minutes} min</p>
                {service.allow_customer_supplied && service.roasting_rate_per_kg && (
                  <p className="text-sm font-medium text-orange-700">
                    {formatCurrency(service.roasting_rate_per_kg)} / kg (bring your own)
                  </p>
                )}
                {service.allow_shop_supplied && service.shop_price && (
                  <p className="text-sm font-medium text-orange-700">
                    {formatCurrency(service.shop_price)} / piece
                  </p>
                )}
                {service.allow_shop_supplied && service.in_stock === false && (
                  <span className="w-fit rounded-full bg-stone-200 px-2 py-0.5 text-xs text-stone-600">
                    Out of stock
                  </span>
                )}
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </section>
  )
}
