import { useState } from 'react'
import { Link, useLoaderData } from 'react-router'
import { ArrowLeft, CircleCheck, Globe, HandCoins, Mail, MapPin, Phone, Plus, Trash2, Truck } from 'lucide-react'
import { api } from '@/services/api'
import { displayPhone, formatKES } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Img } from '@/components/ui/Img'
import { SupplierLogo } from '@/components/SupplierLogo'
import { useVendor } from './VendorLayout'
import { requireStoreId } from './guard'

export async function loader({ params }) {
  return { supplier: await api.getSupplier(requireStoreId(), params.slug) }
}

function SupplierDetail() {
  const { supplier } = useLoaderData()
  const [placed, setPlaced] = useState(null)

  return (
    <>
      <title>{`${supplier.name} — Suppliers — Topline`}</title>
      <Link to="/dashboard/suppliers" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> All suppliers
      </Link>
      <section className="flex flex-col gap-5 rounded-card bg-white p-6 ring-1 ring-line sm:flex-row">
        <SupplierLogo supplier={supplier} className="size-20" />
        <div className="min-w-0 flex-1">
          <h1 className="text-2xl font-bold text-navy">{supplier.name}</h1>
          <p className="mt-2 max-w-3xl leading-relaxed text-muted">{supplier.description}</p>
          <ul className="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-sm text-ink/80">
            {supplier.location && <li className="flex items-center gap-1.5"><MapPin className="size-4 text-steel" />{supplier.location}</li>}
            {supplier.phone && <li className="flex items-center gap-1.5"><Phone className="size-4 text-steel" /><a href={`tel:${supplier.phone.replace(/\s/g, '')}`} className="hover:underline">{supplier.phone}</a></li>}
            {supplier.email && <li className="flex items-center gap-1.5"><Mail className="size-4 text-steel" /><a href={`mailto:${supplier.email}`} className="hover:underline">{supplier.email}</a></li>}
            {supplier.website && <li className="flex items-center gap-1.5"><Globe className="size-4 text-steel" /><a href={supplier.website} target="_blank" rel="noreferrer noopener" className="hover:underline">Website</a></li>}
          </ul>
          {(supplier.minOrder || supplier.deliveryInfo) && (
            <p className="mt-3 flex items-start gap-1.5 text-sm text-muted">
              <Truck className="mt-0.5 size-4 shrink-0 text-steel" />
              {[supplier.minOrder && `Minimum order: ${supplier.minOrder}`, supplier.deliveryInfo].filter(Boolean).join('. ')}
            </p>
          )}
        </div>
      </section>

      {placed ? <OrderSent order={placed} supplier={supplier} /> : <OrderForm supplier={supplier} onPlaced={setPlaced} />}
    </>
  )
}

