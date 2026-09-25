import { zodResolver } from '@hookform/resolvers/zod'
import { isAxiosError } from 'axios'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { getGenericErrorMessage } from '@/lib/errors'
import { useLogout, useMe, useUpdateProfile } from './hooks'
import { toNullableField } from './profile'

const profileFormSchema = z.object({
  first_name: z.string().min(1, 'First name is required'),
  middle_name: z.string().optional(),
  last_name: z.string().min(1, 'Last name is required'),
  address: z.string().min(1, 'Address is required'),
})

type ProfileForm = z.infer<typeof profileFormSchema>

function CustomerProfileForm({
  phone,
  defaultValues,
}: {
  phone: string | null
  defaultValues: ProfileForm
}) {
  const updateProfile = useUpdateProfile()
  const [formError, setFormError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  const {
    register,
    handleSubmit,
    formState: { errors },
    setError,
  } = useForm<ProfileForm>({ resolver: zodResolver(profileFormSchema), defaultValues })

  const onSubmit = handleSubmit((values) => {
    setFormError(null)
    setSaved(false)
    updateProfile.mutate(
      {
        first_name: values.first_name,
        middle_name: toNullableField(values.middle_name),
        last_name: values.last_name,
        address: values.address,
      },
      {
        onSuccess: () => setSaved(true),
        onError: (error) => {
          if (isAxiosError(error) && error.response?.status === 422) {
            const fieldErrors = error.response.data?.errors as
              | Record<string, string[]>
              | undefined
            for (const [name, messages] of Object.entries(fieldErrors ?? {})) {
              setError(name as keyof ProfileForm, { message: messages[0] })
            }
            return
          }
          setFormError(getGenericErrorMessage(error))
        },
      }
    )
  })

  return (
    <form onSubmit={onSubmit} className="flex max-w-sm flex-col gap-4">
      <div className="flex flex-col gap-1">
        <Label>Phone</Label>
        <p className="text-sm text-muted-foreground">{phone}</p>
      </div>

      {formError && <p className="text-sm text-destructive">{formError}</p>}
      {saved && <p className="text-sm text-muted-foreground">Profile updated.</p>}

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

      <Button type="submit" disabled={updateProfile.isPending} className="w-fit">
        {updateProfile.isPending ? 'Saving…' : 'Save changes'}
      </Button>
    </form>
  )
}

export function AccountPage() {
  const { data: me } = useMe()
  const logout = useLogout()

  if (!me) {
    return null
  }

  const displayName =
    me.role === 'customer' ? [me.first_name, me.last_name].filter(Boolean).join(' ') : me.name

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-4">
        <p>Signed in as {displayName}</p>
        <Button
          variant="outline"
          className="w-fit"
          onClick={() => logout.mutate()}
          disabled={logout.isPending}
        >
          Log out
        </Button>
      </div>

      {me.role === 'customer' && (
        <CustomerProfileForm
          phone={me.phone}
          defaultValues={{
            first_name: me.first_name ?? '',
            middle_name: me.middle_name ?? '',
            last_name: me.last_name ?? '',
            address: me.address ?? '',
          }}
        />
      )}
    </div>
  )
}
