import { useEffect, useState } from 'react'
import { useFetcher, useLoaderData, useNavigate, useSearchParams } from 'react-router'
import { Download, Plus, Trash2 } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate, formatKES } from '@/lib/format'
import { EXPENSE_CATEGORY, METHOD_LABEL, monthLabel, recentMonths } from '@/lib/labels'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { Field } from '@/components/ui/Field'
import { BarChart, Legend } from '@/components/BarChart'
import { useVendor } from './VendorLayout'
import { requireStoreId } from './guard'

export async function loader({ request }) {
  const storeId = requireStoreId()
  const month = new URL(request.url).searchParams.get('month') || recentMonths(1)[0].value
  const [summary, expenses] = await Promise.all([api.getAccounting(storeId, month), api.listExpenses(storeId, month)])
  return { summary, expenses }
}

export async function action({ request }) {
  const storeId = requireStoreId()
  const fd = await request.formData()
  try {
    if (fd.get('intent') === 'delete') {
      await api.deleteExpense(storeId, fd.get('id'))
    } else {
      await api.addExpense(storeId, {
        spentOn: fd.get('spentOn'), category: fd.get('category'), amount: Number(fd.get('amount')), method: fd.get('method'), description: fd.get('description'),
      })
    }
    return { ok: true }
  } catch (e) {
    return { error: e.message, field: e.field }
  }
}

const SERIES = [
  { name: 'Cost of goods', color: 'var(--color-steel)' },
  { name: 'Expenses', color: 'var(--color-amber)' },
  { name: 'Profit', color: 'var(--color-navy)' },
]

function Accounting() {
  const { summary: s, expenses } = useLoaderData()
  const { store } = useVendor()
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const [adding, setAdding] = useState(false)
  const [downloadError, setDownloadError] = useState(null)
  const month = params.get('month') || s.month

  async function download(type) {
    setDownloadError(null)
    try {
      await api.downloadAccounting(store.id, type, month)
    } catch (e) {
      setDownloadError(e.message)
    }
  }

  return (
    <>
      <title>Accounting — Topline</title>
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
        Built from your sales, stock costs and the expenses you record. {monthLabel(s.month)}.
      </PageHeader>

      <section aria-label="This month" className="grid gap-px overflow-hidden rounded-card bg-line ring-1 ring-line sm:grid-cols-2 lg:grid-cols-4">
        <Kpi label="Takings" value={s.revenue} note={`${s.salesCount} sales`} />
        <Kpi label="Cost of goods sold" value={s.costOfGoods} note="What the stock you sold cost you" />
        <Kpi label="Expenses" value={s.expenses} note="Rent, wages, transport…" />
        <Kpi label="Net profit" value={s.netProfit} tone={s.netProfit < 0 ? 'text-bad' : 'text-good'} note={s.revenue ? `${Math.round((s.netProfit / s.revenue) * 100)}% of takings` : ' '} />
      </section>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <section className="rounded-card bg-white p-5 ring-1 ring-line" aria-labelledby="pl-h">
          <h2 id="pl-h" className="font-bold">Profit and loss</h2>
          <dl className="mt-3 divide-y divide-line text-sm">
            <Row label="Takings" value={s.revenue} />
            <Row label="Less cost of goods sold" value={-s.costOfGoods} />
            <Row label="Gross profit" value={s.grossProfit} strong />
            {s.expensesByCategory.map((e) => <Row key={e.category} label={`Less ${EXPENSE_CATEGORY[e.category]?.toLowerCase() ?? e.category}`} value={-e.total} />)}
            <Row label="Net profit" value={s.netProfit} strong />
          </dl>
        </section>

        <section className="rounded-card bg-white p-5 ring-1 ring-line" aria-labelledby="money-h">
          <h2 id="money-h" className="font-bold">How you were paid</h2>
          <dl className="mt-3 divide-y divide-line text-sm">
            {Object.entries(s.byMethod).map(([m, v]) => <Row key={m} label={`${METHOD_LABEL[m] ?? m} (${v.count})`} value={v.total} />)}
          </dl>
          <h3 className="mt-5 text-sm font-bold">Also this month</h3>
          <dl className="mt-1 divide-y divide-line text-sm">
            <Row label="Credit customers paid you" value={s.creditCollected} />
            <Row label="Online orders sent to you" value={s.onlineReceived} />
            {s.onlineFees > 0 && <Row label="Topline fees on online orders" value={s.onlineFees} />}
            <Row label="Customers owe you now" value={s.owedToYou} />
            {s.owedToSuppliers > 0 && <Row label="You owe suppliers now" value={s.owedToSuppliers} />}
          </dl>
        </section>
      </div>

      <section className="mt-6 rounded-card bg-white p-5 ring-1 ring-line" aria-labelledby="trend-h">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="trend-h" className="font-bold">Last 6 months</h2>
          <Legend series={SERIES} />
        </div>
        <div className="mt-3">
          <BarChart
            ariaLabel="Takings split into cost of goods, expenses and profit for the last six months"
            series={SERIES}
            rows={s.history.map((h) => ({
              label: monthLabel(h.month, 'short'),
              title: `${monthLabel(h.month)}: takings ${formatKES(h.revenue)}, profit ${formatKES(h.netProfit)}`,
              values: [h.costOfGoods, Math.min(h.expenses, Math.max(0, h.revenue - h.costOfGoods)), Math.max(0, h.netProfit)],
            }))}
          />
        </div>
      </section>

      <section className="mt-6 rounded-card bg-white ring-1 ring-line" aria-labelledby="exp-h">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
          <h2 id="exp-h" className="font-bold">Expenses in {monthLabel(s.month)}</h2>
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" onClick={() => download('sales')}><Download className="size-4" />Sales (CSV)</Button>
            <Button variant="outline" size="sm" onClick={() => download('expenses')}><Download className="size-4" />Expenses (CSV)</Button>
            <Button variant="navy" size="sm" onClick={() => setAdding(true)}><Plus className="size-4" />Record expense</Button>
          </div>
        </div>
        {downloadError && <p className="px-5 pt-3 text-sm text-bad" role="alert">{downloadError}</p>}
        {expenses.length === 0 ? (
          <p className="px-5 py-6 text-muted">No expenses recorded for this month. Record rent, wages and transport to see your real profit.</p>
        ) : (
          <ul className="divide-y divide-line">
            {expenses.map((e) => <ExpenseRow key={e.id} expense={e} />)}
          </ul>
        )}
      </section>

      <AddExpenseDialog open={adding} onClose={() => setAdding(false)} />
    </>
  )
}

