import { useState } from 'react'
import { Form, Link, data, redirect, useActionData, useLoaderData, useNavigation } from 'react-router'
import { ArrowLeft, Camera, Images, Plus, Trash2, X } from 'lucide-react'
import { api } from '@/services/api'
import { formatKES } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PHOTOS } from '@/lib/images'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Img } from '@/components/ui/Img'
import { PhotoUpload } from '@/components/PhotoUpload'
import { useVendor } from './VendorLayout'
import { requireStoreId } from './guard'

const SPEC_LABELS = ['Size', 'Length', 'Width', 'Thickness', 'Diameter', 'Weight', 'Capacity', 'Grade', 'Gauge', 'Colour', 'Brand', 'Material', 'Finish', 'Pack size']
const UNITS = ['piece', 'bag (50 kg)', 'bag (25 kg)', 'length (12 m)', 'length (6 m)', 'metre', 'foot', 'kg', 'tonne', 'litre', 'tin (4 L)', 'roll', 'sheet', 'box']
const PRODUCT_PHOTOS = Object.keys(PHOTOS).filter((k) => !['heroShelves', 'toolWall', 'storefront', 'aisle', 'ownerPhone', 'stallOwner', 'handshake'].includes(k))

export async function loader({ params }) {
  const storeId = requireStoreId()
  const { products, categories } = await api.listProducts(storeId)
  if (!params.productId) return { product: null, categories }
  const product = products.find((p) => p.id === params.productId)
  if (!product) throw data({ message: 'That product was deleted or never existed.' }, { status: 404 })
  return { product, categories }
}

export async function action({ request, params }) {
  const storeId = requireStoreId()
  const fd = await request.formData()

  if (fd.get('intent') === 'delete') {
    await api.deleteProduct(storeId, params.productId)
    return redirect('/dashboard/products')
  }

  const num = (k) => (fd.get(k) === '' ? NaN : Number(fd.get(k)))
  try {
    await api.saveProduct(storeId, {
      id: params.productId,
      name: fd.get('name').trim(),
      categoryId: fd.get('categoryId'),
      unit: fd.get('unit'),
      sellPrice: num('sellPrice'),
      buyPrice: num('buyPrice') || 0,
      stock: num('stock') || 0,
      reorderAt: num('reorderAt') || 0,
      description: fd.get('description').trim(),
      size: fd.get('size').trim(),
      specs: JSON.parse(fd.get('specs') || '[]'),
      image: fd.get('image'),
      gallery: JSON.parse(fd.get('gallery') || '[]'),
      published: fd.get('published') === 'on',
    })
    return redirect('/dashboard/products')
  } catch (e) {
    return { error: e.message, field: e.field }
  }
}

