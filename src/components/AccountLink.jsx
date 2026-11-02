import { getUser } from '@/services/session'
import { Button } from '@/components/ui/Button'

/** "Log in" for visitors; a shortcut back to their admin for anyone already logged in. */
export function AccountLink({ variant = 'ghost', className }) {
  const user = getUser()
  if (user?.role === 'platform_admin') return <Button to="/admin" variant={variant} className={className}>Topline admin</Button>
  if (user?.role === 'vendor') return <Button to="/dashboard" variant={variant} className={className}>My shop admin</Button>
  return <Button to="/login" variant={variant} className={className}>Log in</Button>
}