function Kpi({ label, value, note, tone }) {
  return (
    <div className="bg-white p-5">
      <p className="text-sm text-muted">{label}</p>
      <p className={cn('mt-1 text-2xl font-bold tabular', tone ?? 'text-ink')}>{formatKES(value)}</p>
      <p className="mt-1 text-xs text-muted">{note}</p>
    </div>
  )
}

function Row({ label, value, strong }) {
  return (
    <div className={cn('flex justify-between gap-4 py-2', strong && 'font-bold')}>
      <dt>{label}</dt>
      <dd className={cn('tabular', value < 0 && 'text-muted')}>{value < 0 ? `(${formatKES(-value)})` : formatKES(value)}</dd>
    </div>
  )
}

function ExpenseRow({ expense: e }) {
  const fetcher = useFetcher()
  if (fetcher.formData?.get('intent') === 'delete') return null
  return (
    <li className="flex items-center gap-4 px-5 py-3 text-sm">
      <span className="w-24 shrink-0 text-muted">{formatDate(e.spentOn)}</span>
      <span className="min-w-0 flex-1">
        <span className="block font-medium">{EXPENSE_CATEGORY[e.category] ?? e.category}</span>
        {e.description && <span className="block truncate text-muted">{e.description}</span>}
      </span>
      <span className="hidden text-muted sm:block">{METHOD_LABEL[e.method] ?? e.method}</span>
      <span className="w-28 text-right font-medium tabular">{formatKES(e.amount)}</span>
      <fetcher.Form method="post" onSubmit={(ev) => { if (!window.confirm('Remove this expense?')) ev.preventDefault() }}>
        <input type="hidden" name="intent" value="delete" />
        <input type="hidden" name="id" value={e.id} />
        <button aria-label={`Remove expense ${EXPENSE_CATEGORY[e.category] ?? e.category} ${formatKES(e.amount)}`} className="rounded-md p-1.5 text-muted hover:bg-bad-tint hover:text-bad">
          <Trash2 className="size-4" />
        </button>
      </fetcher.Form>
    </li>
  )
}

function AddExpenseDialog({ open, onClose }) {
  const fetcher = useFetcher()
  const today = new Date().toLocaleDateString('en-CA') // YYYY-MM-DD in local time
  const err = (f) => (fetcher.data?.field === f ? fetcher.data.error : undefined)
  useEffect(() => {
    if (fetcher.state === 'idle' && fetcher.data?.ok) onClose()
  }, [fetcher.state, fetcher.data, onClose])

  return (
    <Dialog open={open} onClose={onClose} title="Record an expense">
      <fetcher.Form method="post" className="space-y-4" key={open ? 'open' : 'closed'}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field name="spentOn" type="date" label="Date" defaultValue={today} max={today} error={err('spentOn')} required />
          <Field name="amount" label="Amount" prefix="KSh" inputMode="numeric" error={err('amount')} required />
        </div>
        <Field as="select" name="category" label="Category" defaultValue="rent" error={err('category')}>
          {Object.entries(EXPENSE_CATEGORY).map(([id, label]) => <option key={id} value={id}>{label}</option>)}
        </Field>
        <Field as="select" name="method" label="Paid by" defaultValue="cash" error={err('method')}>
          <option value="cash">Cash</option>
          <option value="mpesa">M-PESA</option>
          <option value="bank">Bank</option>
        </Field>
        <Field name="description" label="Description (optional)" placeholder="e.g. November rent" maxLength={200} error={err('description')} />
        {fetcher.data?.error && !fetcher.data.field && <p className="text-sm text-bad" role="alert">{fetcher.data.error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="navy" pending={fetcher.state !== 'idle'}>Save expense</Button>
        </div>
      </fetcher.Form>
    </Dialog>
  )
}

export { Accounting as Component }
