const formatter = new Intl.NumberFormat('en-PH', {
  style: 'currency',
  currency: 'PHP',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

export function formatCurrency(amount: string | number): string {
  return formatter.format(typeof amount === 'string' ? Number(amount) : amount)
}
