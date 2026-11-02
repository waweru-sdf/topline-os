import { useState } from 'react'
import { useRevalidator } from 'react-router'
import { CircleCheck, Clock, TriangleAlert } from 'lucide-react'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { PhotoUpload } from '@/components/PhotoUpload'
import { api } from '@/services/api'
import { cn } from '@/lib/cn'
import { useSupplier } from './SupplierLayout'

function toForm(p) {
  return {
    name: p.name, logo: p.logo ?? '', description: p.description, categories: p.categories.join(', '), location: p.location,
    phone: p.phone, email: p.email ?? '', website: p.website ?? '', minOrder: p.minOrder, deliveryInfo: p.deliveryInfo,
    paymentOptions: p.paymentOptions.map((o) => o.id), paymentInstructions: p.paymentInstructions,
  }
}

function Profile() {
  const { me } = useSupplier()
  const revalidator = useRevalidator()
  const [form, setForm] = useState(() => toForm(me.draft ?? me.live))
  const [error, setError] = useState(null)
  const [saved, setSaved] = useState(false)
  const [busy, setBusy] = useState(false)
  const update = (patch) => { setSaved(false); setForm((f) => ({ ...f, ...patch })) }
  const err = (f) => (error?.field === f ? error.message : undefined)
  const toggle = (id) => update({ paymentOptions: form.paymentOptions.includes(id) ? form.paymentOptions.filter((x) => x !== id) : [...form.paymentOptions, id] })

  async function save(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.updateSupplierProfile(form)
      setSaved(true)
      revalidator.revalidate()
    } catch (err2) {
      setError({ message: err2.message, field: err2.field })
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <title>Company profile — Topline supplier</title>
      <PageHeader title="Company profile">This is what hardware shops see. Your logo, name, description and number of shops you work with also appear on Topline’s home page.</PageHeader>

      <ReviewBanner me={me} saved={saved} />

      <form onSubmit={save} noValidate className="grid gap-6 rounded-card bg-white p-6 ring-1 ring-line lg:grid-cols-[16rem_1fr]">
        <div>
          <PhotoUpload label="Logo" value={form.logo} onChange={(url) => update({ logo: url })} aspect="aspect-square" />
          {err('logo') && <p className="mt-1 text-sm text-bad">{err('logo')}</p>}
        </div>
        <div className="space-y-4">
          <Field label="Company name" value={form.name} onChange={(e) => update({ name: e.target.value })} error={err('name')} required />
          <Field as="textarea" label="What you supply" value={form.description} onChange={(e) => update({ description: e.target.value })} error={err('description')} maxLength={1000}
            hint="A few sentences: your main products, where you deliver, what makes you a good partner." required />
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Categories" value={form.categories} onChange={(e) => update({ categories: e.target.value })} error={err('categories')} hint="Separate with commas: Cement, Steel" />
            <Field label="Location" value={form.location} onChange={(e) => update({ location: e.target.value })} error={err('location')} placeholder="Town, or country" />
            <Field label="Phone for shops" type="tel" value={form.phone} onChange={(e) => update({ phone: e.target.value })} error={err('phone')} required />
            <Field label="Email (optional)" type="email" value={form.email} onChange={(e) => update({ email: e.target.value })} error={err('email')} />
            <Field label="Website (optional)" value={form.website} onChange={(e) => update({ website: e.target.value })} error={err('website')} placeholder="https://" />
            <Field label="Minimum order (optional)" value={form.minOrder} onChange={(e) => update({ minOrder: e.target.value })} error={err('minOrder')} placeholder="e.g. 100 bags" />
          </div>
          <Field label="Delivery (optional)" value={form.deliveryInfo} onChange={(e) => update({ deliveryInfo: e.target.value })} error={err('deliveryInfo')} placeholder="Where you deliver and how long it takes" />
          <fieldset>
            <legend className="text-sm font-medium">How shops can pay you</legend>
            <div className="mt-1.5 grid gap-2 sm:grid-cols-2">
              {me.paymentOptionChoices.map((o) => (
                <label key={o.id} className={cn('flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2.5 text-sm', form.paymentOptions.includes(o.id) ? 'border-navy bg-mist/40' : 'border-line')}>
                  <input type="checkbox" checked={form.paymentOptions.includes(o.id)} onChange={() => toggle(o.id)} className="size-4 accent-navy" />
                  {o.label}
                </label>
              ))}
            </div>
            {err('paymentOptions') && <p className="mt-1 text-sm text-bad">{err('paymentOptions')}</p>}
          </fieldset>
          <Field as="textarea" label="Payment details for shops (optional)" value={form.paymentInstructions} onChange={(e) => update({ paymentInstructions: e.target.value })} error={err('paymentInstructions')} maxLength={1000}
            hint="Shown only to logged-in shops when they order, e.g. your Paybill, or that you send an invoice first." />
          {error && !error.field && <p className="text-sm text-bad" role="alert">{error.message}</p>}
          <div className="flex items-center gap-3">
            <Button type="submit" variant="navy" pending={busy}>Send for approval</Button>
            {saved && <span className="flex items-center gap-1.5 text-sm text-good"><CircleCheck className="size-4" />Sent. Topline will review it.</span>}
          </div>
        </div>
      </form>
    </>
  )
}

function ReviewBanner({ me, saved }) {
  if (saved) return null
  if (me.reviewStatus === 'rejected') {
    return (
      <p className="mb-4 flex gap-2 rounded-lg bg-bad-tint px-4 py-3 text-sm text-bad">
        <TriangleAlert className="size-5 shrink-0" />Topline asked for changes{me.reviewNote ? `: “${me.reviewNote}”` : '.'} Update the profile and send it again.
      </p>
    )
  }
  if (me.reviewStatus === 'pending') {
    return (
      <p className="mb-4 flex gap-2 rounded-lg bg-amber-tint px-4 py-3 text-sm text-[#7a4f00]">
        <Clock className="size-5 shrink-0" />
        {me.approvedAt ? 'Your latest changes are waiting for approval. Shops see the approved version until then.' : 'Your profile is waiting for approval. Shops will see you once Topline approves it.'}
      </p>
    )
  }
  if (!me.approvedAt) {
    return <p className="mb-4 rounded-lg bg-concrete px-4 py-3 text-sm">Fill in your profile and send it for approval. Topline checks every supplier before shops can see them.</p>
  }
  return null
}

export { Profile as Component }
