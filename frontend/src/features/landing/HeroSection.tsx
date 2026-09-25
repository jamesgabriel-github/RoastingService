import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/button'

export function HeroSection() {
  return (
    <section className="flex flex-col items-start gap-4 rounded-xl bg-amber-50 px-6 py-12 text-stone-900">
      <h1 className="text-3xl font-semibold text-stone-900 sm:text-4xl">Roasting Service</h1>
      <p className="max-w-xl text-base text-stone-700">
        Bring us your own raw food to roast, or order ready-to-cook items from our shop stock -
        pick up or have it delivered.
      </p>
      <Button render={<Link to="/book" />} className="w-fit bg-orange-600 text-white hover:bg-orange-700">
        Book Now
      </Button>
    </section>
  )
}
