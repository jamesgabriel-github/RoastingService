import { zodResolver } from '@hookform/resolvers/zod'
import { isAxiosError } from 'axios'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { cn } from 'cn'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { getGenericErrorMessage } from '@/lib/errors'
import { useAdminAccounts, useCreateAdminAccount, useUpdateAdminAccount } from './hooks'
import { MODULES, type AdminAccount } from './types'

const accountSchema = z.object({
  name: z.string().min(1, 'Name is required'),
  email: z.string().email('Enter a valid email'),
  password: z.string().min(8, 'Password must be at least 8 characters').optional().or(z.literal('')),
  permissions: z.array(z.string()),
})

type AccountFormValues = z.infer<typeof accountSchema>
type ModalMode = 'create' | 'view' | 'edit'

const emptyValues: AccountFormValues = {
  name: '',
  email: '',
  password: '',
  permissions: [],
}

function toFormValues(account: AdminAccount): AccountFormValues {
  return {
    name: account.name,
    email: account.email,
    password: '',
    permissions: account.permissions,
  }
}

function StatusPill({ isActive }: { isActive: boolean }) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
        isActive ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'
      )}
    >
      {isActive ? 'Active' : 'Disabled'}
    </span>
  )
}

function AccountRow({
  account,
  onView,
  onEdit,
}: {
  account: AdminAccount
  onView: (account: AdminAccount) => void
  onEdit: (account: AdminAccount) => void
}) {
  const [rowError, setRowError] = useState<string | null>(null)
  const update = useUpdateAdminAccount()

  return (
    <tr className="border-b">
      <td className="p-2">{account.name}</td>
      <td className="p-2">{account.email}</td>
      <td className="p-2">
        <StatusPill isActive={account.is_active} />
      </td>
      <td className="p-2">{account.permissions.length ? account.permissions.join(', ') : '—'}</td>
      <td className="p-2">
        <div className="flex gap-2">
          <Button size="sm" variant="outline" onClick={() => onView(account)}>
            View
          </Button>
          <Button size="sm" variant="outline" onClick={() => onEdit(account)}>
            Edit
          </Button>
          <Button
            size="sm"
            variant={account.is_active ? 'destructive' : 'default'}
            disabled={update.isPending}
            onClick={() => {
              setRowError(null)
              update.mutate(
                { id: account.id, is_active: !account.is_active },
                { onError: (error) => setRowError(getGenericErrorMessage(error)) }
              )
            }}
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
  const createAccount = useCreateAdminAccount()
  const updateAccount = useUpdateAdminAccount()
  const [isOpen, setIsOpen] = useState(false)
  const [mode, setMode] = useState<ModalMode>('create')
  const [activeAccount, setActiveAccount] = useState<AdminAccount | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [successMessage, setSuccessMessage] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    reset,
    watch,
    setValue,
    formState: { errors },
    setError,
  } = useForm<AccountFormValues>({
    resolver: zodResolver(accountSchema),
    defaultValues: emptyValues,
  })

  const permissions = watch('permissions')
  const isSaving = createAccount.isPending || updateAccount.isPending
  const isViewing = mode === 'view'

  const filteredAccounts = useMemo(() => {
    const query = search.trim().toLowerCase()
    if (!query) return accounts ?? []
    return (accounts ?? []).filter(
      (account) => account.name.toLowerCase().includes(query) || account.email.toLowerCase().includes(query)
    )
  }, [accounts, search])

  const showSuccess = (message: string) => {
    setSuccessMessage(message)
    setTimeout(() => setSuccessMessage(null), 3000)
  }

  const toggleModule = (module: string) => {
    setValue(
      'permissions',
      permissions.includes(module) ? permissions.filter((m) => m !== module) : [...permissions, module]
    )
  }

  const openCreate = () => {
    setActiveAccount(null)
    setMode('create')
    setFormError(null)
    reset(emptyValues)
    setIsOpen(true)
  }

  const openView = (account: AdminAccount) => {
    setActiveAccount(account)
    setMode('view')
    setFormError(null)
    reset(toFormValues(account))
    setIsOpen(true)
  }

  const openEdit = (account: AdminAccount) => {
    setActiveAccount(account)
    setMode('edit')
    setFormError(null)
    reset(toFormValues(account))
    setIsOpen(true)
  }

  const switchToEdit = () => setMode('edit')

  const closeModal = () => {
    setIsOpen(false)
    setActiveAccount(null)
    setMode('create')
    setFormError(null)
    reset(emptyValues)
  }

  const onSubmit = handleSubmit((values) => {
    setFormError(null)

    const onError = (error: unknown) => {
      if (isAxiosError(error) && error.response?.status === 422) {
        const fieldErrors = error.response.data?.errors as Record<string, string[]> | undefined
        for (const [name, messages] of Object.entries(fieldErrors ?? {})) {
          setError(name as keyof AccountFormValues, { message: messages[0] })
        }
        return
      }
      setFormError(getGenericErrorMessage(error))
    }

    if (activeAccount) {
      updateAccount.mutate(
        { id: activeAccount.id, permissions: values.permissions },
        {
          onSuccess: () => {
            closeModal()
            showSuccess('Account updated.')
          },
          onError,
        }
      )
      return
    }

    createAccount.mutate(
      {
        name: values.name,
        email: values.email,
        password: values.password ?? '',
        permissions: values.permissions,
      },
      {
        onSuccess: () => {
          closeModal()
          showSuccess('Account created.')
        },
        onError,
      }
    )
  })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-semibold">Admin accounts</h1>
        <Button onClick={openCreate}>New Account</Button>
      </div>

      {successMessage && (
        <div className="rounded-md bg-primary/10 px-3 py-2 text-sm text-primary">{successMessage}</div>
      )}

      <Input
        placeholder="Search accounts…"
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        className="max-w-xs"
      />

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
            {filteredAccounts.map((account) => (
              <AccountRow key={account.id} account={account} onView={openView} onEdit={openEdit} />
            ))}
          </tbody>
        </table>
      )}

      <Dialog open={isOpen} onOpenChange={(open) => !open && closeModal()}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {mode === 'create' ? 'Create Admin Account' : mode === 'view' ? activeAccount?.name : 'Edit Account'}
            </DialogTitle>
            <DialogDescription>
              {mode === 'view'
                ? 'Account details'
                : mode === 'edit'
                  ? 'Update account permissions'
                  : 'Add a new admin account'}
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={onSubmit} className="flex flex-col gap-4">
            {formError && <p className="text-sm text-destructive">{formError}</p>}

            <div className="flex flex-col gap-1">
              <Label htmlFor="name">Name</Label>
              <Input id="name" disabled={mode !== 'create'} {...register('name')} />
              {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
            </div>

            <div className="flex flex-col gap-1">
              <Label htmlFor="email">Email</Label>
              <Input id="email" type="email" disabled={mode !== 'create'} {...register('email')} />
              {errors.email && <p className="text-sm text-destructive">{errors.email.message}</p>}
            </div>

            {mode === 'create' && (
              <div className="flex flex-col gap-1">
                <Label htmlFor="password">Password</Label>
                <Input id="password" type="password" {...register('password')} />
                {errors.password && <p className="text-sm text-destructive">{errors.password.message}</p>}
              </div>
            )}

            <div className="flex flex-col gap-1">
              <Label>Permissions</Label>
              <div className="flex flex-wrap gap-3">
                {MODULES.map((module) => (
                  <label key={module} className="flex items-center gap-1 text-sm">
                    <input
                      type="checkbox"
                      disabled={isViewing}
                      checked={permissions.includes(module)}
                      onChange={() => toggleModule(module)}
                    />
                    {module}
                  </label>
                ))}
              </div>
            </div>

            {mode !== 'create' && activeAccount && (
              <div className="grid grid-cols-2 gap-2 rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                <span>Status: {activeAccount.is_active ? 'Active' : 'Disabled'}</span>
              </div>
            )}

            <DialogFooter>
              {mode === 'view' ? (
                <>
                  <DialogClose render={<Button type="button" variant="outline" />}>Close</DialogClose>
                  <Button type="button" onClick={switchToEdit}>
                    Switch to Edit
                  </Button>
                </>
              ) : (
                <>
                  <DialogClose render={<Button type="button" variant="outline" />}>Cancel</DialogClose>
                  <Button type="submit" disabled={isSaving}>
                    {isSaving ? 'Saving…' : mode === 'edit' ? 'Save changes' : 'Create account'}
                  </Button>
                </>
              )}
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  )
}
