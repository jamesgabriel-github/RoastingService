import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { AdjustPayload, RestockPayload } from './api'
import { adjustService, fetchInventory, fetchInventoryLogs, restockService } from './api'

const inventoryKey = ['admin-inventory']
const inventoryLogsKey = ['admin-inventory-logs']

export function useInventory() {
  return useQuery({
    queryKey: inventoryKey,
    queryFn: fetchInventory,
  })
}

export function useInventoryLogs(page: number) {
  return useQuery({
    queryKey: [...inventoryLogsKey, page],
    queryFn: () => fetchInventoryLogs(page),
  })
}

export function useRestockService() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: RestockPayload }) => restockService(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: inventoryKey })
      queryClient.invalidateQueries({ queryKey: inventoryLogsKey })
    },
  })
}

export function useAdjustService() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: AdjustPayload }) => adjustService(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: inventoryKey })
      queryClient.invalidateQueries({ queryKey: inventoryLogsKey })
    },
  })
}
