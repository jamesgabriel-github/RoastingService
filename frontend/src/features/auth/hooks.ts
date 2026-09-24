import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { adminLogin, adminLogout, fetchMe, loginCustomer, logout, registerCustomer } from './api'

export function useMe() {
  return useQuery({
    queryKey: ['me'],
    queryFn: fetchMe,
    retry: false,
  })
}

export function useRegister() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: registerCustomer,
    onSuccess: (me) => {
      queryClient.setQueryData(['me'], me)
    },
  })
}

export function useLoginCustomer() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: loginCustomer,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['me'] })
    },
  })
}

export function useLogout() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: logout,
    onSuccess: () => {
      queryClient.setQueryData(['me'], null)
    },
  })
}

export function useAdminLogin() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ email, password }: { email: string; password: string }) =>
      adminLogin(email, password),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['me'] })
    },
  })
}

export function useAdminLogout() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: adminLogout,
    onSuccess: () => {
      queryClient.setQueryData(['me'], null)
    },
  })
}
