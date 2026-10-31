import { useState } from 'react'
import { useSearchParams } from 'react-router'
import {
  Banknote, CircleUserRound, KeyRound, LoaderCircle, Package, PackageMinus, PackagePlus, Receipt, Settings2,
  ShieldAlert, ShoppingCart, Store, Wallet,
} from 'lucide-react'
import { formatDate, formatTime } from '@/lib/format'
import { cn } from '@/lib/cn'

/** What each kind of event looks like. Keys match the backend's activity kinds. */
const ACTIVITY_KINDS = {
  order_placed: ['Online order', ShoppingCart, 'text-navy'],
  order_paid: ['Order paid', Banknote, 'text-good'],
  order_status: ['Order update', Receipt, 'text-steel'],
  counter_sale: ['Counter sale', Receipt, 'text-good'],
  product_added: ['Product added', PackagePlus, 'text-navy'],
  product_updated: ['Product edited', Package, 'text-steel'],
  product_deleted: ['Product deleted', PackageMinus, 'text-bad'],
  stock_adjusted: ['Stock changed', Package, 'text-amber-deep'],
  payout_sent: ['Money sent to shop', Wallet, 'text-good'],
  payout_failed: ['Payout failed', Wallet, 'text-bad'],
  plan_paid: ['Plan paid', Banknote, 'text-good'],
  store_created: ['New shop', Store, 'text-navy'],
  store_status: ['Shop status', ShieldAlert, 'text-amber-deep'],
  settings_updated: ['Settings', Settings2, 'text-steel'],
  login: ['Log in', CircleUserRound, 'text-steel'],
  password_changed: ['Password changed', KeyRound, 'text-steel'],
  password_reset: ['Password reset', KeyRound, 'text-amber-deep'],
}

/**
 * The activity list with filters and "Show older".
 * entries: first page from the route loader. loadMore(params) fetches the next page.
 * stores: optional [{id, name}] for the shop filter (Topline admin only).
 */
export function ActivityFeed({ entries: firstPage, loadMore, stores }) {
  const [params, setParams] = useSearchParams()
  const [older, setOlder] = useState({ key: null, rows: [], done: false })
  const [loading, setLoading] = useState(false)
  const filterKey = params.toString()
  // Older pages belong to the current filters; changing a filter starts again from the first page.
  const extra = older.key === filterKey ? older : { key: filterKey, rows: [], done: false }
  const entries = [...firstPage, ...extra.rows]
  const done = extra.done || firstPage.length < 50

  function setFilter(name, value) {
    const next = new URLSearchParams(params)
    if (value) next.set(name, value)
    else next.delete(name)
    setParams(next, { replace: true })
  }

  async function showOlder() {
    setLoading(true)
    try {
      const rows = await loadMore({ storeId: params.get('storeId'), kind: params.get('kind'), before: entries.at(-1)?.id })
      setOlder({ key: filterKey, rows: [...extra.rows, ...rows], done: rows.length < 50 })
    } finally {
      setLoading(false)
    }
  }

  let lastDay = null
  return (
    <>
      <div className="mb-4 flex flex-col gap-3 sm:flex-row">
        {stores && (
          <label className="flex-1">
            <span className="sr-only">Shop</span>
            <select aria-label="Shop" value={params.get('storeId') ?? ''} onChange={(e) => setFilter('storeId', e.target.value)} className="h-11 w-full rounded-lg border border-line bg-white px-3">
              <option value="">All shops</option>
              {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </label>
        )}
        <label className="flex-1">
          <span className="sr-only">Kind of activity</span>
          <select aria-label="Kind of activity" value={params.get('kind') ?? ''} onChange={(e) => setFilter('kind', e.target.value)} className="h-11 w-full rounded-lg border border-line bg-white px-3">
            <option value="">Everything</option>
            {Object.entries(ACTIVITY_KINDS).map(([kind, [label]]) => <option key={kind} value={kind}>{label}</option>)}
          </select>
        </label>
      </div>

      {entries.length === 0 ? (
        <p className="rounded-card bg-white p-8 text-center text-muted ring-1 ring-line">Nothing here yet.</p>
      ) : (
        <ol className="rounded-card bg-white ring-1 ring-line">
          {entries.map((a) => {
            const day = formatDate(a.createdAt)
            const header = day !== lastDay
            lastDay = day
            const [label, Icon, tone] = ACTIVITY_KINDS[a.kind] ?? [a.kind, Package, 'text-steel']
            return (
              <li key={a.id}>
                {header && <p className="border-b border-line bg-concrete/60 px-4 py-2 text-xs font-medium tracking-wide text-muted uppercase first:rounded-t-card">{day}</p>}
                <div className="flex gap-3 border-b border-line px-4 py-3 last:border-b-0">
                  <Icon className={cn('mt-0.5 size-5 shrink-0', tone)} aria-hidden />
                  <div className="min-w-0 flex-1">
                    <p className="text-sm">{a.summary}</p>
                    <p className="mt-0.5 text-xs text-muted">
                      {formatTime(a.createdAt)} · {label} · {a.actor}
                      {stores && a.storeName && <> · <button type="button" onClick={() => setFilter('storeId', a.storeId)} className="text-navy hover:underline">{a.storeName}</button></>}
                    </p>
                  </div>
                </div>
              </li>
            )
          })}
        </ol>
      )}

      {!done && entries.length > 0 && (
        <div className="mt-4 text-center">
          <button type="button" onClick={showOlder} disabled={loading} className="inline-flex items-center gap-2 rounded-lg px-4 py-2 font-medium text-navy ring-1 ring-line hover:bg-white">
            {loading && <LoaderCircle className="size-4 animate-spin" />} Show older
          </button>
        </div>
      )}
    </>
  )
}
