import { useState } from 'react'
import { useFetcher, useRevalidator } from 'react-router'
import { Package, Pencil, Plus, Trash2 } from 'lucide-react'
import { api } from '@/services/api'
import { formatKES } from '@/lib/format'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { EmptyState } from '@/components/ui/EmptyState'
import { Field } from '@/components/ui/Field'
import { Img } from '@/components/ui/Img'
import { PhotoUpload } from '@/components/PhotoUpload'

/** The supplier's products, edited in place. Changes reach shops at once. Lives on the profile page. */
export function ProductList({ products }) {
  const [editing, setEditing] = useState(null) // a product, or {} for a new one

  return (
    <section className="mt-8" aria-labelledby="products-h">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 id="products-h" className="text-lg font-bold">Products & prices</h2>
          <p className="text-sm text-muted">Shops see these on your profile and order from them. Keep prices and stock up to date.</p>
        </div>
        <Button variant="navy" onClick={() => setEditing({})}><Plus className="size-4" />Add product</Button>
      </div>
      {products.length === 0 ? (
        <div className="mt-4"><EmptyState icon={Package} title="No products yet">Add what you sell, with the price per unit and the minimum order.</EmptyState></div>
      ) : (
        <ul className="mt-4 divide-y divide-line rounded-card bg-white ring-1 ring-line">
          {products.map((p) => <ProductRow key={p.id} product={p} onEdit={() => setEditing(p)} />)}
        </ul>
      )}
      {editing && <ProductDialog product={editing} onClose={() => setEditing(null)} />}
    </section>
  )
}

function ProductRow({ product: p, onEdit }) {
  const fetcher = useFetcher()
  if (fetcher.formData) return null
  return (
    <li className="flex flex-wrap items-center gap-4 px-5 py-4">
      {p.image ? <Img name={p.image} alt="" w={120} className="size-14 shrink-0 rounded-lg" /> : <span className="flex size-14 shrink-0 items-center justify-center rounded-lg bg-concrete"><Package className="size-5 text-steel" /></span>}
      <div className="min-w-0 flex-1">
        <p className="font-medium">{p.name}</p>
        <p className="text-sm text-muted">
          {p.price ? `${formatKES(p.price)} per ${p.unit}` : 'Price on request'} · min. {p.minQty} {p.unit}{p.inStock ? '' : ' · out of stock'}
        </p>
      </div>
      <div className="flex gap-1">
        <button type="button" onClick={onEdit} aria-label={`Edit ${p.name}`} className="rounded-md p-2 text-muted hover:bg-concrete hover:text-ink"><Pencil className="size-4" /></button>
        <fetcher.Form method="post" onSubmit={(e) => { if (!window.confirm(`Remove ${p.name}? Shops will no longer see it.`)) e.preventDefault() }}>
          <input type="hidden" name="id" value={p.id} />
          <button aria-label={`Remove ${p.name}`} className="rounded-md p-2 text-muted hover:bg-bad-tint hover:text-bad"><Trash2 className="size-4" /></button>
        </fetcher.Form>
      </div>
    </li>
  )
}

function ProductDialog({ product, onClose }) {
  const revalidator = useRevalidator()
  const [form, setForm] = useState({
    name: product.name ?? '', description: product.description ?? '', unit: product.unit ?? 'bag',
    price: product.price ?? '', minQty: product.minQty ?? 1, inStock: product.inStock ?? true, image: product.image ?? '',
  })
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const update = (patch) => setForm((f) => ({ ...f, ...patch }))
  const err = (f) => (error?.field === f ? error.message : undefined)

  async function save(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.saveOwnProduct({ ...form, id: product.id, price: form.price === '' ? null : Number(form.price), minQty: Number(form.minQty) || 1 })
      revalidator.revalidate()
      onClose()
    } catch (err2) {
      setError({ message: err2.message, field: err2.field })
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open onClose={onClose} title={product.id ? 'Edit product' : 'Add a product'}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <Field label="Product name" value={form.name} onChange={(e) => update({ name: e.target.value })} error={err('name')} placeholder="Cement 42.5R (50 kg)" required />
        <Field as="textarea" label="Short description (optional)" value={form.description} onChange={(e) => update({ description: e.target.value })} error={err('description')} maxLength={500} />
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Price" prefix="KSh" inputMode="numeric" value={form.price} onChange={(e) => update({ price: e.target.value.replace(/\D/g, '') })} error={err('price')} hint="Empty = on request" />
          <Field label="Per" value={form.unit} onChange={(e) => update({ unit: e.target.value })} error={err('unit')} placeholder="bag, length, m²" />
          <Field label="Minimum order" inputMode="numeric" value={form.minQty} onChange={(e) => update({ minQty: e.target.value.replace(/\D/g, '') })} error={err('minQty')} />
        </div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.inStock} onChange={(e) => update({ inStock: e.target.checked })} className="size-4 accent-navy" />In stock</label>
        <PhotoUpload label="Photo (optional)" value={form.image} onChange={(url) => update({ image: url })} className="max-w-xs" />
        {err('image') && <p className="text-sm text-bad">{err('image')}</p>}
        {error && !['name', 'description', 'price', 'unit', 'minQty', 'image'].includes(error.field) && <p className="text-sm text-bad" role="alert">{error.message}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="navy" pending={busy}>Save</Button>
        </div>
      </form>
    </Dialog>
  )
}

