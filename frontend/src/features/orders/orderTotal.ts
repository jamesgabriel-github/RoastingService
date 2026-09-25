export interface OrderLine {
  rate: number
  qty: number
}

/** Server-authoritative total is recomputed on submit; this only drives the live preview. */
export function computeOrderTotal(lines: OrderLine[]): number {
  const total = lines.reduce((sum, line) => sum + line.rate * line.qty, 0)
  return Math.round(total * 100) / 100
}
