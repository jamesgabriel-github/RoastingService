import { zodResolver } from '@hookform/resolvers/zod'
import { isAxiosError } from 'axios'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { getGenericErrorMessage } from '@/lib/errors'
import { useCompleteProfile } from './hooks'

const profileSetupSchema = z.object({
  first_name: z.string().min(1, 'First name is required'),
  middle_name: z.string().optional(),
  last_name: z.string().min(1, 'Last name is required'),
  address: z.string().min(1, 'Address is required'),
})

type ProfileSetupForm = z.infer<typeof profileSetupSchema>

export function ProfileSetupPage() {
  const completeProfile = useCompleteProfile()
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors },
    setError,
  } = useForm<ProfileSetupForm>({ resolver: zodResolver(profileSetupSchema) })

  const onSubmit = handleSubmit((values) => {
    setFormError(null)
    completeProfile.mutate(values, {
      onSuccess: () => navigate('/account'),
      onError: (error) => {
        if (isAxiosError(error) && error.response?.status === 422) {
          const fieldErrors = error.response.data?.errors as
            | Record<string, string[]>
            | undefined
          for (const [name, messages] of Object.entries(fieldErrors ?? {})) {
            setError(name as keyof ProfileSetupForm, { message: messages[0] })
          }
          return
        }
        setFormError(getGenericErrorMessage(error))
      },
    })
  })

  return (
    <form onSubmit={onSubmit} className="mx-auto flex max-w-sm flex-col gap-4">
      <h1 className="text-xl font-semibold">Complete your profile</h1>

      {formError && <p className="text-sm text-destructive">{formError}</p>}

      <div className="flex flex-col gap-1">
        <Label htmlFor="first_name">First name</Label>
        <Input id="first_name" {...register('first_name')} />
        {errors.first_name && (
          <p className="text-sm text-destructive">{errors.first_name.message}</p>
        )}
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor="middle_name">Middle name (optional)</Label>
        <Input id="middle_name" {...register('middle_name')} />
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor="last_name">Last name</Label>
        <Input id="last_name" {...register('last_name')} />
        {errors.last_name && (
          <p className="text-sm text-destructive">{errors.last_name.message}</p>
        )}
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor="address">Address</Label>
        <Input id="address" {...register('address')} />
        {errors.address && <p className="text-sm text-destructive">{errors.address.message}</p>}
      </div>

      <Button type="submit" disabled={completeProfile.isPending}>
        {completeProfile.isPending ? 'Saving…' : 'Continue'}
      </Button>
    </form>
  )
}
