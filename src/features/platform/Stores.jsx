import { useState } from 'react'
import { Link, useFetcher, useLoaderData, useNavigate, useRevalidator } from 'react-router'
import { Activity, Copy, ExternalLink, Globe, KeyRound, LayoutDashboard, MoreHorizontal, Search } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { Field } from '@/components/ui/Field'
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
  try {
    if (fd.get('intent') === 'reset-password') return { reset: await api.resetOwnerPassword(fd.get('storeId')) }
    return { store: await api.setStoreStatus(fd.get('storeId'), fd.get('status')) }
  } catch (e) {
    return { error: e.message }
  }
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
        <div className="relative flex gap-1 overflow-x-auto rounded-lg bg-white p-1 ring-1 ring-line">
          {STATUS_FILTERS.map(([id, label]) => (
            <button key={id || 'all'} type="button" aria-pressed={status === id} onClick={() => setStatus(id)} className={cn('shrink-0 rounded-md px-3 py-1.5 text-sm', status === id ? 'bg-navy text-white' : 'text-muted hover:text-ink')}>
              {label}
            </button>
          ))}
        </div>
      </div>

      <div className="relative mt-4 overflow-x-auto rounded-card bg-white ring-1 ring-line">
        <table className="w-full min-w-[64rem] text-sm">
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
  const reset = fetcher.data?.reset
  const [showReset, setShowReset] = useState(false)
  const [showDomain, setShowDomain] = useState(false)
  return (
    <tr>
      <td className="px-4 py-3">
        <span className="block font-medium">{store.name}</span>
        <a href={`/s/${store.slug}`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs text-muted hover:text-navy">
          {storeHost(store.slug)} <ExternalLink className="size-3" />
        </a>
        {store.customDomain && (
          <span className={cn('mt-0.5 flex items-center gap-1 text-xs', store.domainStatus === 'connected' ? 'text-good' : 'font-medium text-[#7a4f00]')}>
            <Globe className="size-3" />{store.customDomain}{store.domainStatus === 'connected' ? '' : ': to connect'}
          </span>
        )}
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
          <button type="button" onClick={openShopAdmin} className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 font-medium whitespace-nowrap text-navy hover:bg-concrete">
            <LayoutDashboard className="size-4" /> Open shop admin
          </button>
          <input type="hidden" name="storeId" value={store.id} />
          <details className="relative">
            <summary className="flex cursor-pointer list-none items-center rounded-lg p-1.5 text-muted hover:bg-concrete hover:text-ink" aria-label={`More for ${store.name}`}>
              <MoreHorizontal className="size-5" />
            </summary>
            <div className="absolute right-0 z-10 mt-1 w-56 rounded-lg bg-white p-1 text-left shadow-lift ring-1 ring-line">
              <Link to={`/admin/activity?storeId=${store.id}`} className="flex items-center gap-2 rounded-md px-3 py-2 hover:bg-concrete">
                <Activity className="size-4 text-steel" /> See activity
              </Link>
              <button type="button" onClick={() => setShowDomain(true)} className="flex w-full items-center gap-2 rounded-md px-3 py-2 hover:bg-concrete">
                <Globe className="size-4 text-steel" /> {store.customDomain ? 'Own domain…' : 'Connect a domain…'}
              </button>
              <button
                name="intent"
                value="reset-password"
                className="flex w-full items-center gap-2 rounded-md px-3 py-2 hover:bg-concrete"
                onClick={(e) => {
                  if (!window.confirm(`Give ${store.owner?.name ?? 'the owner'} a temporary password? Their current password stops working.`)) e.preventDefault()
                  else setShowReset(true)
                }}
              >
                <KeyRound className="size-4 text-steel" /> Reset owner’s password
              </button>
            </div>
          </details>
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
        {fetcher.data?.error && <p className="mt-1 text-xs text-bad" role="alert">{fetcher.data.error}</p>}
        {showDomain && <DomainDialog store={store} onClose={() => setShowDomain(false)} />}
        <Dialog open={showReset && Boolean(reset)} onClose={() => setShowReset(false)} title="Temporary password">
          {reset && <TempPassword reset={reset} store={store} />}
        </Dialog>
      </td>
    </tr>
  )
}

function TempPassword({ reset, store }) {
  const [copied, setCopied] = useState(false)
  async function copy() {
    try {
      await navigator.clipboard.writeText(reset.temporaryPassword)
      setCopied(true)
    } catch {
      setCopied(false)
    }
  }
  const message = `Hi ${reset.owner}, your temporary Topline password is ${reset.temporaryPassword}. Log in and you'll be asked to choose a new one.`
  return (
    <div className="text-left">
      <p className="text-sm text-muted">Give this to {reset.owner} ({store.name}). It’s shown only once.</p>
      <div className="mt-4 flex items-center justify-between gap-3 rounded-lg bg-concrete px-4 py-3">
        <code className="text-xl font-bold tracking-wider text-navy">{reset.temporaryPassword}</code>
        <button type="button" onClick={copy} className="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium text-navy hover:bg-white">
          <Copy className="size-4" /> {copied ? 'Copied' : 'Copy'}
        </button>
      </div>
      <p className="mt-3 text-sm text-muted">When they log in with it, they must choose their own password before anything else.</p>
      {store.owner?.phone && (
        <a
          href={`https://wa.me/${store.owner.phone.replace(/\D/g, '').replace(/^0/, '254')}?text=${encodeURIComponent(message)}`}
          target="_blank"
          rel="noreferrer"
          className="mt-4 inline-flex items-center gap-2 rounded-lg bg-good px-4 py-2.5 text-sm font-medium text-white hover:opacity-90"
        >
          Send it on WhatsApp
        </a>
      )}
    </div>
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

/**
 * The domain Topline bought for this shop. Steps outside Topline: buy it (e.g. Truehost), point its DNS
 * to the hosting, add it in hPanel as a parked domain and install SSL. Then save it here as connected.
 */
function DomainDialog({ store, onClose }) {
  const revalidator = useRevalidator()
  const [domain, setDomain] = useState(store.customDomain ?? '')
  const [connected, setConnected] = useState(store.domainStatus === 'connected' || !store.customDomain)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  async function save(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.setStoreDomain(store.id, { customDomain: domain, ...(domain.trim() ? { domainStatus: connected ? 'connected' : 'pending' } : {}) })
      revalidator.revalidate()
      onClose()
    } catch (e2) {
      setError(e2.message)
      setBusy(false)
    }
  }
  return (
    <Dialog open onClose={onClose} title={`${store.name}: own domain`}>
      <form onSubmit={save} className="space-y-4">
        <ol className="list-decimal space-y-1 pl-5 text-sm text-muted">
          <li>Buy the domain (e.g. at Truehost) and point it to the hosting: A records for <em>@</em> and <em>www</em> to the IP in hPanel → Websites → Dashboard.</li>
          <li>In hPanel, add it as a <strong>parked domain</strong> of toplinehub.co.ke, then install SSL for it (Security → SSL).</li>
          <li>Save it here. From then on it opens this shop.</li>
        </ol>
        <Field label="Domain" value={domain} onChange={(e) => setDomain(e.target.value)} placeholder="mjengohardware.co.ke" hint="Leave empty to remove the shop’s domain." error={error ?? undefined} />
        {domain.trim() && (
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={connected} onChange={(e) => setConnected(e.target.checked)} className="size-4 accent-navy" />
            Set up in hPanel with SSL: connect it now
          </label>
        )}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="navy" pending={busy}>Save</Button>
        </div>
      </form>
    </Dialog>
  )
}

export { Stores as Component }
