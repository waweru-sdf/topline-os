import { Link } from 'react-router'
import { Minus, Plus, ShoppingCart, Trash2 } from 'lucide-react'
import { formatKES } from '@/lib/format'
import { useCart } from '@/context/CartContext'
import { Img } from '@/components/ui/Img'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { useStore } from './useStore'

function CartPage() {
  const { store, base } = useStore()
  const { items, total, setQty, remove } = useCart()

  return (
    <div className="mx-auto max-w-3xl px-4 py-8 sm:px-5 sm:py-12">
      <title>{`Cart — ${store.name}`}</title>
      <h1 className="text-3xl font-bold tracking-tight text-navy">Your cart</h1>

      {items.length === 0 ? (
        <div className="mt-8">
          <EmptyState icon={ShoppingCart} title="Your cart is empty" action={<Button to={base || '/'} variant="navy">Browse products</Button>}>
            Add the materials you need and pay by M-PESA when you’re ready.
          </EmptyState>
        </div>
      ) : (
        <>
          <ul className="mt-8 divide-y divide-line rounded-card bg-white ring-1 ring-line">
            {items.map((item) => (
              <li key={item.productId} className="flex gap-4 p-4">
                <Img name={item.image} alt="" w={160} h={160} className="size-18 shrink-0 rounded-lg" />
                <div className="min-w-0 flex-1">
                  <Link to={`${base}/p/${item.slug}`} className="font-medium hover:underline">{item.name}</Link>
                  <p className="text-sm text-muted">{formatKES(item.price)} per {item.unit}</p>
                  <div className="mt-3 flex items-center gap-3">
                    <div className="flex h-9 items-center rounded-lg border border-line">
                      <button type="button" aria-label={`One less ${item.name}`} onClick={() => setQty(item.productId, item.qty - 1)} className="flex h-full w-9 items-center justify-center text-muted hover:text-ink">
                        <Minus className="size-3.5" />
                      </button>
                      <span className="w-10 text-center text-sm font-bold tabular" aria-live="polite">{item.qty}</span>
                      <button type="button" aria-label={`One more ${item.name}`} onClick={() => setQty(item.productId, item.qty + 1)} className="flex h-full w-9 items-center justify-center text-muted hover:text-ink">
                        <Plus className="size-3.5" />
                      </button>
                    </div>
                    <button type="button" onClick={() => remove(item.productId)} className="flex items-center gap-1 text-sm text-muted hover:text-bad">
                      <Trash2 className="size-4" /> Remove
                    </button>
                  </div>
                </div>
                <p className="font-bold tabular">{formatKES(item.qty * item.price)}</p>
              </li>
            ))}
          </ul>
          <div className="mt-6 flex flex-col gap-4 rounded-card bg-white p-5 ring-1 ring-line sm:flex-row sm:items-center sm:justify-between">
            <p>
              <span className="text-muted">Total </span>
              <span className="text-2xl font-bold text-navy tabular">{formatKES(total)}</span>
              {store.delivery?.enabled && <span className="block text-sm text-muted">Delivery is chosen at checkout; pickup is free.</span>}
            </p>
            <Button to={`${base}/checkout`} size="lg">Checkout with M-PESA</Button>
          </div>
        </>
      )}
    </div>
  )
}

export { CartPage as Component }
