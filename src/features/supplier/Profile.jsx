import { useState } from 'react'
import { useLoaderData, useRevalidator } from 'react-router'
import { CircleCheck, EyeOff, PartyPopper } from 'lucide-react'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { PhotoUpload } from '@/components/PhotoUpload'
import { api } from '@/services/api'
import { SUPPLIER_PAYMENT_OPTIONS } from '@/lib/labels'
import { cn } from '@/lib/cn'
import { requireSupplier } from '@/features/vendor/guard'
import { useSupplier } from './SupplierLayout'
import { ProductList } from './ProductList'

export async function loader() {
  requireSupplier()
  return { products: await api.listOwnProducts() }
}

/** Removing a product (the edit dialog saves directly). */
export async function action({ request }) {
  const fd = await request.formData()
  try {
    await api.deleteOwnProduct(fd.get('id'))
    return { ok: true }
  } catch (e) {
    return { error: e.message }
  }
}

function toForm(p) {
  return {
    name: p.name, logo: p.logo ?? '', description: p.description, categories: p.categories.join(', '), location: p.location,
    phone: p.phone, email: p.email ?? '', website: p.website ?? '', minOrder: p.minOrder, deliveryInfo: p.deliveryInfo,
    paymentOptions: p.paymentOptions.map((o) => o.id), paymentInstructions: p.paymentInstructions,
  }
}

function Profile() {
  const { me } = useSupplier()
  const { products } = useLoaderData()
  const revalidator = useRevalidator()
  const [form, setForm] = useState(() => toForm(me.profile))
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
      <title>Profile — Topline supplier</title>
      <PageHeader title="Profile & products">What hardware shops see. Your logo, name and description also appear on Topline’s home page. Changes show straight away.</PageHeader>

      {me.status === 'hidden' && (
        <p className="mb-4 flex gap-2 rounded-lg bg-bad-tint px-4 py-3 text-sm text-bad"><EyeOff className="size-5 shrink-0" />Topline has hidden your profile from shops for now. Contact Topline to find out why.</p>
      )}
      {me.status === 'approved' && me.productCount === 0 && (
        <p className="mb-4 flex gap-2 rounded-lg bg-amber-tint px-4 py-3 text-sm text-[#7a4f00]">
          <PartyPopper className="size-5 shrink-0" />You’re approved and listed. Add your logo and products with prices below, so shops know what to order.
        </p>
      )}

      <form onSubmit={save} noValidate className="grid gap-6 rounded-card bg-white p-6 ring-1 ring-line lg:grid-cols-[16rem_minmax(0,1fr)]">
        <div>
          <PhotoUpload label="Logo (optional)" value={form.logo} onChange={(url) => update({ logo: url })} aspect="aspect-square" />
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
              {SUPPLIER_PAYMENT_OPTIONS.map(([id, label]) => (
                <label key={id} className={cn('flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2.5 text-sm', form.paymentOptions.includes(id) ? 'border-navy bg-mist/40' : 'border-line')}>
                  <input type="checkbox" checked={form.paymentOptions.includes(id)} onChange={() => toggle(id)} className="size-4 accent-navy" />
                  {label}
                </label>
              ))}
            </div>
            {err('paymentOptions') && <p className="mt-1 text-sm text-bad">{err('paymentOptions')}</p>}
          </fieldset>
          <Field as="textarea" label="Payment details for shops (optional)" value={form.paymentInstructions} onChange={(e) => update({ paymentInstructions: e.target.value })} error={err('paymentInstructions')} maxLength={1000}
            hint="Shown only to logged-in shops when they order, e.g. your Paybill, or that you send an invoice first." />
          {error && !error.field && <p className="text-sm text-bad" role="alert">{error.message}</p>}
          <div className="flex flex-wrap items-center gap-3">
            <Button type="submit" variant="navy" pending={busy}>Save profile</Button>
            {saved && <span className="flex items-center gap-1.5 text-sm text-good" role="status"><CircleCheck className="size-4" />Saved. Shops see it now.</span>}
          </div>
        </div>
      </form>

      <ProductList products={products} />
    </>
  )
}

export { Profile as Component }
