import { Link, useLoaderData } from 'react-router'
import { AlertTriangle, ArrowUpRight, CircleCheck } from 'lucide-react'
import { api } from '@/services/api'
import { formatKES } from '@/lib/format'
import { storeHost } from '@/lib/tenant'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { BarChart, Legend } from '@/components/BarChart'
import { requirePlatformAdmin } from '@/features/vendor/guard'

export async function loader() {
  requirePlatformAdmin()
  return api.getOverview()
}

const DAY = new Intl.DateTimeFormat('en-KE', { day: 'numeric', month: 'short' })
const short = (iso) => DAY.format(new Date(iso))
const TRAFFIC = [{ name: 'Main site', color: 'var(--color-navy)' }, { name: 'Shop pages', color: 'var(--color-amber)' }]

function Overview() {
  const { stores, money, customers, traffic, health } = useLoaderData()
  const errorRate = health.requests24 ? (health.serverErrors24 / health.requests24) * 100 : 0

  return (
    <>
      <title>Overview — Topline admin</title>
      <PageHeader title="Overview">The whole platform over the last 30 days.</PageHeader>

      <section aria-label="Key numbers" className="grid divide-y divide-line rounded-card bg-white ring-1 ring-line sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 lg:divide-x">
        <Kpi label="Subscription income per month" value={formatKES(stores.monthlyRevenue)} note={`${stores.byStatus.active} paying stores of ${stores.total}`} />
        <Kpi label="Sales through all shops" value={formatKES(money.salesValue)} note={`${formatKES(money.onlineValue)} online, ${money.onlineOrders} orders`} />
        <Kpi label="Customers buying online" value={customers.onlineBuyers} note={`${customers.newBuyers30} new, ${customers.repeatBuyers} came back`} />
        <Kpi label="Visits" value={traffic.total30.toLocaleString('en-KE')} note="Main site and shop pages" />
      </section>

      <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem] xl:items-start">
        <section className="rounded-card bg-white p-5 ring-1 ring-line">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="font-bold">Visits per day</h2>
            <Legend series={TRAFFIC} />
          </div>
          <div className="mt-4">
            <BarChart
              ariaLabel="Visits per day for the last 30 days"
              series={TRAFFIC}
              labelEvery={5}
              rows={traffic.byDay.map((d) => ({ label: short(d.date), title: `${short(d.date)}: ${d.site} main site, ${d.stores} shop pages`, values: [d.site, d.stores] }))}
            />
          </div>
        </section>

        <section className="rounded-card bg-white p-5 ring-1 ring-line">
          <h2 className="font-bold">Most visited shops</h2>
          <ol className="mt-3 divide-y divide-line">
            {traffic.topStores.map((s) => (
              <li key={s.storeId} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                <a href={`/s/${s.slug}`} target="_blank" rel="noreferrer" className="min-w-0 truncate hover:underline">{s.name}</a>
                <span className="shrink-0 tabular text-muted">{s.views.toLocaleString('en-KE')}</span>
              </li>
            ))}
          </ol>
        </section>
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <section className="rounded-card bg-white p-5 ring-1 ring-line">
          <h2 className="font-bold">Stores</h2>
          <dl className="mt-3 space-y-2 text-sm">
            {[['active', 'Active'], ['past_due', 'Payment due (grace period)'], ['suspended', 'Suspended'], ['draft', 'Signed up, not paid']].map(([k, label]) => (
              <div key={k} className="flex justify-between"><dt className="text-muted">{label}</dt><dd className="font-medium tabular">{stores.byStatus[k] ?? 0}</dd></div>
            ))}
          </dl>
          <p className="mt-4 text-sm text-muted">New stores in 30 days: <span className="font-medium text-ink">{stores.signupsByDay.reduce((a, d) => a + d.count, 0)}</span></p>
          <Link to="/admin/stores" className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-navy hover:underline">Manage stores <ArrowUpRight className="size-4" /></Link>
        </section>

        <section className="rounded-card bg-white p-5 ring-1 ring-line">
          <h2 className="font-bold">Customers</h2>
          <dl className="mt-3 space-y-2 text-sm">
            <Row label="Online buyers (by phone)" value={customers.onlineBuyers} />
            <Row label="New in the last 30 days" value={customers.newBuyers30} />
            <Row label="Bought more than once" value={customers.repeatBuyers} />
            <Row label="Shops’ credit customers" value={customers.creditCustomers} />
            <Row label="Owed to shops on credit" value={formatKES(customers.owedToShops)} />
          </dl>
        </section>

        <section className="rounded-card bg-white p-5 ring-1 ring-line">
          <h2 className="font-bold">Money</h2>
          <dl className="mt-3 space-y-2 text-sm">
            <Row label="Plan payments received" value={formatKES(money.subscriptionRevenue)} />
            <Row label="Sent to shops" value={formatKES(money.payouts.paid.amount)} />
            <Row label="Payouts waiting" value={money.payouts.pending.count} />
            <Row label="Payouts failed" value={money.payouts.failed.count} tone={money.payouts.failed.count ? 'text-bad' : undefined} />
            <Row label="Counter sales recorded" value={money.counterSales} />
          </dl>
          <Link to="/admin/payments" className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-navy hover:underline">See payments <ArrowUpRight className="size-4" /></Link>
        </section>
      </div>

      <section className={cn('mt-6 flex flex-wrap items-center gap-x-8 gap-y-3 rounded-card p-5 ring-1', health.openIncidents ? 'bg-bad-tint ring-bad/30' : 'bg-white ring-line')}>
        <h2 className="flex items-center gap-2 font-bold">
          {health.openIncidents ? <AlertTriangle className="size-5 text-bad" /> : <CircleCheck className="size-5 text-good" />}
          {health.openIncidents ? `${health.openIncidents} problem${health.openIncidents > 1 ? 's' : ''} need attention` : 'Everything is running'}
        </h2>
        <p className="text-sm text-muted">Last 24 h: {health.requests24.toLocaleString('en-KE')} requests, {errorRate.toFixed(2)}% failed, {health.avgMs} ms average</p>
        <Link to="/admin/health" className="ml-auto inline-flex items-center gap-1 text-sm font-medium text-navy hover:underline">System health <ArrowUpRight className="size-4" /></Link>
      </section>
      <p className="mt-4 text-xs text-muted">Shop links look like {storeHost('shopname')}.</p>
    </>
  )
}

function Kpi({ label, value, note }) {
  return (
    <div className="p-5">
      <p className="text-sm text-muted">{label}</p>
      <p className="mt-1 text-2xl font-bold tabular">{value}</p>
      <p className="mt-1 text-sm text-muted">{note}</p>
    </div>
  )
}

function Row({ label, value, tone }) {
  return (
    <div className="flex justify-between gap-3"><dt className="text-muted">{label}</dt><dd className={cn('font-medium tabular', tone)}>{value}</dd></div>
  )
}

export { Overview as Component }
