import { useActionState, useState } from 'react'
import { redirect, useNavigate } from 'react-router'
import { ArrowLeft } from 'lucide-react'
import { api } from '@/services/api'
import { getUser, setSession } from '@/services/session'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Logo } from '@/components/ui/Logo'

const homeFor = (user) => (user.role === 'platform_admin' ? '/admin' : '/dashboard')

export function loader() {
  const user = getUser()
  return user ? redirect(homeFor(user)) : null
}

function Login() {
  const navigate = useNavigate()
  const [phone, setPhone] = useState(null)
  const [devCode, setDevCode] = useState(null)

  const [sendState, sendAction, sending] = useActionState(async (_p, fd) => {
    try {
      const res = await api.requestOtp(fd.get('phone'))
      setDevCode(res?.devCode ?? null)
      setPhone(fd.get('phone'))
      return null
    } catch (e) {
      return { error: e.message }
    }
  }, null)

  const [verifyState, verifyAction, verifying] = useActionState(async (_p, fd) => {
    try {
      const { user, token } = await api.verifyOtp(phone, fd.get('code'))
      setSession({ user, token })
      navigate(homeFor(user), { replace: true })
      return null
    } catch (e) {
      return { error: e.message, field: e.field }
    }
  }, null)

  return (
    <main className="flex min-h-dvh items-center justify-center bg-concrete px-5 py-10">
      <title>Log in — Topline</title>
      <div className="w-full max-w-sm">
        <Logo className="mb-8" />
        <div className="rounded-card bg-white p-7 shadow-lift">
          {!phone ? (
            <form action={sendAction}>
              <h1 className="text-2xl font-bold text-navy">Log in</h1>
              <p className="mt-1 text-sm text-muted">We’ll text a 6-digit code to your phone.</p>
              <Field className="mt-6" name="phone" label="Mobile number" type="tel" inputMode="tel" placeholder="0712 345 678" autoComplete="tel" error={sendState?.error} required />
              <Button type="submit" variant="navy" className="mt-6 w-full" pending={sending}>Send code</Button>
            </form>
          ) : (
            <form action={verifyAction}>
              <button type="button" onClick={() => setPhone(null)} className="mb-4 flex items-center gap-1 text-sm text-muted hover:text-ink">
                <ArrowLeft className="size-4" /> Change number
              </button>
              <h1 className="text-2xl font-bold text-navy">Enter your code</h1>
              <p className="mt-1 text-sm text-muted">Sent to {phone}.</p>
              {devCode && (
                <p className="mt-3 rounded-lg bg-amber-tint px-3 py-2 text-sm text-[#7a4f00]">
                  Test mode, no SMS sent. Your code is <span className="font-bold tabular">{devCode}</span>.
                </p>
              )}
              <Field
                className="mt-6"
                name="code"
                label="6-digit code"
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={6}
                pattern="\d{6}"
                error={verifyState?.error}
                required
                autoFocus
              />
              <Button type="submit" variant="navy" className="mt-6 w-full" pending={verifying}>Log in</Button>
            </form>
          )}
        </div>
        <p className="mt-6 text-center text-sm text-muted">
          New to Topline? <a href="/start" className="font-medium text-navy underline underline-offset-4">Open your store</a>
        </p>
      </div>
    </main>
  )
}

export { Login as Component }
