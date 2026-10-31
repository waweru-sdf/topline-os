import { useState } from 'react'
import { useRevalidator } from 'react-router'
import { CircleCheck, Plus, Trash2 } from 'lucide-react'
import { api } from '@/services/api'
import { COUNTIES } from '@/lib/counties'
import { storeHost } from '@/lib/tenant'
import { cn } from '@/lib/cn'
import { displayPhone, formatKES } from '@/lib/format'
import { PAYOUT_METHODS, payoutMethod } from '@/lib/payments'
import { PageHeader } from '@/components/layout/AppShell'
import { PhotoUpload } from '@/components/PhotoUpload'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { useVendor } from './VendorLayout'

const ACCENTS = ['#F5A623', '#E4572E', '#2E9E5B', '#1E88E5', '#8E44AD', '#0B3A6E']

function Settings() {
  const { store } = useVendor()
  const revalidator = useRevalidator()
  const [form, setForm] = useState(() => ({
    name: store.name, tagline: store.tagline, county: store.county, town: store.town, address: store.address,
    phone: store.phone, whatsapp: displayPhone(store.whatsapp), hours: store.hours, accent: store.accent,
    logo: store.logo, cover: store.cover,
    payments: {
      method: store.payments.method,
      number: ['pochi', 'send_money'].includes(store.payments.method) ? displayPhone(store.payments.number) : store.payments.number,
      account: store.payments.account, name: store.payments.name, acceptsDirect: store.payments.acceptsDirect || !store.payments.canReceive,
    },
    delivery: { enabled: store.delivery.enabled, mode: store.delivery.mode, zones: store.delivery.zones, freeOver: store.delivery.freeOver ?? '', note: store.delivery.note },
  }))
  const [status, setStatus] = useState(null) // { saving } | { saved } | { error, field }
  const set = (patch) => setForm((f) => ({ ...f, ...patch }))
  const setPay = (patch) => setForm((f) => ({ ...f, payments: { ...f.payments, ...patch } }))
  const setDel = (patch) => setForm((f) => ({ ...f, delivery: { ...f.delivery, ...patch } }))
  const err = (field) => (status?.field === field ? status.error : undefined)

  async function save(e) {
    e.preventDefault()
    setStatus({ saving: true })
    try {
      await api.updateVendorStore(store.id, { ...form, delivery: { ...form.delivery, freeOver: form.delivery.freeOver || null } })
      setStatus({ saved: true })
      revalidator.revalidate()
    } catch (error) {
      setStatus({ error: error.message, field: error.field })
    }
  }

  const method = payoutMethod(form.payments.method)

  return (
    <form onSubmit={save} className="max-w-3xl space-y-6">
      <title>Store settings — Topline</title>
      <PageHeader title="Store settings">What customers see on {storeHost(store.slug)}.</PageHeader>

      <section className="grid gap-5 rounded-card bg-white p-5 ring-1 ring-line sm:grid-cols-2 sm:p-6">
        <h2 className="font-bold sm:col-span-2">Shop details</h2>
        <Field className="sm:col-span-2" label="Shop name" value={form.name} onChange={(e) => set({ name: e.target.value })} error={err('name')} required />
        <Field className="sm:col-span-2" label="Short description" value={form.tagline} onChange={(e) => set({ tagline: e.target.value })} />
        <Field as="select" label="County" value={form.county} onChange={(e) => set({ county: e.target.value })}>
          {COUNTIES.map((c) => <option key={c}>{c}</option>)}
        </Field>
        <Field label="Town or area" value={form.town} onChange={(e) => set({ town: e.target.value })} />
        <Field className="sm:col-span-2" label="Street or landmark" value={form.address} onChange={(e) => set({ address: e.target.value })} />
        <Field label="Shop phone" type="tel" value={form.phone} onChange={(e) => set({ phone: e.target.value })} />
        <Field label="WhatsApp for orders" type="tel" value={form.whatsapp} onChange={(e) => set({ whatsapp: e.target.value })} error={err('whatsapp')} hint="New-order alerts go here." />
        <Field className="sm:col-span-2" label="Opening hours" value={form.hours} onChange={(e) => set({ hours: e.target.value })} />
      </section>

      <section className="rounded-card bg-white p-5 ring-1 ring-line sm:p-6">
        <h2 className="font-bold">Getting paid</h2>
        <p className="mt-1 text-sm text-muted">Money from online orders paid by M-PESA prompt is sent here automatically.</p>
        <fieldset className="mt-4">
          <legend className="sr-only">How you receive money</legend>
          <div className="grid gap-2 sm:grid-cols-2">
            {PAYOUT_METHODS.map((m) => (
              <label key={m.id} className={cn('flex cursor-pointer items-center gap-3 rounded-lg border p-3', form.payments.method === m.id ? 'border-navy ring-1 ring-navy' : 'border-line hover:border-steel')}>
                <input type="radio" name="payoutMethod" checked={form.payments.method === m.id} onChange={() => setPay({ method: m.id, number: '', account: '' })} className="accent-navy" />
                <span className="font-medium">{m.label}</span>
              </label>
            ))}
          </div>
        </fieldset>
        <div className="mt-4 grid gap-5 sm:grid-cols-2">
          <Field label={method.numberLabel} placeholder={method.placeholder} inputMode={['till', 'paybill'].includes(method.id) ? 'numeric' : 'tel'}
                 value={form.payments.number} onChange={(e) => setPay({ number: e.target.value })} error={err('payoutNumber')} />
          {method.id === 'paybill' && (
            <Field label="Account number" value={form.payments.account} onChange={(e) => setPay({ account: e.target.value })} error={err('payoutAccount')} />
          )}
          <Field label="Name customers see on M-PESA" value={form.payments.name} onChange={(e) => setPay({ name: e.target.value })} hint="So they can check before paying." />
        </div>
        <label className="mt-5 flex items-start gap-3">
          <input type="checkbox" checked={form.payments.acceptsDirect} onChange={(e) => setPay({ acceptsDirect: e.target.checked })} className="mt-1 size-4.5 accent-navy" />
          <span>
            <span className="block">Let customers pay my {method.short} directly</span>
            <span className="block text-sm text-muted">They pay from their M-PESA menu and enter the code. You confirm each payment in Online orders.</span>
          </span>
        </label>
      </section>

      <section className="rounded-card bg-white p-5 ring-1 ring-line sm:p-6">
        <h2 className="font-bold">Delivery</h2>
        <label className="mt-3 flex items-center gap-3">
          <input type="checkbox" checked={form.delivery.enabled} onChange={(e) => setDel({ enabled: e.target.checked })} className="size-4.5 accent-navy" />
          I deliver to customers’ sites
        </label>
        {form.delivery.enabled && (
          <div className="mt-5 space-y-5">
            <fieldset>
              <legend className="text-sm font-medium">How delivery is charged</legend>
              <div className="mt-2 grid gap-2 sm:grid-cols-2">
                {[
                  ['zones', 'Fixed fee per area', 'Customers pick their area; the fee is paid with the order.'],
                  ['quote', 'Agree the cost with each customer', 'For lorries or far sites. You call them; they pay on delivery.'],
                ].map(([id, title, note]) => (
                  <label key={id} className={cn('flex cursor-pointer gap-3 rounded-lg border p-3', form.delivery.mode === id ? 'border-navy ring-1 ring-navy' : 'border-line hover:border-steel')}>
                    <input type="radio" name="deliveryMode" checked={form.delivery.mode === id} onChange={() => setDel({ mode: id })} className="mt-1 accent-navy" />
                    <span><span className="block font-medium">{title}</span><span className="block text-sm text-muted">{note}</span></span>
                  </label>
                ))}
              </div>
            </fieldset>

            {form.delivery.mode === 'zones' && (
              <>
                <ZonesEditor zones={form.delivery.zones} onChange={(zones) => setDel({ zones })} error={err('deliveryZones')} />
                <Field label="Free delivery on orders over (optional)" prefix="KSh" inputMode="numeric" value={form.delivery.freeOver}
                       onChange={(e) => setDel({ freeOver: e.target.value.replace(/\D/g, '') })}
                       hint={form.delivery.freeOver ? `Orders over ${formatKES(Number(form.delivery.freeOver))} pay no delivery.` : 'Leave empty to always charge.'} />
              </>
            )}
            <Field as="textarea" label="Delivery details customers see" value={form.delivery.note} onChange={(e) => setDel({ note: e.target.value })}
                   placeholder="Same-day delivery for orders before 2 pm." />
          </div>
        )}
      </section>

      <section className="rounded-card bg-white p-5 ring-1 ring-line sm:p-6">
        <h2 className="font-bold">Look of your store</h2>
        <div className="mt-4 grid gap-6 sm:grid-cols-[10rem_1fr]">
          <PhotoUpload label="Logo" value={form.logo} onChange={(logo) => set({ logo })} storeId={store.id} aspect="aspect-square" />
          <PhotoUpload label="Cover photo (top of your store)" value={form.cover} onChange={(cover) => set({ cover })} storeId={store.id} aspect="aspect-[16/7]" />
        </div>
        <fieldset className="mt-6">
          <legend className="text-sm font-medium">Accent colour</legend>
          <div className="mt-2 flex flex-wrap gap-2">
            {ACCENTS.map((c) => (
              <button key={c} type="button" aria-label={`Accent ${c}`} aria-pressed={form.accent === c} onClick={() => set({ accent: c })}
                      style={{ backgroundColor: c }} className={cn('size-9 rounded-full ring-2 ring-offset-2', form.accent === c ? 'ring-ink' : 'ring-transparent')} />
            ))}
          </div>
        </fieldset>
      </section>

      <div className="sticky bottom-0 -mx-4 flex items-center justify-end gap-4 border-t border-line bg-concrete/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-card sm:border sm:px-5">
        {status?.saved && <p className="flex items-center gap-1.5 text-sm text-good" role="status"><CircleCheck className="size-4" />Changes saved</p>}
        {status?.error && <p className="text-sm text-bad" role="alert">{status.error}</p>}
        <Button type="submit" variant="navy" pending={status?.saving}>Save changes</Button>
      </div>
    </form>
  )
}

