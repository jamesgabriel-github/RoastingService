import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  adminLogin,
  adminLogout,
  completeProfile,
  fetchMe,
  loginCustomer,
  logout,
} from './api'

export function useMe() {
  return useQuery({
    queryKey: ['me'],
    queryFn: fetchMe,
    retry: false,
  })
}

export function useLoginCustomer() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: loginCustomer,
    onSuccess: (me) => {
      queryClient.setQueryData(['me'], me)
    },
  })
}

export function useCompleteProfile() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: completeProfile,
    onSuccess: (me) => {
      queryClient.setQueryData(['me'], me)
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
