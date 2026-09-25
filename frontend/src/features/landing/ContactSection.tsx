// Placeholder contact details - the shop owner has not provided real address,
// phone, or email yet; no such data exists anywhere in this project's models.
export function ContactSection() {
  return (
    <section className="flex flex-col gap-2 py-8">
      <h2 className="text-xl font-semibold text-stone-900">Visit or contact us</h2>
      <div className="flex flex-col gap-1 text-sm text-stone-700">
        <p>[Shop address]</p>
        <p>[Phone number]</p>
        <p>[Email address]</p>
      </div>
    </section>
  )
}
