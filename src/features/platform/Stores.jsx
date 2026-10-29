import { useState } from 'react'
import { useFetcher, useLoaderData, useNavigate } from 'react-router'
import { ExternalLink, LayoutDashboard, Search } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate, formatKES } from '@/lib/format'
import { storeHost } from '@/lib/tenant'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { StoreStatus } from '@/components/ui/Badge'
import { requirePlatformAdmin } from '@/features/vendor/guard'
import { setActingStore } from '@/services/session'

export async function loader() {
  requirePlatformAdmin()
  return { stores: await api.listAllStores() }
}

export async function action({ request }) {
  requirePlatformAdmin()
  const fd = await request.formData()
  return api.setStoreStatus(fd.get('storeId'), fd.get('status'))
}

const STATUS_FILTERS = [
  ['', 'All'],
  ['active', 'Active'],
  ['past_due', 'Payment due'],
  ['suspended', 'Suspended'],
  ['draft', 'Not paid yet'],
]

function Stores() {
  const { stores } = useLoaderData()
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState('')

  const mrr = stores.filter((s) => s.status === 'active').reduce((sum, s) => sum + (s.plan?.priceMonthly ?? 0), 0)
  const gmv = stores.reduce((sum, s) => sum + s.gmv30, 0)
  const count = (st) => stores.filter((s) => s.status === st).length

  const q = query.toLowerCase()
  const rows = stores.filter((s) => (!status || s.status === status) && `${s.name} ${s.town} ${s.owner?.name} ${s.slug}`.toLowerCase().includes(q))

  return (
    <>
      <title>Stores — Topline admin</title>
      <PageHeader title="Stores">Every hardware store on Topline.</PageHeader>

      <section aria-label="Platform totals" className="grid divide-y divide-line rounded-card bg-white ring-1 ring-line sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 lg:divide-x">
        <Total label="Monthly subscription revenue" value={formatKES(mrr)} note={`${count('active')} paying stores`} />
        <Total label="Sales through stores" value={formatKES(gmv)} note="All stores, recent activity" />
        <Total label="Payment due" value={count('past_due')} note="In their 7-day grace period" tone={count('past_due') ? 'text-amber-deep' : undefined} />
        <Total label="Suspended or unpaid" value={count('suspended') + count('draft')} note="Storefront shows as closed" />
      </section>

      <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
        <label className="relative flex-1">
          <span className="sr-only">Search stores</span>
          <Search aria-hidden className="pointer-events-none absolute top-1/2 left-3 size-4.5 -translate-y-1/2 text-steel" />
          <input type="search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search by store, town or owner" className="h-11 w-full rounded-lg border border-line bg-white pr-3 pl-10 focus:border-navy focus:ring-3 focus:ring-navy/15 focus:outline-none" />
        </label>
        <div className="flex gap-1 overflow-x-auto rounded-lg bg-white p-1 ring-1 ring-line">
          {STATUS_FILTERS.map(([id, label]) => (
            <button key={id || 'all'} type="button" aria-pressed={status === id} onClick={() => setStatus(id)} className={cn('shrink-0 rounded-md px-3 py-1.5 text-sm', status === id ? 'bg-navy text-white' : 'text-muted hover:text-ink')}>
              {label}
            </button>
          ))}
        </div>
      </div>

      <div className="mt-4 overflow-x-auto rounded-card bg-white ring-1 ring-line">
        <table className="w-full min-w-[56rem] text-sm">
          <thead className="text-left text-muted">
            <tr className="border-b border-line">
              <th className="px-4 py-3 font-medium">Store</th>
              <th className="px-3 py-3 font-medium">Owner</th>
              <th className="px-3 py-3 font-medium">Plan</th>
              <th className="px-3 py-3 font-medium">Status</th>
              <th className="px-3 py-3 font-medium">Next payment</th>
              <th className="px-3 py-3 text-right font-medium">Products</th>
              <th className="px-3 py-3 text-right font-medium">Sales</th>
              <th className="px-4 py-3"><span className="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {rows.map((s) => <StoreRow key={s.id} store={s} />)}
          </tbody>
        </table>
        {rows.length === 0 && <p className="px-4 py-8 text-center text-muted">No stores match.</p>}
      </div>
    </>
  )
}

function StoreRow({ store }) {
  const fetcher = useFetcher()
  const navigate = useNavigate()
  function openShopAdmin() {
    setActingStore(store.id)
    navigate('/dashboard')
  }
  const status = fetcher.formData?.get('status') ?? store.status
  return (
    <tr>
      <td className="px-4 py-3">
        <span className="block font-medium">{store.name}</span>
        <a href={`/s/${store.slug}`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs text-muted hover:text-navy">
          {storeHost(store.slug)} <ExternalLink className="size-3" />
        </a>
      </td>
      <td className="px-3 py-3">
        <span className="block">{store.owner?.name}</span>
        <span className="block text-xs text-muted">{store.town}, {store.county}</span>
      </td>
      <td className="px-3 py-3">{store.plan?.name}</td>
      <td className="px-3 py-3"><StoreStatus status={status} /></td>
      <td className="px-3 py-3 text-muted">{store.nextBillingAt ? formatDate(store.nextBillingAt) : '—'}</td>
      <td className="px-3 py-3 text-right tabular">{store.products}</td>
      <td className="px-3 py-3 text-right font-medium tabular">{formatKES(store.gmv30)}</td>
      <td className="px-4 py-3 text-right">
        <fetcher.Form method="post" className="flex items-center justify-end gap-1">
          <button type="button" onClick={openShopAdmin} className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 font-medium text-navy hover:bg-concrete">
            <LayoutDashboard className="size-4" /> Open shop admin
          </button>
          <input type="hidden" name="storeId" value={store.id} />
          {status === 'suspended' ? (
            <button name="status" value="active" className="rounded-lg px-3 py-1.5 font-medium text-navy hover:bg-concrete">Reactivate</button>
          ) : status === 'draft' ? null : (
            <button
              name="status"
              value="suspended"
              className="rounded-lg px-3 py-1.5 font-medium text-bad hover:bg-bad-tint"
              onClick={(e) => { if (!window.confirm(`Suspend ${store.name}? Their store will show as closed.`)) e.preventDefault() }}
            >
              Suspend
            </button>
          )}
        </fetcher.Form>
      </td>
    </tr>
  )
}

function Total({ label, value, note, tone }) {
  return (
    <div className="p-5">
      <p className="text-sm text-muted">{label}</p>
      <p className={cn('mt-1 text-2xl font-bold tabular', tone ?? 'text-ink')}>{value}</p>
      <p className="mt-1 text-sm text-muted">{note}</p>
    </div>
  )
}

export { Stores as Component }
