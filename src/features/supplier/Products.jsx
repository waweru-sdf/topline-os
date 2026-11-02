import { useState } from 'react'
import { useFetcher, useLoaderData, useRevalidator } from 'react-router'
import { Package, Pencil, Plus, Trash2 } from 'lucide-react'
import { api } from '@/services/api'
import { formatKES } from '@/lib/format'
import { PageHeader } from '@/components/layout/AppShell'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { EmptyState } from '@/components/ui/EmptyState'
import { Field } from '@/components/ui/Field'
import { Img } from '@/components/ui/Img'
import { PhotoUpload } from '@/components/PhotoUpload'
import { requireSupplier } from '@/features/vendor/guard'

export async function loader() {
  requireSupplier()
  return { products: await api.listOwnProducts() }
}

export async function action({ request }) {
  const fd = await request.formData()
  try {
    await api.deleteOwnProduct(fd.get('id'))
    return { ok: true }
  } catch (e) {
    return { error: e.message }
  }
}

function reviewBadge(p) {
  if (p.pendingChange) return <Badge tone="amber">Change waiting for approval</Badge>
  if (p.status === 'pending') return <Badge tone="amber">Waiting for approval</Badge>
  if (p.status === 'rejected') return <Badge tone="bad">Not approved</Badge>
  return <Badge tone="good">Approved</Badge>
}

function Products() {
  const { products } = useLoaderData()
  const [editing, setEditing] = useState(null) // product, or {} for new

  return (
    <>
      <title>Products — Topline supplier</title>
      <PageHeader title="Products & prices" actions={<Button variant="navy" onClick={() => setEditing({})}><Plus className="size-4" />Add product</Button>}>
        Shops see a product once Topline approves it. Changes to an approved product (price, stock) are checked too; until then shops see the approved version.
      </PageHeader>
      {products.length === 0 ? (
        <EmptyState icon={Package} title="No products yet">Add what you sell, with prices per unit and the minimum order.</EmptyState>
      ) : (
        <ul className="divide-y divide-line rounded-card bg-white ring-1 ring-line">
          {products.map((p) => <ProductRow key={p.id} product={p} onEdit={() => setEditing(p)} />)}
        </ul>
      )}
      {editing && <ProductDialog product={editing} onClose={() => setEditing(null)} />}
    </>
  )
}

function ProductRow({ product: p, onEdit }) {
  const fetcher = useFetcher()
  if (fetcher.formData) return null
  const shown = p.pendingChange ?? p
  return (
    <li className="flex flex-wrap items-center gap-4 px-5 py-4">
      {p.image ? <Img name={p.image} alt="" w={120} className="size-14 shrink-0 rounded-lg" /> : <span className="flex size-14 shrink-0 items-center justify-center rounded-lg bg-concrete"><Package className="size-5 text-steel" /></span>}
      <div className="min-w-0 flex-1">
        <p className="font-medium">{shown.name}</p>
        <p className="text-sm text-muted">
          {shown.price ? `${formatKES(shown.price)} per ${shown.unit}` : 'Price on request'} · min. {shown.minQty} {shown.unit}{shown.inStock ? '' : ' · out of stock'}
        </p>
        {p.pendingChange && p.price !== p.pendingChange.price && (
          <p className="text-xs text-muted">Shops still see {p.price ? formatKES(p.price) : 'price on request'} until the change is approved.</p>
        )}
        {p.reviewNote && <p className="mt-1 text-sm text-bad">Topline: {p.reviewNote}</p>}
      </div>
      {reviewBadge(p)}
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
  const start = product.pendingChange ?? product
  const revalidator = useRevalidator()
  const [form, setForm] = useState({
    name: start.name ?? '', description: start.description ?? '', unit: start.unit ?? 'bag',
    price: start.price ?? '', minQty: start.minQty ?? 1, inStock: start.inStock ?? true, image: start.image ?? '',
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
        <p className="text-xs text-muted">{product.status === 'approved' ? 'Your change goes to Topline for approval. Shops keep seeing the current version until then.' : 'Topline checks new products before shops can see them.'}</p>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="navy" pending={busy}>Send for approval</Button>
        </div>
      </form>
    </Dialog>
  )
}

export { Products as Component }
