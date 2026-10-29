import { useState } from 'react'
import { Form, Link, data, redirect, useActionData, useLoaderData, useNavigation } from 'react-router'
import { ArrowLeft, Trash2 } from 'lucide-react'
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
      image: fd.get('image'),
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
          <Field className="sm:col-span-2" as="textarea" name="description" label="Description (optional)" defaultValue={product?.description} placeholder="What it’s for, grade, size, brand" />
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
          <h2 className="font-bold">Photo</h2>
          <p className="mt-1 text-sm text-muted">A clear photo of your actual stock sells better. Take one with your phone or upload one.</p>
          <input type="hidden" name="image" value={image} />
          <PhotoUpload className="mt-4 max-w-sm" label="Product photo" value={image} onChange={setImage} storeId={store.id} />
          <details className="mt-5">
            <summary className="cursor-pointer text-sm font-medium text-navy">No photo yet? Use a sample picture</summary>
            <div className="mt-3 grid grid-cols-4 gap-2 sm:grid-cols-6" role="radiogroup" aria-label="Sample photo">
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
          </details>
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
