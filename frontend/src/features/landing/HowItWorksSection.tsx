const steps = [
  'Book a roasting slot or order from our shop stock online.',
  'We review and confirm your booking or order.',
  'We cook it fresh, right on schedule.',
  'Pick it up, or have it delivered to you.',
]

export function HowItWorksSection() {
  return (
    <section className="flex flex-col gap-4 py-8">
      <h2 className="text-xl font-semibold text-stone-900">How it works</h2>
      <ol className="flex max-w-2xl flex-col gap-2 text-sm text-stone-700">
        {steps.map((step, index) => (
          <li key={step} className="flex gap-3">
            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xs font-semibold text-orange-700">
              {index + 1}
            </span>
            <span>{step}</span>
          </li>
        ))}
      </ol>
    </section>
  )
}
