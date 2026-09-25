import { Button } from '@/components/ui/button'
import { useLogout, useMe } from './hooks'

export function AccountPage() {
  const { data: me } = useMe()
  const logout = useLogout()

  const displayName =
    me?.role === 'customer' ? [me.first_name, me.last_name].filter(Boolean).join(' ') : me?.name

  return (
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
  )
}
