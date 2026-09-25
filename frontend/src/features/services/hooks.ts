import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createService, fetchServices, toggleService, updateService } from './api'
import type { ServicePayload } from './api'

const servicesKey = ['admin-services']

export function useServices() {
  return useQuery({
    queryKey: servicesKey,
    queryFn: fetchServices,
  })
}

export function useCreateService() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: createService,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: servicesKey })
    },
  })
}

export function useUpdateService() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: ServicePayload }) => updateService(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: servicesKey })
    },
  })
}

export function useToggleService() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: toggleService,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: servicesKey })
    },
  })
}