function OrderForm({ supplier, onPlaced }) {
  const { user, store } = useVendor()
  const [qty, setQty] = useState({}) // productId -> quantity text
  const [extra, setExtra] = useState([]) // [{ name, unit, qty }]
  const [form, setForm] = useState({
    contactName: user.name,
    contactPhone: displayPhone(user.phone) || '',
    deliveryLocation: [store.address, store.town, store.county].filter(Boolean).join(', '),
    paymentOption: supplier.paymentOptions[0]?.id ?? '',
    notes: '',
  })
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const update = (patch) => setForm((f) => ({ ...f, ...patch }))

  const lines = [
    ...supplier.products.filter((p) => Number(qty[p.id]) > 0).map((p) => ({ productId: p.id, qty: Number(qty[p.id]), product: p })),
    ...extra.filter((x) => x.name.trim() && Number(x.qty) > 0).map((x) => ({ name: x.name.trim(), unit: x.unit.trim(), qty: Number(x.qty) })),
  ]
  const estimate = lines.reduce((sum, l) => sum + (l.product?.price ? l.product.price * l.qty : 0), 0)
  const unpriced = lines.some((l) => !l.product?.price)

  async function submit(e) {
    e.preventDefault()
    setError(null)
    if (!lines.length) return setError({ message: 'Enter a quantity for at least one item.', field: 'items' })
    setBusy(true)
    try {
      const order = await api.orderFromSupplier(store.id, supplier.slug, {
        ...form,
        items: lines.map((l) => (l.productId ? { productId: l.productId, qty: l.qty } : { name: l.name, unit: l.unit, qty: l.qty })),
      })
      onPlaced(order)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } catch (err) {
      setError({ message: err.message, field: err.field })
    } finally {
      setBusy(false)
    }
  }
  const fieldError = (name) => (error?.field === name ? error.message : undefined)

  return (
    <form onSubmit={submit} className="mt-6 grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]" noValidate>
      <section className="rounded-card bg-white ring-1 ring-line" aria-labelledby="products-h">
        <h2 id="products-h" className="border-b border-line px-5 py-4 font-bold">What they supply</h2>
        {supplier.products.length === 0 && <p className="px-5 py-4 text-muted">No price list yet. Describe what you need below.</p>}
        <ul className="divide-y divide-line">
          {supplier.products.map((p) => (
            <li key={p.id} className="flex gap-4 px-5 py-4">
              {p.image ? <Img name={p.image} alt="" w={160} className="size-16 shrink-0 rounded-lg" /> : null}
              <div className="min-w-0 flex-1">
                <p className="font-medium">{p.name}</p>
                {p.description && <p className="mt-0.5 text-sm text-muted">{p.description}</p>}
                <p className="mt-1 text-sm">
                  <span className="font-bold tabular">{p.price ? formatKES(p.price) : 'Price on request'}</span>
                  {p.price ? <span className="text-muted"> per {p.unit}</span> : null}
                  <span className="text-muted"> · min. {p.minQty} {p.unit}</span>
                  {!p.inStock && <span className="ml-2 rounded-full bg-amber-tint px-2 py-0.5 text-xs text-[#8a5a00]">Out of stock</span>}
                </p>
              </div>
              <label className="w-28 shrink-0">
                <span className="sr-only">Quantity of {p.name}</span>
                <input
                  type="number" inputMode="numeric" min="0" placeholder="Qty" value={qty[p.id] ?? ''}
                  onChange={(e) => setQty((q) => ({ ...q, [p.id]: e.target.value }))}
                  className="h-11 w-full rounded-lg border border-line px-3 text-right tabular focus:border-navy focus:ring-3 focus:ring-navy/15 focus:outline-none"
                />
                <span className="mt-1 block text-right text-xs text-muted">{p.unit}</span>
              </label>
            </li>
          ))}
        </ul>
        <div className="border-t border-line px-5 py-4">
          <p className="text-sm font-medium">Something not on the list?</p>
          {extra.map((x, i) => (
            <div key={i} className="mt-2 flex gap-2">
              <input aria-label={`Item ${i + 1}`} placeholder="Item, e.g. cement 42.5 in 25 kg bags" value={x.name} onChange={(e) => setExtra((xs) => xs.map((y, j) => (j === i ? { ...y, name: e.target.value } : y)))} className="h-10 min-w-0 flex-1 rounded-lg border border-line px-3 text-sm" />
              <input aria-label={`Item ${i + 1} quantity`} type="number" min="1" placeholder="Qty" value={x.qty} onChange={(e) => setExtra((xs) => xs.map((y, j) => (j === i ? { ...y, qty: e.target.value } : y)))} className="h-10 w-20 rounded-lg border border-line px-3 text-sm" />
              <input aria-label={`Item ${i + 1} unit`} placeholder="Unit" value={x.unit} onChange={(e) => setExtra((xs) => xs.map((y, j) => (j === i ? { ...y, unit: e.target.value } : y)))} className="h-10 w-20 rounded-lg border border-line px-3 text-sm" />
              <button type="button" aria-label={`Remove item ${i + 1}`} onClick={() => setExtra((xs) => xs.filter((_, j) => j !== i))} className="rounded-lg px-2 text-muted hover:bg-concrete"><Trash2 className="size-4" /></button>
            </div>
          ))}
          {extra.length < 10 && (
            <button type="button" onClick={() => setExtra((xs) => [...xs, { name: '', unit: '', qty: '' }])} className="mt-2 flex items-center gap-1.5 text-sm font-medium text-navy hover:underline">
              <Plus className="size-4" /> Add an item
            </button>
          )}
        </div>
        {fieldError('items') && <p className="px-5 pb-4 text-sm text-bad" role="alert">{fieldError('items')}</p>}
      </section>

      <section className="h-fit space-y-4 rounded-card bg-white p-5 ring-1 ring-line lg:sticky lg:top-24" aria-labelledby="order-h">
        <h2 id="order-h" className="font-bold">Send an order to {supplier.name}</h2>
        <div className="rounded-lg bg-concrete px-4 py-3 text-sm">
          <p className="flex justify-between"><span>Items</span><span className="font-medium tabular">{lines.length}</span></p>
          <p className="mt-1 flex justify-between"><span>Estimated total</span><span className="font-bold tabular">{estimate ? formatKES(estimate) : '—'}</span></p>
          {unpriced && lines.length > 0 && <p className="mt-1 text-xs text-muted">Some items have no price yet: the supplier will quote them.</p>}
        </div>
        <Field label="Who should they talk to?" value={form.contactName} onChange={(e) => update({ contactName: e.target.value })} error={fieldError('contactName')} required />
        <Field label="Phone they should call" type="tel" value={form.contactPhone} onChange={(e) => update({ contactPhone: e.target.value })} error={fieldError('contactPhone')} required />
        <Field label="Deliver to" value={form.deliveryLocation} onChange={(e) => update({ deliveryLocation: e.target.value })} error={fieldError('deliveryLocation')} required />
        <fieldset>
          <legend className="text-sm font-medium">How you’ll pay</legend>
          <div className="mt-1.5 space-y-1.5">
            {supplier.paymentOptions.map((o) => (
              <label key={o.id} className={cn('flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2.5 text-sm', form.paymentOption === o.id ? 'border-navy bg-mist/40' : 'border-line')}>
                <input type="radio" name="paymentOption" value={o.id} checked={form.paymentOption === o.id} onChange={() => update({ paymentOption: o.id })} className="accent-navy" />
                {o.label}
              </label>
            ))}
          </div>
          {fieldError('paymentOption') && <p className="mt-1 text-sm text-bad">{fieldError('paymentOption')}</p>}
          {form.paymentOption === 'topline_credit' && (
            <p className="mt-2 flex gap-2 rounded-lg bg-amber-tint px-3 py-2 text-xs text-[#7a4f00]"><HandCoins className="size-4 shrink-0" />After sending, you can ask for Topline stock credit for this order.</p>
          )}
          {supplier.paymentInstructions && <p className="mt-2 text-xs text-muted">From the supplier: {supplier.paymentInstructions}</p>}
        </fieldset>
        <Field as="textarea" label="Notes (optional)" placeholder="Delivery date, site access, anything else" value={form.notes} onChange={(e) => update({ notes: e.target.value })} error={fieldError('notes')} />
        {error && !['items', 'contactName', 'contactPhone', 'deliveryLocation', 'paymentOption', 'notes'].includes(error.field) && (
          <p className="rounded-lg bg-bad-tint px-3 py-2 text-sm text-bad" role="alert">{error.message}</p>
        )}
        <Button type="submit" variant="navy" className="w-full" pending={busy}>Send order</Button>
        <p className="text-xs text-muted">The supplier calls you to confirm prices and delivery. You pay them directly; Topline doesn’t handle this money.</p>
      </section>
    </form>
  )
}

function OrderSent({ order, supplier }) {
  return (
    <section className="mt-6 rounded-card bg-white p-6 ring-1 ring-line" role="status">
      <CircleCheck className="size-10 text-good" aria-hidden />
      <h2 className="mt-3 text-xl font-bold">Order {order.reference} sent to {supplier.name}</h2>
      <p className="mt-2 max-w-2xl text-muted">
        They’ll call {order.contactName} on {order.contactPhone} to confirm prices, delivery and payment ({order.paymentOption.label.toLowerCase()}).
        {supplier.phone ? ` You can also reach them on ${supplier.phone}.` : ''}
      </p>
      <div className="mt-5 flex flex-wrap gap-3">
        <Button variant="navy" to="/dashboard/suppliers">See your supplier orders</Button>
        {order.paymentOption.id === 'topline_credit' && (
          <Button variant="outline" to={`/dashboard/credit?order=${order.id}&amount=${order.estimatedTotal ?? ''}`}>Ask for stock credit</Button>
        )}
      </div>
    </section>
  )
}

export { SupplierDetail as Component }
