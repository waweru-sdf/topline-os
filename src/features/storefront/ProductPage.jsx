import { useState } from 'react'
import { Link, data, useLoaderData } from 'react-router'
import { ArrowLeft, Check, FileText, Minus, Plus, Truck } from 'lucide-react'
import { api } from '@/services/api'
import { getTenantSlugFromHost } from '@/lib/tenant'
import { formatKES } from '@/lib/format'
import { useCart } from '@/context/CartContext'
import { Img } from '@/components/ui/Img'
import { Button } from '@/components/ui/Button'
import { QuoteDialog } from './QuoteDialog'
import { stockLabel } from './ProductCard'
import { useStore } from './useStore'

export async function loader({ params }) {
  try {
    return await api.getStoreProduct(params.slug ?? getTenantSlugFromHost(), params.productSlug)
  } catch (e) {
    throw data({ message: e.message }, { status: e.status ?? 500 })
  }
}

function ProductPage() {
  const { product, category } = useLoaderData()
  const { store, base } = useStore()
  const { add, items } = useCart()
  const [qty, setQty] = useState(1)
  const [quoteOpen, setQuoteOpen] = useState(false)
  const inCart = items.find((i) => i.productId === product.id)?.qty ?? 0
  const stock = stockLabel(product)
  const soldOut = product.stock <= 0

  return (
    <div className="mx-auto max-w-6xl px-4 py-6 sm:px-5 sm:py-10">
      <title>{`${product.name} — ${store.name}`}</title>
      <Link to={base || '/'} className="inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> All products
      </Link>

      <div className="mt-5 grid gap-8 md:grid-cols-2 md:gap-12">
        <Img name={product.image} alt={product.name} w={900} h={700} priority sizes="(min-width: 768px) 560px, 100vw" className="aspect-[9/7] w-full rounded-card" />

        <div>
          {category && <p className="text-sm text-muted">{category.name}</p>}
          <h1 className="mt-1 text-3xl font-bold tracking-tight text-ink">{product.name}</h1>
          <p className="mt-4">
            <span className="text-3xl font-bold text-navy tabular">{formatKES(product.sellPrice)}</span>
            <span className="text-muted"> per {product.unit}</span>
          </p>
          {product.altUnits?.filter((u) => u.price).map((u) => (
            <p key={u.name} className="mt-1 text-sm text-muted">
              Or {formatKES(u.price)} per {u.name}
            </p>
          ))}
          <p className={`mt-3 text-sm font-medium ${stock.tone}`}>{stock.text}</p>
          {product.description && <p className="mt-5 leading-relaxed text-muted">{product.description}</p>}

          <div className="mt-7 flex flex-wrap items-center gap-3">
            <div className="flex h-12 items-center rounded-lg border border-line bg-white">
              <button type="button" aria-label="One less" onClick={() => setQty((q) => Math.max(1, q - 1))} className="flex h-full w-12 items-center justify-center text-muted hover:text-ink">
                <Minus className="size-4" />
              </button>
              <input
                aria-label={`Quantity in ${product.unit}`}
                inputMode="numeric"
                value={qty}
                onChange={(e) => setQty(Math.max(1, Number(e.target.value.replace(/\D/g, '')) || 1))}
                className="h-full w-16 border-x border-line text-center font-bold tabular focus:outline-none"
              />
              <button type="button" aria-label="One more" onClick={() => setQty((q) => q + 1)} className="flex h-full w-12 items-center justify-center text-muted hover:text-ink">
                <Plus className="size-4" />
              </button>
            </div>
            <Button size="lg" variant="navy" disabled={soldOut} onClick={() => add(product, qty)}>
              Add {qty} to cart ({formatKES(qty * product.sellPrice)})
            </Button>
          </div>
          {inCart > 0 && (
            <p className="mt-3 flex items-center gap-1.5 text-sm text-good">
              <Check className="size-4" /> {inCart} in your cart. <Link to={`${base}/cart`} className="font-medium underline underline-offset-4">Go to cart</Link>
            </p>
          )}

          <div className="mt-8 space-y-3 border-t border-line pt-6 text-sm">
            {store.delivery?.enabled && (
              <p className="flex gap-3"><Truck className="size-5 shrink-0 text-steel" /> {store.delivery.note}</p>
            )}
            <button type="button" onClick={() => setQuoteOpen(true)} className="flex gap-3 text-left text-navy hover:underline">
              <FileText className="size-5 shrink-0" /> Buying for a site? Ask for a bulk price
            </button>
          </div>
        </div>
      </div>
      <QuoteDialog open={quoteOpen} onClose={() => setQuoteOpen(false)} store={store} initialItems={`${qty} × ${product.name}`} />
    </div>
  )
}

export { ProductPage as Component }
