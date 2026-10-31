import { useActionState } from 'react'
import { redirect, useNavigate } from 'react-router'
import { ArrowLeft } from 'lucide-react'
import { api } from '@/services/api'
import { clearSession, getSession, getUser, setSession } from '@/services/session'
import { Button } from '@/components/ui/Button'
import { Logo } from '@/components/ui/Logo'
import { PasswordField } from '@/components/ui/PasswordField'

export function loader() {
  return getUser() ? null : redirect('/login')
}

/** Used after an admin reset (forced) and from Settings (voluntary). */
function ChangePassword() {
  const navigate = useNavigate()
  const user = getUser()
  const forced = Boolean(user?.mustChangePassword)
  const home = user?.role === 'platform_admin' ? '/admin' : '/dashboard'

  const [state, save, pending] = useActionState(async (_prev, fd) => {
    const next = fd.get('newPassword')
    if (next !== fd.get('confirm')) return { field: 'confirm', error: 'The two passwords don’t match.' }
    try {
      const { user: updated, token } = await api.changePassword(fd.get('currentPassword') ?? '', next)
      setSession({ ...getSession(), user: updated, token })
      navigate(home, { replace: true })
      return null
    } catch (e) {
      return { field: e.field, error: e.message }
    }
  }, null)
  const err = (f) => (state?.field === f ? state.error : undefined)

  return (
    <main className="flex min-h-dvh items-center justify-center bg-concrete px-5 py-10">
      <title>Change password — Topline</title>
      <div className="w-full max-w-sm">
        <Logo className="mb-8" />
        <form action={save} className="rounded-card bg-white p-7 shadow-lift">
          {!forced && (
            <button type="button" onClick={() => navigate(-1)} className="mb-4 flex items-center gap-1 text-sm text-muted hover:text-ink">
              <ArrowLeft className="size-4" /> Back
            </button>
          )}
          <h1 className="text-2xl font-bold text-navy">{forced ? 'Choose your own password' : 'Change password'}</h1>
          <p className="mt-1 text-sm text-muted">
            {forced
              ? 'You logged in with a temporary password from Topline. Pick a new one only you know.'
              : 'Other phones and computers logged in to your account will be signed out.'}
          </p>
          {!forced && (
            <PasswordField className="mt-6" name="currentPassword" label="Current password" autoComplete="current-password" error={err('currentPassword')} required />
          )}
          <PasswordField className={forced ? 'mt-6' : 'mt-4'} name="newPassword" label="New password" autoComplete="new-password" minLength={8} hint="At least 8 characters." error={err('newPassword')} required />
          <PasswordField className="mt-4" name="confirm" label="Type it again" autoComplete="new-password" error={err('confirm')} required />
          {state?.error && !['currentPassword', 'newPassword', 'confirm'].includes(state.field) && (
            <p className="mt-4 text-sm text-bad" role="alert">{state.error}</p>
          )}
          <Button type="submit" variant="navy" className="mt-6 w-full" pending={pending}>Save new password</Button>
          {forced && (
            <button type="button" onClick={() => { clearSession(); window.location.assign('/login') }} className="mt-4 w-full text-center text-sm text-muted hover:text-ink">
              Log out
            </button>
          )}
        </form>
      </div>
    </main>
  )
}

export { ChangePassword as Component }
