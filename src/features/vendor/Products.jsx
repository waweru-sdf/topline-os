import { useState } from 'react'
import { Link, useFetcher, useLoaderData } from 'react-router'
import { ArrowLeftRight, Minus, Package, Plus, Search } from 'lucide-react'
import { api } from '@/services/api'
import { formatKES } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Img } from '@/components/ui/Img'
import { EmptyState } from '@/components/ui/EmptyState'
import { Dialog } from '@/components/ui/Dialog'
import { Field } from '@/components/ui/Field'
import { branchParam, useVendor } from './VendorLayout'
import { requireStoreId } from './guard'

export async function loader() {
  const storeId = requireStoreId()
  return api.listProducts(storeId, branchParam(storeId))
}

/** Quick +/- stock at the current branch, or moving stock between branches. */
export async function action({ request }) {
  const storeId = requireStoreId()
  const fd = await request.formData()
  try {
    if (fd.get('intent') === 'transfer') {
      await api.transferStock(storeId, fd.get('productId'), { fromBranchId: fd.get('from'), toBranchId: fd.get('to'), qty: Number(fd.get('qty')) })
      return { moved: true }
    }
    await api.adjustStock(storeId, fd.get('productId'), Number(fd.get('delta')), branchParam(storeId))
    return null
  } catch (e) {
    return { error: e.message }
  }
}

const FILTERS = [
  ['all', 'All'],
  ['low', 'Running low'],
  ['out', 'Out of stock'],
  ['hidden', 'Hidden from store'],
]

function Products() {
  const { products, categories, stockByBranch } = useLoaderData()
  const { store, branch } = useVendor()
  const can = (perm) => store.permissions?.includes(perm)
  const multi = store.branches.length > 1
  const [moving, setMoving] = useState(null) // product whose stock is being moved between branches
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
      <PageHeader title="Products & stock" actions={can('manage_products') && <Button to="/dashboard/products/new" variant="navy"><Plus className="size-4" />Add product</Button>}>
        {products.length} products{multi && `; stock shown is at ${branch.name}`}{can('view_costs') && `, worth ${formatKES(stockValue)} at buying price`}.
      </PageHeader>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <label className="relative flex-1">
          <span className="sr-only">Search products</span>
          <Search aria-hidden className="pointer-events-none absolute top-1/2 left-3 size-4.5 -translate-y-1/2 text-steel" />
          <input type="search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search products" className="h-11 w-full rounded-lg border border-line bg-white pr-3 pl-10 focus:border-navy focus:ring-3 focus:ring-navy/15 focus:outline-none" />
        </label>
        <div className="relative flex gap-1 overflow-x-auto rounded-lg bg-white p-1 ring-1 ring-line">
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
        <div className="relative mt-4 overflow-x-auto rounded-card bg-white ring-1 ring-line">
          <table className="w-full min-w-[46rem] text-sm">
            <thead className="text-left text-muted">
              <tr className="border-b border-line">
                <th className="px-4 py-3 font-medium">Product</th>
                <th className="px-3 py-3 text-right font-medium">Selling price</th>
                {can('view_costs') && <th className="px-3 py-3 text-right font-medium">Buying price</th>}
                <th className="px-3 py-3 text-center font-medium">{multi ? `In stock at ${branch.name}` : 'In stock'}</th>
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
                  {can('view_costs') && <td className="px-3 py-3 text-right text-muted tabular">
                    {p.buyPrice > 0 ? (
                      <>
                        {formatKES(p.buyPrice)}
                        <span className="block text-xs">{Math.round(((p.sellPrice - p.buyPrice) / p.sellPrice) * 100)}% margin</span>
                      </>
                    ) : (
                      <Link to={`/dashboard/products/${p.id}`} className="text-xs text-navy hover:underline">Add buying price</Link>
                    )}
                  </td>}
                  <td className="px-3 py-3">
                    {can('manage_products') ? <StockStepper product={p} /> : <span className="block text-center font-bold tabular">{p.stock}</span>}
                    {stockByBranch && !store.myBranchId && (
                      <button type="button" onClick={() => setMoving(p)} className="mx-auto mt-1 flex items-center gap-1 text-xs text-navy hover:underline">
                        <ArrowLeftRight className="size-3" />Branches
                      </button>
                    )}
                  </td>
                  <td className="px-4 py-3">{p.published ? <Badge tone="good">Visible</Badge> : <Badge>Hidden</Badge>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {moving && <MoveStock product={moving} branches={store.branches} stock={stockByBranch} onClose={() => setMoving(null)} />}
    </>
  )
}

/** Stock at every branch, and moving some from one branch to another. */
function MoveStock({ product, branches, stock, onClose }) {
  const fetcher = useFetcher()
  const [from, setFrom] = useState(branches[0].id)
  const [to, setTo] = useState(branches[1].id)
  const busy = fetcher.state !== 'idle'
  const done = fetcher.state === 'idle' && fetcher.data?.moved
  return (
    <Dialog open onClose={onClose} title={`${product.name}: stock by branch`}>
      <ul className="divide-y divide-line rounded-lg ring-1 ring-line">
        {branches.map((b) => (
          <li key={b.id} className="flex justify-between px-4 py-2.5 text-sm">
            <span>{b.name}</span><span className="font-bold tabular">{stock[b.id]?.[product.id] ?? 0} {product.unit}</span>
          </li>
        ))}
      </ul>
      <fetcher.Form method="post" className="mt-5 space-y-4">
        <input type="hidden" name="intent" value="transfer" />
        <input type="hidden" name="productId" value={product.id} />
        <div className="grid grid-cols-2 gap-3">
          <Field as="select" name="from" label="Move from" value={from} onChange={(e) => setFrom(e.target.value)}>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Field>
          <Field as="select" name="to" label="To" value={to} onChange={(e) => setTo(e.target.value)}>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Field>
        </div>
        <Field name="qty" label={`How many (${product.unit})`} inputMode="numeric" required />
        {fetcher.data?.error && <p className="text-sm text-bad" role="alert">{fetcher.data.error}</p>}
        {done && <p className="text-sm text-good" role="status">Moved. The counts above are up to date.</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Close</Button>
          <Button type="submit" variant="navy" pending={busy} disabled={from === to}>Move stock</Button>
        </div>
      </fetcher.Form>
    </Dialog>
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