function ProductEditor() {
  const { product, categories } = useLoaderData()
  const { store } = useVendor()
  const result = useActionData()
  const navigation = useNavigation()
  const saving = navigation.state === 'submitting' && navigation.formData?.get('intent') !== 'delete'
  const deleting = navigation.state === 'submitting' && navigation.formData?.get('intent') === 'delete'
  const [image, setImage] = useState(product?.image ?? '')
  const [gallery, setGallery] = useState(product?.gallery ?? [])
  const [specs, setSpecs] = useState(product?.specs?.length ? product.specs : [{ label: '', value: '' }])
  const [photoSource, setPhotoSource] = useState(!product?.image || PRODUCT_PHOTOS.includes(product.image) ? 'ready' : 'own')
  const [description, setDescription] = useState(product?.description ?? '')
  const [prices, setPrices] = useState({ sell: product?.sellPrice ?? '', buy: product?.buyPrice ?? '' })
  const margin = prices.sell > 0 && prices.buy > 0 ? Math.round(((prices.sell - prices.buy) / prices.sell) * 100) : null
  const err = (f) => (result?.field === f ? result.error : undefined)

  return (
    <div className="mx-auto max-w-3xl">
      <title>{`${product ? `Edit ${product.name}` : 'Add product'} — Topline`}</title>
      <Link to="/dashboard/products" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> Products
      </Link>
      <PageHeader title={product ? 'Edit product' : 'Add a product'} />

      <Form method="post" className="space-y-6">
        <section className="grid gap-5 rounded-card bg-white p-5 ring-1 ring-line sm:grid-cols-2 sm:p-6">
          <Field className="sm:col-span-2" name="name" label="Product name" defaultValue={product?.name} placeholder="Bamburi Tembo cement 32.5R" error={err('name')} required />
          <Field as="select" name="categoryId" label="Category" defaultValue={product?.categoryId ?? categories[0].id}>
            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </Field>
          <Field as="select" name="unit" label="Sold per" defaultValue={product?.unit ?? 'piece'} hint="The unit customers pay for.">
            {[...new Set([product?.unit, ...UNITS].filter(Boolean))].map((u) => <option key={u}>{u}</option>)}
          </Field>
          <Field
            className="sm:col-span-2"
            as="textarea"
            name="description"
            label="Short description"
            value={description}
            onChange={(e) => setDescription(e.target.value.slice(0, 500))}
            placeholder="What it’s for and why it’s good, e.g. Fast-setting cement for slabs, columns and beams."
            hint={`${description.length}/500 characters. Customers see this on the product page.`}
          />
        </section>

        <section className="rounded-card bg-white p-5 ring-1 ring-line sm:p-6">
          <h2 className="font-bold">Measurements</h2>
          <p className="mt-1 text-sm text-muted">Fundis buy by size. Give the main measurement, then any others that matter.</p>
          <Field
            className="mt-4 max-w-md"
            name="size"
            label="Main size or measurement"
            defaultValue={product?.size}
            maxLength={80}
            placeholder="e.g. 12 mm × 12 m, 50 kg, 4 L, 2 × 4 in"
            error={err('size')}
          />
          <input type="hidden" name="specs" value={JSON.stringify(specs.filter((r) => r.label.trim() || r.value.trim()))} />
          <datalist id="spec-labels">{SPEC_LABELS.map((l) => <option key={l} value={l} />)}</datalist>
          <div className="mt-5 space-y-2" role="group" aria-label="Other measurements">
            <p className="text-sm font-medium">Other measurements (optional)</p>
            {specs.map((row, i) => (
              <div key={i} className="flex gap-2">
                <input
                  list="spec-labels"
                  aria-label={`Measurement ${i + 1} name`}
                  value={row.label}
                  onChange={(e) => setSpecs((rows) => rows.map((r, j) => (j === i ? { ...r, label: e.target.value.slice(0, 40) } : r)))}
                  placeholder="e.g. Grade"
                  className="h-11 w-2/5 rounded-lg border border-line px-3 focus:border-navy focus:ring-3 focus:ring-navy/15 focus:outline-none"
                />
                <input
                  aria-label={`Measurement ${i + 1} value`}
                  value={row.value}
                  onChange={(e) => setSpecs((rows) => rows.map((r, j) => (j === i ? { ...r, value: e.target.value.slice(0, 80) } : r)))}
                  placeholder="e.g. Y12"
                  className="h-11 min-w-0 flex-1 rounded-lg border border-line px-3 focus:border-navy focus:ring-3 focus:ring-navy/15 focus:outline-none"
                />
                <button
                  type="button"
                  aria-label={`Remove measurement ${i + 1}`}
                  onClick={() => setSpecs((rows) => (rows.length > 1 ? rows.filter((_, j) => j !== i) : [{ label: '', value: '' }]))}
                  className="rounded-md p-2 text-muted hover:text-bad"
                >
                  <X className="size-4" />
                </button>
              </div>
            ))}
            {specs.length < 8 && (
              <button type="button" onClick={() => setSpecs((rows) => [...rows, { label: '', value: '' }])} className="inline-flex items-center gap-1.5 text-sm font-medium text-navy hover:underline">
                <Plus className="size-4" /> Add another measurement
              </button>
            )}
            {err('specs') && <p className="text-sm text-bad" role="alert">{err('specs')}</p>}
          </div>
        </section>

        <section className="grid gap-5 rounded-card bg-white p-5 ring-1 ring-line sm:grid-cols-2 sm:p-6">
          <h2 className="font-bold sm:col-span-2">Price and stock</h2>
          <Field name="sellPrice" label="Selling price" prefix="KSh" inputMode="numeric" defaultValue={product?.sellPrice} onChange={(e) => setPrices((p) => ({ ...p, sell: Number(e.target.value) }))} error={err('sellPrice')} required />
          <Field
            name="buyPrice"
            label="Buying price"
            prefix="KSh"
            inputMode="numeric"
            defaultValue={product?.buyPrice}
            onChange={(e) => setPrices((p) => ({ ...p, buy: Number(e.target.value) }))}
            hint={margin !== null ? `${margin}% margin, ${formatKES(prices.sell - prices.buy)} profit per unit. Staff can’t see this.` : 'Only you and managers can see this.'}
          />
          <Field name="stock" label="Quantity in stock" inputMode="numeric" defaultValue={product?.stock ?? 0} />
          <Field name="reorderAt" label="Warn me when stock falls to" inputMode="numeric" defaultValue={product?.reorderAt ?? 10} />
        </section>

        <section className="rounded-card bg-white p-5 ring-1 ring-line sm:p-6">
          <h2 className="font-bold">Photos</h2>
          <p className="mt-1 text-sm text-muted">Start with a ready-made picture, or show your actual stock: a real photo sells better.</p>
          <input type="hidden" name="image" value={image} />
          <input type="hidden" name="gallery" value={JSON.stringify(gallery)} />

          <div className="mt-4 inline-flex rounded-lg bg-concrete p-1" role="tablist" aria-label="Main photo">
            {[['ready', 'Ready-made pictures', Images], ['own', 'My own photo', Camera]].map(([id, label, Icon]) => (
              <button
                key={id}
                type="button"
                role="tab"
                aria-selected={photoSource === id}
                onClick={() => setPhotoSource(id)}
                className={cn('flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm', photoSource === id ? 'bg-white font-medium text-navy shadow-sm' : 'text-muted hover:text-ink')}
              >
                <Icon className="size-4" /> {label}
              </button>
            ))}
          </div>

          {photoSource === 'own' ? (
            <PhotoUpload className="mt-4 max-w-sm" label="Main photo" value={PRODUCT_PHOTOS.includes(image) ? '' : image} onChange={setImage} storeId={store.id} />
          ) : (
            <div className="mt-4 grid grid-cols-4 gap-2 sm:grid-cols-6" role="radiogroup" aria-label="Ready-made picture">
              {PRODUCT_PHOTOS.map((key) => (
                <button
                  key={key}
                  type="button"
                  role="radio"
                  aria-checked={image === key}
                  aria-label={key}
                  onClick={() => setImage(key)}
                  className={cn('overflow-hidden rounded-lg ring-2 ring-offset-2', image === key ? 'ring-navy' : 'ring-transparent hover:ring-line')}
                >
                  <Img name={key} alt="" w={160} h={160} className="aspect-square w-full" />
                </button>
              ))}
            </div>
          )}

          <div className="mt-6">
            <p className="text-sm font-medium">More photos (optional, up to 3)</p>
            <p className="text-sm text-muted">Other angles, the label, or a close-up of the size marking.</p>
            <div className="mt-3 grid gap-4 sm:grid-cols-3">
              {[0, 1, 2].map((i) =>
                i <= gallery.length ? (
                  <PhotoUpload
                    key={i}
                    label={`Extra photo ${i + 1}`}
                    aspect="aspect-square"
                    value={gallery[i] ?? ''}
                    storeId={store.id}
                    onChange={(url) => setGallery((g) => (url ? Object.assign([...g], { [i]: url }) : g.filter((_, j) => j !== i)))}
                  />
                ) : null,
              )}
            </div>
          </div>
          <label className="mt-6 flex items-center gap-3">
            <input type="checkbox" name="published" defaultChecked={product?.published ?? true} className="size-4.5 accent-navy" />
            <span>Show on my online store</span>
          </label>
        </section>

        {result?.error && !result.field && <p className="text-sm text-bad" role="alert">{result.error}</p>}

        <div className="flex flex-wrap items-center justify-between gap-3">
          {product ? (
            <Button
              type="submit"
              name="intent"
              value="delete"
              variant="ghost"
              className="text-bad hover:bg-bad-tint"
              pending={deleting}
              formNoValidate
              onClick={(e) => { if (!window.confirm(`Delete ${product.name}? Past sales keep their record.`)) e.preventDefault() }}
            >
              <Trash2 className="size-4" />Delete product
            </Button>
          ) : <span />}
          <div className="flex gap-2">
            <Button to="/dashboard/products" variant="outline">Cancel</Button>
            <Button type="submit" name="intent" value="save" variant="navy" pending={saving}>{product ? 'Save changes' : 'Add product'}</Button>
          </div>
        </div>
      </Form>
    </div>
  )
}

export { ProductEditor as Component }
