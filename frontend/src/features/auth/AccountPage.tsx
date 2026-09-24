import { Button } from '@/components/ui/button'
import { useLogout, useMe } from './hooks'

export function AccountPage() {
  const { data: me } = useMe()
  const logout = useLogout()

  return (
    <div className="flex flex-col gap-4">
      <p>Signed in as {me?.name}</p>
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
