import { zodResolver } from '@hookform/resolvers/zod'
import { isAxiosError } from 'axios'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { getGenericErrorMessage } from '@/lib/errors'
import { useAdminAccounts, useCreateAdminAccount, useUpdateAdminAccount } from './hooks'
import { MODULES, type AdminAccount } from './types'

const createAdminSchema = z.object({
  name: z.string().min(1, 'Name is required'),
  email: z.string().email('Enter a valid email'),
  password: z.string().min(8, 'Password must be at least 8 characters'),
})

type CreateAdminForm = z.infer<typeof createAdminSchema>

function AdminAccountRow({ account }: { account: AdminAccount }) {
  const [permissions, setPermissions] = useState<string[]>(account.permissions)
  const [rowError, setRowError] = useState<string | null>(null)
  const update = useUpdateAdminAccount()

  const toggleModule = (module: string) => {
    setPermissions((current) =>
      current.includes(module) ? current.filter((m) => m !== module) : [...current, module]
    )
  }

  const runUpdate = (payload: { is_active?: boolean; permissions?: string[] }) => {
    setRowError(null)
    update.mutate(
      { id: account.id, ...payload },
      { onError: (error) => setRowError(getGenericErrorMessage(error)) }
    )
  }

  return (
    <tr className="border-b">
      <td className="p-2">{account.name}</td>
      <td className="p-2">{account.email}</td>
      <td className="p-2">{account.is_active ? 'Active' : 'Disabled'}</td>
      <td className="p-2">
        <div className="flex flex-wrap gap-2">
          {MODULES.map((module) => (
            <label key={module} className="flex items-center gap-1 text-sm">
              <input
                type="checkbox"
                checked={permissions.includes(module)}
                onChange={() => toggleModule(module)}
              />
              {module}
            </label>
          ))}
        </div>
      </td>
      <td className="p-2">
        <div className="flex gap-2">
          <Button
            size="sm"
            variant="outline"
            disabled={update.isPending}
            onClick={() => runUpdate({ permissions })}
          >
            Save permissions
          </Button>
          <Button
            size="sm"
            variant={account.is_active ? 'destructive' : 'default'}
            disabled={update.isPending}
            onClick={() => runUpdate({ is_active: !account.is_active })}
          >
            {account.is_active ? 'Disable' : 'Enable'}
          </Button>
        </div>
        {rowError && <p className="mt-1 text-sm text-destructive">{rowError}</p>}
      </td>
    </tr>
  )
}

export function AdminAccountsPage() {
  const { data: accounts, isLoading } = useAdminAccounts()
  const createAdmin = useCreateAdminAccount()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
    setError,
  } = useForm<CreateAdminForm>({ resolver: zodResolver(createAdminSchema) })

  const onSubmit = handleSubmit((values) => {
    setFormError(null)
    createAdmin.mutate(values, {
      onSuccess: () => reset(),
      onError: (error) => {
        if (isAxiosError(error) && error.response?.status === 422) {
          const fieldErrors = error.response.data?.errors as
            | Record<string, string[]>
            | undefined
          for (const [name, messages] of Object.entries(fieldErrors ?? {})) {
            setError(name as keyof CreateAdminForm, { message: messages[0] })
          }
          return
        }
        setFormError(getGenericErrorMessage(error))
      },
    })
  })

  return (
    <div className="flex flex-col gap-8">
      <div>
        <h1 className="mb-4 text-xl font-semibold">Admin accounts</h1>
        {isLoading && <p>Loading…</p>}
        {accounts && (
          <table className="w-full border-collapse text-left">
            <thead>
              <tr className="border-b">
                <th className="p-2">Name</th>
                <th className="p-2">Email</th>
                <th className="p-2">Status</th>
                <th className="p-2">Permissions</th>
                <th className="p-2">Actions</th>
              </tr>
            </thead>
            <tbody>
              {accounts.map((account) => (
                <AdminAccountRow key={account.id} account={account} />
              ))}
            </tbody>
          </table>
        )}
      </div>

      <form onSubmit={onSubmit} className="flex max-w-sm flex-col gap-4">
        <h2 className="font-semibold">Create admin account</h2>

        {formError && <p className="text-sm text-destructive">{formError}</p>}

        <div className="flex flex-col gap-1">
          <Label htmlFor="name">Name</Label>
          <Input id="name" {...register('name')} />
          {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
        </div>

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

        <Button type="submit" disabled={createAdmin.isPending}>
          {createAdmin.isPending ? 'Creating…' : 'Create admin'}
        </Button>
      </form>
    </div>
  )
}