function ZonesEditor({ zones, onChange, error }) {
  const update = (i, patch) => onChange(zones.map((z, j) => (j === i ? { ...z, ...patch } : z)))
  return (
    <div>
      <p className="text-sm font-medium">Delivery areas and fees</p>
      <ul className="mt-2 space-y-2">
        {zones.map((z, i) => (
          <li key={z.id ?? i} className="flex items-center gap-2">
            <input aria-label="Area name" value={z.name} onChange={(e) => update(i, { name: e.target.value })} placeholder="e.g. Kitengela town"
                   className="h-10 min-w-0 flex-1 rounded-lg border border-line px-3 focus:border-navy focus:outline-none" />
            <span className="text-sm text-muted">KSh</span>
            <input aria-label={`Fee for ${z.name || 'area'}`} inputMode="numeric" value={z.fee} onChange={(e) => update(i, { fee: Number(e.target.value.replace(/\D/g, '')) || 0 })}
                   className="h-10 w-24 rounded-lg border border-line px-3 text-right tabular focus:border-navy focus:outline-none" />
            <button type="button" aria-label={`Remove ${z.name || 'area'}`} onClick={() => onChange(zones.filter((_, j) => j !== i))} className="rounded-md p-2 text-muted hover:text-bad">
              <Trash2 className="size-4" />
            </button>
          </li>
        ))}
      </ul>
      <button type="button" onClick={() => onChange([...zones, { name: '', fee: 0 }])} className="mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-navy hover:underline">
        <Plus className="size-4" /> Add an area
      </button>
      {error && <p className="mt-1 text-sm text-bad">{error}</p>}
    </div>
  )
}

export { Settings as Component }
