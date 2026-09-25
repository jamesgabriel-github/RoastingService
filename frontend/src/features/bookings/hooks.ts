import { useMutation, useQuery } from '@tanstack/react-query'
import { createBooking, fetchBookableServices } from './api'

export function useBookableServices() {
  return useQuery({
    queryKey: ['bookable-services'],
    queryFn: fetchBookableServices,
  })
}

export function useCreateBooking() {
  return useMutation({
    mutationFn: createBooking,
  })
}
