import { useState } from 'react'
import { useLoaderData, useRevalidator } from 'react-router'
import { CalendarClock, Check, CircleCheck } from 'lucide-react'
import { api } from '@/services/api'
import { displayPhone, formatDate, formatKES } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { PaymentPrompt } from '@/components/PaymentPrompt'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { StoreStatus } from '@/components/ui/Badge'
import { useVendor } from './VendorLayout'
import { requireStoreId } from './guard'

export async function loader() {
  requireStoreId()
  return { plans: await api.getPlans() }
}

function Billing() {
  const { plans } = useLoaderData()
  const { store, user } = useVendor()
  const revalidator = useRevalidator()
  const plan = plans.find((p) => p.id === store.planId)
  const period = store.billing?.period ?? 'monthly'
  const priceOf = (p) => (period === 'yearly' ? p.priceYearly : p.priceMonthly)
  const [phone, setPhone] = useState(displayPhone(user.phone))
  const [payment, setPayment] = useState(null) // the prompt being waited on
  const [paid, setPaid] = useState(null)
  const [error, setError] = useState(null)
  const [sending, setSending] = useState(null) // plan id being paid for

  async function pay(planId) {
    setError(null)
    setPaid(null)
    setSending(planId)
    try {
      setPayment(await api.paySubscription({ phone, planId, period }))
    } catch (err) {
      setError(err.message)
    } finally {
      setSending(null)
    }
  }

  function onPaid(result) {
    setPayment(null)
    setPaid(result)
    revalidator.revalidate()
  }

  return (
    <div className="max-w-4xl">
      <title>Plan & billing — Topline</title>
      <PageHeader title="Plan & billing" />

      <section className="grid gap-6 rounded-card bg-white p-6 ring-1 ring-line md:grid-cols-2 md:items-start">
        <div>
          <p className="flex items-center gap-2 text-sm text-muted">Current plan <StoreStatus status={store.status} /></p>
          <p className="mt-1 text-2xl font-bold text-navy">{plan.name}</p>
          <p className="mt-1 text-muted">{formatKES(priceOf(plan))} per {period === 'yearly' ? 'year' : 'month'}</p>
          {store.nextBillingAt && (
            <p className="mt-3 flex items-center gap-2 text-sm">
              <CalendarClock className="size-4 text-steel" /> Next payment due {formatDate(store.nextBillingAt)}
            </p>
          )}
        </div>
        <div>
          {payment ? (
            <PaymentPrompt payment={payment} poll={() => api.getSubscriptionPayment(payment.reference)} onPaid={onPaid} onRetry={() => setPayment(null)} />
          ) : paid ? (
            <p className="flex items-start gap-2 text-good"><CircleCheck className="mt-0.5 size-5 shrink-0" />Paid. M-PESA ref {paid.mpesaRef}. Next payment due {formatDate(paid.nextBillingAt)}.</p>
          ) : (
            <div className="space-y-3">
              <Field label="M-PESA number to pay from" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} error={error} />
              <Button className="w-full" pending={sending === plan.id} onClick={() => pay(plan.id)}>
                Pay {formatKES(priceOf(plan))} now
              </Button>
              <p className="text-xs text-muted">You’ll get a prompt for exactly {formatKES(priceOf(plan))} to TOPLINE. Paying early adds to your remaining time.</p>
            </div>
          )}
        </div>
      </section>

      <h2 className="mt-10 text-lg font-bold">Change plan</h2>
      <div className="mt-4 grid gap-4 md:grid-cols-3">
        {plans.map((p) => {
          const current = p.id === plan.id
          return (
            <article key={p.id} className={cn('flex flex-col rounded-card bg-white p-5 ring-1', current ? 'ring-2 ring-navy' : 'ring-line')}>
              <h3 className="font-bold">{p.name}</h3>
              <p className="mt-1 text-xl font-bold tabular">{formatKES(priceOf(p))}<span className="text-sm font-normal text-muted"> / {period === 'yearly' ? 'year' : 'month'}</span></p>
              <ul className="mt-3 flex-1 space-y-1.5 text-sm text-muted">
                {p.features.slice(0, 4).map((f) => <li key={f} className="flex gap-2"><Check className="mt-0.5 size-4 shrink-0 text-good" />{f}</li>)}
              </ul>
              {current ? (
                <p className="mt-4 text-sm font-medium text-navy">Your current plan</p>
              ) : (
                <Button variant="outline" className="mt-4 w-full" pending={sending === p.id} disabled={!!payment} onClick={() => pay(p.id)}>
                  Switch to {p.name} ({formatKES(priceOf(p))})
                </Button>
              )}
            </article>
          )
        })}
      </div>
      <p className="mt-4 text-sm text-muted">Switching sends an M-PESA prompt for the new plan’s price. Card payments and automatic renewal come with Paystack.</p>
    </div>
  )
}

export { Billing as Component }
