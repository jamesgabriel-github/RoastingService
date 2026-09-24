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
import { useLoginCustomer, useMe } from './hooks'
import { PH_PHONE_REGEX } from './phone'

const loginSchema = z.object({
  phone: z.string().regex(PH_PHONE_REGEX, 'Enter a valid PH mobile number'),
})

type LoginForm = z.infer<typeof loginSchema>

export function LoginPage() {
  const { data: me, isLoading: meLoading } = useMe()
  const login = useLoginCustomer()
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors },
    setError,
  } = useForm<LoginForm>({ resolver: zodResolver(loginSchema) })

  if (!meLoading && me?.role === 'customer') {
    return <Navigate to="/account" replace />
  }

  const onSubmit = handleSubmit(({ phone }) => {
    setFormError(null)
    login.mutate(phone, {
      onSuccess: () => navigate('/account'),
      onError: (error) => {
        if (isAxiosError(error) && error.response?.status === 422) {
          setError('phone', { message: 'We could not find an account with that number.' })
          return
        }
        setFormError(getGenericErrorMessage(error))
      },
    })
  })

  return (
    <form onSubmit={onSubmit} className="mx-auto flex max-w-sm flex-col gap-4">
      <h1 className="text-xl font-semibold">Log in</h1>

      {formError && <p className="text-sm text-destructive">{formError}</p>}

      <div className="flex flex-col gap-1">
        <Label htmlFor="phone">Mobile number</Label>
        <Input id="phone" placeholder="09171234567" {...register('phone')} />
        {errors.phone && <p className="text-sm text-destructive">{errors.phone.message}</p>}
      </div>

      <Button type="submit" disabled={login.isPending}>
        {login.isPending ? 'Logging in…' : 'Log in'}
      </Button>
    </form>
  )
}
