import { useState } from 'react'
import { Link, useFetcher, useLoaderData } from 'react-router'
import { Minus, Package, Plus, Search } from 'lucide-react'
import { api } from '@/services/api'
import { formatKES } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Img } from '@/components/ui/Img'
import { EmptyState } from '@/components/ui/EmptyState'
import { requireStoreId } from './guard'

export async function loader() {
  return api.listProducts(requireStoreId())
}

/** Quick +/- stock adjustment from the list. */
export async function action({ request }) {
  const fd = await request.formData()
  await api.adjustStock(requireStoreId(), fd.get('productId'), Number(fd.get('delta')))
  return null
}

const FILTERS = [
  ['all', 'All'],
  ['low', 'Running low'],
  ['out', 'Out of stock'],
  ['hidden', 'Hidden from store'],
]

function Products() {
  const { products, categories } = useLoaderData()
  const [query, setQuery] = useState('')
  const [filter, setFilter] = useState('all')
  const catName = Object.fromEntries(categories.map((c) => [c.id, c.name]))

  const terms = query.toLowerCase().split(/\s+/).filter(Boolean)
  const rows = products.filter((p) => {
    if (!terms.every((t) => p.name.toLowerCase().includes(t))) return false
    if (filter === 'low') return p.stock > 0 && p.stock <= p.reorderAt
    if (filter === 'out') return p.stock <= 0
    if (filter === 'hidden') return !p.published
    return true
  })
  const stockValue = products.reduce((s, p) => s + Math.max(0, p.stock) * p.buyPrice, 0)

  return (
    <>
      <title>Products — Topline</title>
      <PageHeader title="Products & stock" actions={<Button to="/dashboard/products/new" variant="navy"><Plus className="size-4" />Add product</Button>}>
        {products.length} products, stock worth {formatKES(stockValue)} at buying price.
      </PageHeader>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <label className="relative flex-1">
          <span className="sr-only">Search products</span>
          <Search aria-hidden className="pointer-events-none absolute top-1/2 left-3 size-4.5 -translate-y-1/2 text-steel" />
          <input type="search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search products" className="h-11 w-full rounded-lg border border-line bg-white pr-3 pl-10 focus:border-navy focus:ring-3 focus:ring-navy/15 focus:outline-none" />
        </label>
        <div className="flex gap-1 overflow-x-auto rounded-lg bg-white p-1 ring-1 ring-line">
          {FILTERS.map(([id, label]) => (
            <button key={id} type="button" aria-pressed={filter === id} onClick={() => setFilter(id)} className={cn('shrink-0 rounded-md px-3 py-1.5 text-sm', filter === id ? 'bg-navy text-white' : 'text-muted hover:text-ink')}>
              {label}
            </button>
          ))}
        </div>
      </div>

      {rows.length === 0 ? (
        <div className="mt-6">
          <EmptyState icon={Package} title={products.length ? 'No products match' : 'Add your first product'} action={!products.length && <Button to="/dashboard/products/new" variant="navy">Add product</Button>}>
            {products.length ? 'Try another search or filter.' : 'Add what you sell with a price per bag, length or metre. It shows on your store straight away.'}
          </EmptyState>
        </div>
      ) : (
        <div className="mt-4 overflow-x-auto rounded-card bg-white ring-1 ring-line">
          <table className="w-full min-w-[46rem] text-sm">
            <thead className="text-left text-muted">
              <tr className="border-b border-line">
                <th className="px-4 py-3 font-medium">Product</th>
                <th className="px-3 py-3 text-right font-medium">Selling price</th>
                <th className="px-3 py-3 text-right font-medium">Buying price</th>
                <th className="px-3 py-3 text-center font-medium">In stock</th>
                <th className="px-4 py-3 font-medium">On store</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {rows.map((p) => (
                <tr key={p.id} className="hover:bg-concrete/50">
                  <td className="px-4 py-3">
                    <Link to={`/dashboard/products/${p.id}`} className="flex items-center gap-3">
                      <Img name={p.image} alt="" w={96} h={96} className="size-11 shrink-0 rounded-md" />
                      <span className="min-w-0">
                        <span className="block font-medium hover:underline">{p.name}</span>
                        <span className="block text-xs text-muted">{catName[p.categoryId]}, per {p.unit}</span>
                      </span>
                    </Link>
                  </td>
                  <td className="px-3 py-3 text-right font-medium tabular">{formatKES(p.sellPrice)}</td>
                  <td className="px-3 py-3 text-right text-muted tabular">
                    {p.buyPrice > 0 ? (
                      <>
                        {formatKES(p.buyPrice)}
                        <span className="block text-xs">{Math.round(((p.sellPrice - p.buyPrice) / p.sellPrice) * 100)}% margin</span>
                      </>
                    ) : (
                      <Link to={`/dashboard/products/${p.id}`} className="text-xs text-navy hover:underline">Add buying price</Link>
                    )}
                  </td>
                  <td className="px-3 py-3"><StockStepper product={p} /></td>
                  <td className="px-4 py-3">{p.published ? <Badge tone="good">Visible</Badge> : <Badge>Hidden</Badge>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}

function StockStepper({ product }) {
  const fetcher = useFetcher()
  // Optimistic: show the new count while the request is in flight.
  const pendingDelta = fetcher.formData ? Number(fetcher.formData.get('delta')) : 0
  const stock = product.stock + pendingDelta
  const tone = stock <= 0 ? 'text-bad' : stock <= product.reorderAt ? 'text-amber-deep' : 'text-ink'

  return (
    <fetcher.Form method="post" className="flex items-center justify-center gap-1.5">
      <input type="hidden" name="productId" value={product.id} />
      <button name="delta" value="-1" aria-label={`Remove one ${product.name}`} className="flex size-7 items-center justify-center rounded-md ring-1 ring-line hover:bg-concrete"><Minus className="size-3" /></button>
      <span className={cn('w-12 text-center font-bold tabular', tone)}>{stock}</span>
      <button name="delta" value="1" aria-label={`Add one ${product.name}`} className="flex size-7 items-center justify-center rounded-md ring-1 ring-line hover:bg-concrete"><Plus className="size-3" /></button>
    </fetcher.Form>
  )
}

export { Products as Component }
