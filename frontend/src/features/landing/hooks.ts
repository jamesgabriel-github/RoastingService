import { useQuery } from '@tanstack/react-query'
import { fetchActiveServices } from './api'

export function useActiveServices() {
  return useQuery({
    queryKey: ['landing-services'],
    queryFn: fetchActiveServices,
  })
}
