import { Link } from 'react-router'
import { Check, Plus } from 'lucide-react'
import { useState } from 'react'
import { formatKES } from '@/lib/format'
import { Img } from '@/components/ui/Img'
import { useCart } from '@/context/CartContext'

export function stockLabel(product) {
  if (product.stock <= 0) return { text: 'Out of stock', tone: 'text-bad' }
  if (product.stock <= product.reorderAt) return { text: `Only ${product.stock} left`, tone: 'text-amber-deep' }
  return { text: 'In stock', tone: 'text-good' }
}

export function ProductCard({ product, base }) {
  const { add } = useCart()
  const [added, setAdded] = useState(false)
  const stock = stockLabel(product)
  const soldOut = product.stock <= 0

  function addToCart() {
    add(product, 1)
    setAdded(true)
    setTimeout(() => setAdded(false), 1400)
  }

  return (
    <article className="group flex flex-col overflow-hidden rounded-card bg-white ring-1 ring-line">
      <Link to={`${base}/p/${product.slug}`} className="block">
        <Img name={product.image} alt={product.name} w={480} h={360} sizes="(min-width: 1024px) 280px, 50vw" className="aspect-[4/3] w-full" />
      </Link>
      <div className="flex flex-1 flex-col p-4">
        <h3 className="leading-snug font-medium">
          <Link to={`${base}/p/${product.slug}`} className="hover:text-navy hover:underline">{product.name}</Link>
        </h3>
        {product.size && <p className="mt-1 text-xs text-muted">{product.size}</p>}
        <p className="mt-2">
          <span className="text-lg font-bold tabular">{formatKES(product.sellPrice)}</span>
          <span className="text-sm text-muted"> / {product.unit}</span>
        </p>
        <p className={`mt-1 text-xs font-medium ${stock.tone}`}>{stock.text}</p>
        <button
          type="button"
          onClick={addToCart}
          disabled={soldOut}
          className="mt-4 flex h-10 items-center justify-center gap-2 rounded-lg bg-navy text-sm font-medium text-white transition-colors hover:bg-navy-soft disabled:bg-concrete disabled:text-muted"
        >
          {added ? <Check className="size-4" /> : <Plus className="size-4" />}
          {soldOut ? 'Out of stock' : added ? 'Added' : 'Add to cart'}
        </button>
      </div>
    </article>
  )
}
