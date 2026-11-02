import { useState } from 'react'
import { Link, useLoaderData, useNavigate, useSearchParams } from 'react-router'
import { Download } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate, formatKES } from '@/lib/format'
import { monthLabel, recentMonths } from '@/lib/labels'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/Button'
import { BarChart, Legend } from '@/components/BarChart'
import { requirePlatformAdmin } from '@/features/vendor/guard'

export async function loader({ request }) {
  requirePlatformAdmin()
  const month = new URL(request.url).searchParams.get('month') || recentMonths(1)[0].value
  return { s: await api.adminAccounting(month) }
}

const SERIES = [
  { name: 'Plan payments', color: 'var(--color-navy)' },
  { name: 'Commission', color: 'var(--color-amber)' },
]

function Accounting() {
  const { s } = useLoaderData()
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const [error, setError] = useState(null)
  const month = params.get('month') || s.month
  const download = (type) => api.downloadPlatformAccounting(type, month).catch((e) => setError(e.message))

  return (
    <>
      <title>Accounting — Topline admin</title>
      <PageHeader
        title="Accounting"
        actions={
          <label className="flex items-center gap-2 text-sm">
            <span className="text-muted">Month</span>
            <select value={month} onChange={(e) => navigate(`?month=${e.target.value}`)} className="h-10 rounded-lg border border-line bg-white px-3">
              {recentMonths(12).map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </select>
          </label>
        }
      >
        Topline’s income and money moved for shops in {monthLabel(s.month)}.
      </PageHeader>

      <section aria-label="This month" className="grid gap-px overflow-hidden rounded-card bg-line ring-1 ring-line sm:grid-cols-2 lg:grid-cols-4">
        <Kpi label="Income" value={formatKES(s.income)} note="Plan payments + commission" strong />
        <Kpi label="Plan payments" value={formatKES(s.planIncome)} note={`${s.planPayments} payments`} />
        <Kpi label="Commission on online orders" value={formatKES(s.commissionIncome)} note={`${s.onlineOrders} orders, ${formatKES(s.onlineOrderVolume)}`} />
        <Kpi label="Monthly recurring revenue" value={formatKES(s.mrr)} note={`${s.activeStores} active shops, ${s.newStores} new this month`} />
      </section>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <section className="rounded-card bg-white p-5 ring-1 ring-line" aria-labelledby="payouts-h">
          <h2 id="payouts-h" className="font-bold">Money sent to shops</h2>
          <dl className="mt-3 divide-y divide-line text-sm">
            <Row label={`Sent (${s.payoutsSent.count})`} value={s.payoutsSent.total} />
            <Row label={`Waiting (${s.payoutsPending.count})`} value={s.payoutsPending.total} />
            <Row label={`Failed (${s.payoutsFailed.count})`} value={s.payoutsFailed.total} bad={s.payoutsFailed.count > 0} />
          </dl>
          {s.payoutsFailed.count > 0 && <Link to="/admin/payments" className="mt-3 inline-block text-sm font-medium text-navy hover:underline">Retry failed payouts</Link>}
        </section>
        <section className="rounded-card bg-white p-5 ring-1 ring-line" aria-labelledby="due-h">
          <h2 id="due-h" className="font-bold">Plans past due</h2>
          {s.pastDue.length === 0 ? <p className="mt-3 text-sm text-muted">Every shop is paid up.</p> : (
            <ul className="mt-3 divide-y divide-line text-sm">
              {s.pastDue.map((p) => (
                <li key={p.id} className="flex justify-between gap-3 py-2"><span>{p.name} <span className="text-muted">({p.planId})</span></span><span className="text-muted">due {p.nextBillingAt ? formatDate(p.nextBillingAt) : '—'}</span></li>
              ))}
            </ul>
          )}
        </section>
      </div>

      <section className="mt-6 rounded-card bg-white p-5 ring-1 ring-line" aria-labelledby="trend-h">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="trend-h" className="font-bold">Income, last 6 months</h2>
          <Legend series={SERIES} />
        </div>
        <div className="mt-3">
          <BarChart ariaLabel="Topline income per month: plan payments and commission" series={SERIES}
            rows={s.history.map((h) => ({ label: monthLabel(h.month, 'short'), title: `${monthLabel(h.month)}: ${formatKES(h.income)}`, values: [h.planIncome, h.commissionIncome] }))} />
        </div>
      </section>

      <section className="mt-6 flex flex-wrap items-center gap-3 rounded-card bg-white p-5 ring-1 ring-line">
        <p className="mr-auto text-sm text-muted">Download {monthLabel(s.month)} for your accountant:</p>
        <Button variant="outline" size="sm" onClick={() => download('payments')}><Download className="size-4" />Payments (CSV)</Button>
        <Button variant="outline" size="sm" onClick={() => download('payouts')}><Download className="size-4" />Payouts (CSV)</Button>
        {error && <p className="w-full text-sm text-bad" role="alert">{error}</p>}
      </section>
    </>
  )
}

function Kpi({ label, value, note, strong }) {
  return (
    <div className="bg-white p-5">
      <p className="text-sm text-muted">{label}</p>
      <p className={cn('mt-1 text-2xl font-bold tabular', strong ? 'text-navy' : 'text-ink')}>{value}</p>
      <p className="mt-1 text-xs text-muted">{note}</p>
    </div>
  )
}

function Row({ label, value, bad }) {
  return (
    <div className="flex justify-between gap-4 py-2">
      <dt>{label}</dt>
      <dd className={cn('tabular', bad && 'text-bad')}>{formatKES(value)}</dd>
    </div>
  )
}

export { Accounting as Component }
