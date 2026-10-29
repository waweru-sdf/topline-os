import { useState } from 'react'
import { useSearchParams } from 'react-router'
import { Clock, FileText, MapPin, MessageCircle, Search, Truck } from 'lucide-react'
import { cn } from '@/lib/cn'
import { Img } from '@/components/ui/Img'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { ProductCard } from './ProductCard'
import { QuoteDialog } from './QuoteDialog'
import { useStore } from './useStore'

function StoreHome() {
  const { store, categories, products, base } = useStore()
  const [params, setParams] = useSearchParams()
  const [quoteOpen, setQuoteOpen] = useState(false)
  const category = params.get('c') ?? ''
  const query = params.get('q') ?? ''

  const terms = query.toLowerCase().split(/\s+/).filter(Boolean)
  const visible = products.filter(
    (p) => (!category || p.categoryId === category) && terms.every((t) => `${p.name} ${p.description}`.toLowerCase().includes(t)),
  )

  // Keep filters in the URL so a customer can share "?c=steel" with a fundi.
  const setFilter = (key, value) =>
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (value) next.set(key, value)
        else next.delete(key)
        return next
      },
      { replace: true, preventScrollReset: true },
    )

  return (
    <>
      <section className="relative bg-navy-deep text-white">
        <Img name={store.cover} alt="" w={1600} h={600} priority className="absolute inset-0 size-full opacity-35" />
        <div className="relative mx-auto max-w-6xl px-5 py-12 sm:py-16">
          <h1 className="max-w-2xl text-3xl font-bold tracking-tight sm:text-5xl">{store.name}</h1>
          {store.tagline && <p className="mt-3 max-w-xl text-lg text-mist">{store.tagline}</p>}
          <ul className="mt-6 flex flex-wrap gap-x-6 gap-y-2 text-sm text-mist">
            <li className="flex items-center gap-2"><MapPin className="size-4 text-amber" />{store.address || store.town}</li>
            {store.hours && <li className="flex items-center gap-2"><Clock className="size-4 text-amber" />{store.hours}</li>}
            {store.delivery?.enabled && <li className="flex items-center gap-2"><Truck className="size-4 text-amber" />Delivery available</li>}
          </ul>
          <div className="mt-7 flex flex-wrap gap-3">
            <Button onClick={() => setQuoteOpen(true)}><FileText className="size-4" />Request a quote</Button>
            {store.whatsapp && (
              <Button href={`https://wa.me/${store.whatsapp}`} variant="ghostLight"><MessageCircle className="size-4" />Order on WhatsApp</Button>
            )}
          </div>
        </div>
      </section>

      <div className="mx-auto max-w-6xl px-4 sm:px-5">
        <div className="sticky top-16 z-20 -mx-4 bg-concrete/95 px-4 pt-5 pb-3 backdrop-blur sm:-mx-5 sm:px-5">
          <label className="relative block">
            <span className="sr-only">Search products</span>
            <Search aria-hidden className="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-steel" />
            <input
              type="search"
              defaultValue={query}
              onChange={(e) => setFilter('q', e.target.value)}
              placeholder={`Search ${products.length} products`}
              className="h-12 w-full rounded-lg border border-line bg-white pr-4 pl-11 focus:border-navy focus:ring-3 focus:ring-navy/15 focus:outline-none"
            />
          </label>
          <div className="mt-3 flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none]" role="group" aria-label="Filter by category">
            {[{ id: '', name: 'All' }, ...categories].map((c) => (
              <button
                key={c.id || 'all'}
                type="button"
                aria-pressed={category === c.id}
                onClick={() => setFilter('c', c.id)}
                className={cn(
                  'shrink-0 rounded-full px-4 py-2 text-sm font-medium transition-colors',
                  category === c.id ? 'bg-navy text-white' : 'bg-white text-ink ring-1 ring-line hover:ring-steel',
                )}
              >
                {c.name}
              </button>
            ))}
          </div>
        </div>

        <h2 className="sr-only">Products</h2>
        <p className="mt-3 text-sm text-muted" aria-live="polite">
          {visible.length === products.length ? `${products.length} products` : `${visible.length} of ${products.length} products`}
        </p>
        {visible.length ? (
          <div className="mt-4 grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3 lg:grid-cols-4">
            {visible.map((p) => <ProductCard key={p.id} product={p} base={base} />)}
          </div>
        ) : (
          <div className="mt-4">
            <EmptyState
              icon={Search}
              title="Nothing matches that search"
              action={<Button variant="outline" onClick={() => setQuoteOpen(true)}>Ask the shop for it</Button>}
            >
              The shop may still have it. Send them a quote request and they’ll get back to you.
            </EmptyState>
          </div>
        )}
      </div>

      <QuoteDialog open={quoteOpen} onClose={() => setQuoteOpen(false)} store={store} initialItems={query} />
    </>
  )
}

export { StoreHome as Component }
