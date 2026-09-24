import { zodResolver } from '@hookform/resolvers/zod'
import { isAxiosError } from 'axios'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Navigate, useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { getGenericErrorMessage } from '@/lib/errors'
import { useAdminLogin, useMe } from './hooks'

const adminLoginSchema = z.object({
  email: z.string().email('Enter a valid email'),
  password: z.string().min(1, 'Password is required'),
})

type AdminLoginForm = z.infer<typeof adminLoginSchema>

export function AdminLoginPage() {
  const { data: me, isLoading: meLoading } = useMe()
  const adminLogin = useAdminLogin()
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors },
    setError,
  } = useForm<AdminLoginForm>({ resolver: zodResolver(adminLoginSchema) })

  if (!meLoading && (me?.role === 'admin' || me?.role === 'super_admin')) {
    return <Navigate to="/admin" replace />
  }

  const onSubmit = handleSubmit((values) => {
    setFormError(null)
    adminLogin.mutate(values, {
      onSuccess: () => navigate('/admin'),
      onError: (error) => {
        if (isAxiosError(error) && error.response?.status === 422) {
          setError('password', { message: 'These credentials do not match our records.' })
          return
        }
        setFormError(getGenericErrorMessage(error))
      },
    })
  })

  return (
    <form onSubmit={onSubmit} className="mx-auto flex max-w-sm flex-col gap-4 p-8">
      <h1 className="text-xl font-semibold">Admin login</h1>

      {formError && <p className="text-sm text-destructive">{formError}</p>}

      <div className="flex flex-col gap-1">
        <Label htmlFor="email">Email</Label>
        <Input id="email" type="email" {...register('email')} />
        {errors.email && <p className="text-sm text-destructive">{errors.email.message}</p>}
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor="password">Password</Label>
        <Input id="password" type="password" {...register('password')} />
        {errors.password && (
          <p className="text-sm text-destructive">{errors.password.message}</p>
        )}
      </div>

      <Button type="submit" disabled={adminLogin.isPending}>
        {adminLogin.isPending ? 'Logging in…' : 'Log in'}
      </Button>
    </form>
  )
}
