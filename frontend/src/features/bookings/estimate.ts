export interface EstimateLine {
  rate: number
  estWeightKg: number
}

/** Server-authoritative total is recomputed on submit; this only drives the live preview. */
export function computeEstimatedTotal(lines: EstimateLine[]): number {
  const total = lines.reduce((sum, line) => sum + line.rate * line.estWeightKg, 0)
  return Math.round(total * 100) / 100
}
