import { Calculator, Check } from 'lucide-react'
import { PageHeader } from '@/components/layout/AppShell'

/** A feature that's on the way: what it will do, no controls yet. */
export function ComingSoonPanel({ icon: Icon = Calculator, title, body, points = [] }) {
  return (
    <section className="rounded-card bg-white p-6 ring-1 ring-line sm:p-8">
      <div className="flex items-start gap-4">
        <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-concrete">
          <Icon aria-hidden className="size-6 text-navy" />
        </span>
        <div>
          <p className="text-xs font-semibold tracking-wide text-amber-deep uppercase">Coming soon</p>
          <h2 className="mt-1 text-xl font-bold text-ink">{title}</h2>
          <p className="mt-2 max-w-2xl leading-relaxed text-muted">{body}</p>
        </div>
      </div>
      {points.length > 0 && (
        <ul className="mt-6 grid gap-2.5 sm:grid-cols-2">
          {points.map((pt) => (
            <li key={pt} className="flex gap-3 text-ink/80">
              <Check aria-hidden className="mt-0.5 size-5 shrink-0 text-steel" />
              <span>{pt}</span>
            </li>
          ))}
        </ul>
      )}
      <button type="button" disabled aria-disabled="true" className="mt-6 cursor-not-allowed rounded-lg bg-concrete px-4 py-2 text-sm font-medium text-muted">
        Coming soon
      </button>
    </section>
  )
}

const SHOP_ACCOUNTING = {
  title: 'Accounting for your shop',
  body: 'Your books, kept for you from the sales, stock and payments you already record in Topline.',
  points: ['Profit and loss, month by month', 'Expenses and supplier bills', 'Cash, M-PESA and bank balances', 'Reports ready for your accountant and KRA'],
}

const PLATFORM_ACCOUNTING = {
  title: 'Accounting across all shops',
  body: 'Topline’s own books in one place: plan payments, commissions on online orders and payouts to shops.',
  points: ['Subscription and commission income', 'Payouts and Paystack fees', 'Monthly statements', 'Export for your accountant'],
}

export function AccountingPage({ variant }) {
  const content = variant === 'platform' ? PLATFORM_ACCOUNTING : SHOP_ACCOUNTING
  return (
    <>
      <title>Accounting — Topline</title>
      <PageHeader title="Accounting">We’re building this now.</PageHeader>
      <ComingSoonPanel {...content} />
    </>
  )
}
