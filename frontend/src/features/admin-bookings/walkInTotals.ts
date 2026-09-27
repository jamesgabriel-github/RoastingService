export interface WalkInLine {
  rate: number
  qty: number
}

/** Server-authoritative total is recomputed on submit; this only drives the live preview. */
export function computeOrderTotal(lines: WalkInLine[]): number {
  const total = lines.reduce((sum, line) => sum + line.rate * line.qty, 0)
  return Math.round(total * 100) / 100
}

export function computeBalanceDue(total: number, paid: number): number {
  return Math.max(0, total - paid)
}

export type QuickTenderType = 'unpaid' | 'half' | 'full'

export function resolveQuickTenderAmount(type: QuickTenderType, total: number): number {
  if (type === 'unpaid') return 0
  if (type === 'half') return Math.round((total / 2) * 100) / 100
  return Math.round(total * 100) / 100
}
