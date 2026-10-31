import { useActionState } from 'react'
import { redirect, useLoaderData, useNavigate } from 'react-router'
import { api } from '@/services/api'
import { getUser, setSession } from '@/services/session'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Logo } from '@/components/ui/Logo'
import { PasswordField } from '@/components/ui/PasswordField'

export const homeFor = (user) =>
  user.mustChangePassword ? '/change-password' : user.role === 'platform_admin' ? '/admin' : '/dashboard'

export async function loader() {
  const user = getUser()
  if (user) return redirect(homeFor(user))
  return { site: await api.getSite().catch(() => null) }
}

function Login() {
  const { site } = useLoaderData()
  const navigate = useNavigate()
  const [state, logIn, pending] = useActionState(async (_prev, fd) => {
    try {
      const { user, token } = await api.login(fd.get('login'), fd.get('password'))
      setSession({ user, token })
      navigate(homeFor(user), { replace: true })
      return null
    } catch (e) {
      return { error: e.message, field: e.field, login: fd.get('login') }
    }
  }, null)

  return (
    <main className="flex min-h-dvh items-center justify-center bg-concrete px-5 py-10">
      <title>Log in — Topline</title>
      <div className="w-full max-w-sm">
        <Logo className="mb-8" />
        <form action={logIn} className="rounded-card bg-white p-7 shadow-lift">
          <h1 className="text-2xl font-bold text-navy">Log in</h1>
          <p className="mt-1 text-sm text-muted">For shop owners, their staff and the Topline team.</p>
          <Field
            className="mt-6"
            name="login"
            label="Phone number or email"
            placeholder="0712 345 678"
            autoComplete="username"
            autoCapitalize="none"
            defaultValue={state?.login ?? ''}
            error={state?.field === 'login' ? state.error : undefined}
            required
          />
          <PasswordField
            className="mt-4"
            name="password"
            label="Password"
            autoComplete="current-password"
            error={state?.field !== 'login' ? state?.error : undefined}
            required
          />
          <Button type="submit" variant="navy" className="mt-6 w-full" pending={pending}>Log in</Button>
          <p className="mt-4 text-sm text-muted">
            Forgot your password? {site?.supportWhatsapp ? (
              <a href={`https://wa.me/${site.supportWhatsapp}`} className="font-medium text-navy underline underline-offset-4">WhatsApp Topline</a>
            ) : 'Contact Topline'}
            {site?.supportPhone ? ` (${site.supportPhone})` : ''} and we’ll give you a temporary password.
          </p>
        </form>
        <p className="mt-6 text-center text-sm text-muted">
          New to Topline? <a href="/start" className="font-medium text-navy underline underline-offset-4">Open your store</a>
        </p>
      </div>
    </main>
  )
}

export { Login as Component }
