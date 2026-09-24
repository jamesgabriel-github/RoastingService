import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createAdminAccount, fetchAdminAccounts, updateAdminAccount } from './api'

const adminAccountsKey = ['admin-accounts']

export function useAdminAccounts() {
  return useQuery({
    queryKey: adminAccountsKey,
    queryFn: fetchAdminAccounts,
  })
}

export function useCreateAdminAccount() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: createAdminAccount,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: adminAccountsKey })
    },
  })
}

export function useUpdateAdminAccount() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, ...payload }: { id: number; is_active?: boolean; permissions?: string[] }) =>
      updateAdminAccount(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: adminAccountsKey })
    },
  })
}
