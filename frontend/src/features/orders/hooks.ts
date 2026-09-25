import { useMutation, useQuery } from '@tanstack/react-query'
import { createOrder, fetchShoppableServices } from './api'

export function useShoppableServices() {
  return useQuery({
    queryKey: ['shoppable-services'],
    queryFn: fetchShoppableServices,
  })
}

export function useCreateOrder() {
  return useMutation({
    mutationFn: createOrder,
  })
}
