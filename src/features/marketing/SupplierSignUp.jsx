import { useState } from 'react'
import { Link } from 'react-router'
import { CircleCheck } from 'lucide-react'
import { api } from '@/services/api'
import { SUPPLIER_PAYMENT_OPTIONS } from '@/lib/labels'
import { cn } from '@/lib/cn'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { NewPasswordField } from '@/components/ui/NewPasswordField'
import { passwordProblem } from '@/lib/password'

const EMPTY = { name: '', contactName: '', phone: '', email: '', location: '', categories: '', description: '', paymentOptions: [], password: '' }

/** /suppliers/join: a manufacturer or distributor signs up. They can log in once Topline approves them. */
function SupplierSignUp() {
  const [form, setForm] = useState(EMPTY)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(null)
  const update = (patch) => setForm((f) => ({ ...f, ...patch }))
  const err = (f) => (error?.field === f ? error.message : undefined)
  const toggle = (id) => update({ paymentOptions: form.paymentOptions.includes(id) ? form.paymentOptions.filter((x) => x !== id) : [...form.paymentOptions, id] })

  async function submit(e) {
    e.preventDefault()
    const problem = passwordProblem(form.password, form.phone)
    if (problem) return setError({ message: problem, field: 'password' })
    setBusy(true)
    setError(null)
    try {
      setDone(await api.supplierSignUp(form))
      window.scrollTo(0, 0)
    } catch (e2) {
      setError({ message: e2.message, field: e2.field })
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="bg-concrete">
      <title>Sign up as a supplier — Topline</title>
      <div className="mx-auto max-w-2xl px-5 py-12 md:py-16">
        {done ? (
          <div className="rounded-card bg-white p-7 text-center shadow-lift" role="status">
            <CircleCheck className="mx-auto size-12 text-good" />
            <h1 className="mt-4 text-2xl font-bold text-navy">Thanks, {done.name} is signed up</h1>
            <p className="mt-3 leading-relaxed text-muted">
              Topline will check your details and send an SMS to {done.phone} once you’re approved. Then log in with that number and your password
              to add your logo, products and prices. Hardware shops can order from you straight away.
            </p>
            <Button to="/" variant="navy" className="mt-6">Back to the home page</Button>
          </div>
        ) : (
          <>
            <h1 className="text-3xl font-bold tracking-tight text-navy">Sell to hardware shops across Kenya</h1>
            <p className="mt-3 leading-relaxed text-muted">
              Sign up as a manufacturer or distributor. Once Topline approves you, your logo and description appear on our home page,
              every shop on Topline sees your products and prices, and their orders come to you by SMS and in your supplier account.
              You agree delivery and payment with each shop directly.
            </p>
            <form onSubmit={submit} noValidate className="mt-8 space-y-5 rounded-card bg-white p-6 shadow-lift sm:p-7">
              <Field label="Company name" value={form.name} onChange={(e) => update({ name: e.target.value })} error={err('name')} autoComplete="organization" required />
              <div className="grid gap-5 sm:grid-cols-2">
                <Field label="Your name" value={form.contactName} onChange={(e) => update({ contactName: e.target.value })} error={err('contactName')} autoComplete="name" required />
                <Field label="Mobile number" type="tel" value={form.phone} onChange={(e) => update({ phone: e.target.value })} error={err('phone')} placeholder="0712 345 678"
                  hint="You log in with it, and shops call it." autoComplete="tel" required />
                <Field label="Email (optional)" type="email" value={form.email} onChange={(e) => update({ email: e.target.value })} error={err('email')} autoComplete="email" />
                <Field label="Location (optional)" value={form.location} onChange={(e) => update({ location: e.target.value })} error={err('location')} placeholder="Town, or country" />
              </div>
              <Field as="textarea" label="What you supply" value={form.description} onChange={(e) => update({ description: e.target.value })} error={err('description')} maxLength={1000}
                hint="Two or three sentences: your main products and where you deliver. Shown on Topline’s home page." required />
              <Field label="Categories (optional)" value={form.categories} onChange={(e) => update({ categories: e.target.value })} error={err('categories')} hint="Separate with commas: Cement, Steel" />
              <fieldset>
                <legend className="text-sm font-medium">How shops can pay you</legend>
                <div className="mt-1.5 grid gap-2 sm:grid-cols-2">
                  {SUPPLIER_PAYMENT_OPTIONS.map(([id, label]) => (
                    <label key={id} className={cn('flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2.5 text-sm', form.paymentOptions.includes(id) ? 'border-navy bg-mist/40' : 'border-line')}>
                      <input type="checkbox" checked={form.paymentOptions.includes(id)} onChange={() => toggle(id)} className="size-4 accent-navy" />
                      {label}
                    </label>
                  ))}
                </div>
                {err('paymentOptions') && <p className="mt-1 text-sm text-bad">{err('paymentOptions')}</p>}
              </fieldset>
              <NewPasswordField label="Choose a password" value={form.password} phone={form.phone} onChange={(e) => update({ password: e.target.value })} error={err('password')} required />
              {error && !['name', 'contactName', 'phone', 'email', 'location', 'description', 'categories', 'paymentOptions', 'password'].includes(error.field) && (
                <p className="text-sm text-bad" role="alert">{error.message}</p>
              )}
              <Button type="submit" variant="navy" className="w-full" pending={busy}>Sign up</Button>
              <p className="text-center text-sm text-muted">Already approved? <Link to="/login" className="font-medium text-navy underline underline-offset-4">Log in</Link></p>
            </form>
          </>
        )}
      </div>
    </section>
  )
}

export { SupplierSignUp as Component }
