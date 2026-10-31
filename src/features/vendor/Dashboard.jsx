import { Link, useLoaderData } from 'react-router'
import { AlertTriangle, Plus, Receipt, TrendingDown, TrendingUp } from 'lucide-react'
import { api } from '@/services/api'
import { formatKES, formatTime, formatDate, pluralize } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { METHOD_LABEL } from '@/lib/labels'
import { SalesChart } from '@/components/SalesChart'
import { useVendor } from './VendorLayout'
import { requireStoreId } from './guard'

export async function loader() {
  return api.getDashboard(requireStoreId())
}

function Dashboard() {
  const d = useLoaderData()
  const { user, store } = useVendor()
  const change = d.revenuePrev7 ? Math.round(((d.revenue7 - d.revenuePrev7) / d.revenuePrev7) * 100) : null

  return (
    <>
      <title>Overview — Topline</title>
      <PageHeader
        title={user.role === 'vendor' ? `Habari, ${user.name.split(' ')[0]}` : store.name}
        actions={
          <>
            <Button to="/dashboard/products/new" variant="outline"><Plus className="size-4" />Add product</Button>
            <Button to="/dashboard/sell" variant="navy"><Receipt className="size-4" />New sale</Button>
          </>
        }
      >
        Here’s how the shop did over the last 7 days.
      </PageHeader>

      {/* Headline numbers as one strip, not a row of identical cards */}
      <section aria-label="Last 7 days" className="grid divide-y divide-line rounded-card bg-white ring-1 ring-line sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 lg:divide-x">
        <Metric label="Sales" value={formatKES(d.revenue7)}>
          {change !== null && (
            <span className={cn('inline-flex items-center gap-1', change >= 0 ? 'text-good' : 'text-bad')}>
              {change >= 0 ? <TrendingUp className="size-4" /> : <TrendingDown className="size-4" />}
              {change >= 0 ? '+' : ''}{change}% on the week before
            </span>
          )}
        </Metric>
        <Metric label="Profit" value={formatKES(d.profit7)}>
          {d.revenue7 ? `${Math.round((d.profit7 / d.revenue7) * 100)}% margin` : null}
        </Metric>
        <Metric label="Customers owe you" value={formatKES(d.owedToYou)}>
          {d.overdueCustomers ? <Link to="/dashboard/customers" className="text-bad hover:underline">{pluralize(d.overdueCustomers, 'customer')} overdue</Link> : 'Nobody overdue'}
        </Metric>
        <Metric label="New online orders" value={d.newOrders}>
          <Link to="/dashboard/orders" className="text-navy hover:underline">{d.newOrders ? 'Confirm them' : 'View orders'}</Link>
        </Metric>
      </section>

      <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <section className="rounded-card bg-white p-5 ring-1 ring-line">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="font-bold">Daily sales</h2>
            <div className="flex gap-4 text-xs text-muted">
              <span className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-navy" />Sales</span>
              <span className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-amber" />Profit</span>
              <span className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-mist" />Week before</span>
            </div>
          </div>
          <div className="mt-4"><SalesChart days={d.byDay} /></div>
        </section>

        <section className="rounded-card bg-white p-5 ring-1 ring-line">
          <h2 className="flex items-center gap-2 font-bold">
            <AlertTriangle className="size-4 text-amber-deep" aria-hidden /> Running low
          </h2>
          {d.lowStock.length ? (
            <ul className="mt-3 divide-y divide-line">
              {d.lowStock.slice(0, 6).map((p) => (
                <li key={p.id} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                  <Link to={`/dashboard/products/${p.id}`} className="min-w-0 truncate hover:underline">{p.name}</Link>
                  <span className={cn('shrink-0 font-medium tabular', p.stock <= 0 ? 'text-bad' : 'text-amber-deep')}>
                    {p.stock <= 0 ? 'Out' : `${p.stock} left`}
                  </span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="mt-3 text-sm text-muted">Everything is above its reorder level.</p>
          )}
        </section>
      </div>

      <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <section className="overflow-hidden rounded-card bg-white ring-1 ring-line">
          <h2 className="px-5 pt-5 font-bold">Latest sales</h2>
          <div className="mt-3 overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-muted">
                <tr className="border-b border-line">
                  <th className="px-5 py-2 font-medium">Sale</th>
                  <th className="px-3 py-2 font-medium">Items</th>
                  <th className="px-3 py-2 font-medium">Paid by</th>
                  <th className="px-5 py-2 text-right font-medium">Total</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {d.recent.map((s) => (
                  <tr key={s.id}>
                    <td className="px-5 py-3 whitespace-nowrap">
                      <span className="font-medium">{s.id}</span>
                      <span className="block text-xs text-muted">{formatDate(s.createdAt)}, {formatTime(s.createdAt)}</span>
                    </td>
                    <td className="max-w-[16rem] truncate px-3 py-3">{s.items.map((i) => `${i.qty} ${i.name}`).join(', ')}</td>
                    <td className="px-3 py-3"><Badge tone={s.channel === 'online' ? 'navy' : 'neutral'}>{s.channel === 'online' ? 'Online' : METHOD_LABEL[s.method]}</Badge></td>
                    <td className="px-5 py-3 text-right font-medium tabular">{formatKES(s.total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>

        <section className="rounded-card bg-white p-5 ring-1 ring-line">
          <h2 className="font-bold">Best sellers</h2>
          <ol className="mt-3 space-y-3">
            {d.topProducts.map((p) => {
              const pct = (p.revenue / d.topProducts[0].revenue) * 100
              return (
                <li key={p.name} className="text-sm">
                  <div className="flex justify-between gap-3">
                    <span className="truncate">{p.name}</span>
                    <span className="shrink-0 font-medium tabular">{formatKES(p.revenue)}</span>
                  </div>
                  <div className="mt-1.5 h-1.5 rounded-full bg-concrete">
                    <div className="h-full rounded-full bg-navy" style={{ width: `${pct}%` }} />
                  </div>
                </li>
              )
            })}
          </ol>
        </section>
      </div>
    </>
  )
}

function Metric({ label, value, children }) {
  return (
    <div className="p-5">
      <p className="text-sm text-muted">{label}</p>
      <p className="mt-1 text-2xl font-bold text-ink tabular">{value}</p>
      {children && <p className="mt-1 text-sm text-muted">{children}</p>}
    </div>
  )
}

export { Dashboard as Component }
