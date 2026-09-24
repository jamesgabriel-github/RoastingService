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
import { useMe, useRegister } from './hooks'
import { PH_PHONE_REGEX } from './phone'

const registerSchema = z.object({
  name: z.string().min(1, 'Name is required'),
  email: z.string().email('Enter a valid email'),
  phone: z.string().regex(PH_PHONE_REGEX, 'Enter a valid PH mobile number'),
  password: z.string().min(8, 'Password must be at least 8 characters'),
})

type RegisterForm = z.infer<typeof registerSchema>

export function RegisterPage() {
  const { data: me, isLoading: meLoading } = useMe()
  const register = useRegister()
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register: field,
    handleSubmit,
    formState: { errors },
    setError,
  } = useForm<RegisterForm>({ resolver: zodResolver(registerSchema) })

  if (!meLoading && me?.role === 'customer') {
    return <Navigate to="/account" replace />
  }

  const onSubmit = handleSubmit((values) => {
    setFormError(null)
    register.mutate(values, {
      onSuccess: () => navigate('/account'),
      onError: (error) => {
        if (isAxiosError(error) && error.response?.status === 422) {
          const fieldErrors = error.response.data?.errors as
            | Record<string, string[]>
            | undefined
          for (const [name, messages] of Object.entries(fieldErrors ?? {})) {
            setError(name as keyof RegisterForm, { message: messages[0] })
          }
          return
        }
        setFormError(getGenericErrorMessage(error))
      },
    })
  })

  return (
    <form onSubmit={onSubmit} className="mx-auto flex max-w-sm flex-col gap-4">
      <h1 className="text-xl font-semibold">Create an account</h1>

      {formError && <p className="text-sm text-destructive">{formError}</p>}

      <div className="flex flex-col gap-1">
        <Label htmlFor="name">Name</Label>
        <Input id="name" {...field('name')} />
        {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor="email">Email</Label>
        <Input id="email" type="email" {...field('email')} />
        {errors.email && <p className="text-sm text-destructive">{errors.email.message}</p>}
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor="phone">Mobile number</Label>
        <Input id="phone" placeholder="09171234567" {...field('phone')} />
        {errors.phone && <p className="text-sm text-destructive">{errors.phone.message}</p>}
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor="password">Password</Label>
        <Input id="password" type="password" {...field('password')} />
        {errors.password && (
          <p className="text-sm text-destructive">{errors.password.message}</p>
        )}
      </div>

      <Button type="submit" disabled={register.isPending}>
        {register.isPending ? 'Creating account…' : 'Register'}
      </Button>
    </form>
  )
}
