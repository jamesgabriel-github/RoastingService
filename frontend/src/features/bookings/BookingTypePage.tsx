import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/button'

export function BookingTypePage() {
  return (
    <div className="flex max-w-sm flex-col gap-4">
      <h1 className="text-xl font-semibold">New booking</h1>
      <p className="text-sm text-muted-foreground">Choose how you&apos;d like to book.</p>

      <Button render={<Link to="/book/roasting" />} className="w-fit">
        Bring my own raw food
      </Button>
      <Button render={<Link to="/book/shop" />} variant="outline" className="w-fit">
        Order from the shop
      </Button>
    </div>
  )
}
