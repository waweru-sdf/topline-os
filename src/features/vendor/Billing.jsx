import { useState } from 'react'
import { useLoaderData, useRevalidator } from 'react-router'
import { CalendarClock, Check, CircleCheck } from 'lucide-react'
import { api } from '@/services/api'
import { displayPhone, formatDate, formatKES } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { PaymentPrompt } from '@/components/PaymentPrompt'
import { TillCodeStatus, TillPay } from '@/components/TillPay'
import { Button } from '@/components/ui/Button'
import { StoreStatus } from '@/components/ui/Badge'
import { useVendor } from './VendorLayout'
import { requireStoreId } from './guard'

export async function loader() {
  requireStoreId()
  const [plans, options] = await Promise.all([api.getPlans(), api.getBillingOptions()])
  return { plans, options }
}

function Billing() {
  const { plans, options } = useLoaderData()
  const { store, user } = useVendor()
  const revalidator = useRevalidator()
  const plan = plans.find((p) => p.id === store.planId)
  const period = store.billing?.period ?? 'monthly'
  const priceOf = (p) => (period === 'yearly' ? p.priceYearly : p.priceMonthly)
  const [phone, setPhone] = useState(displayPhone(user.phone))
  const [how, setHow] = useState(options.prompt ? 'prompt' : 'till')
  const [code, setCode] = useState('')
  const [planId, setPlanId] = useState(store.planId) // plan being paid for
  const [payment, setPayment] = useState(null) // the prompt being waited on
  const [paid, setPaid] = useState(null)
  const [error, setError] = useState(null)
  const [sending, setSending] = useState(false)
  const tillCode = store.billing?.tillCode
  const checking = tillCode?.status === 'checking'
  const chosen = plans.find((p) => p.id === planId) ?? plan

  async function pay(e) {
    e.preventDefault()
    setError(null)
    setPaid(null)
    setSending(true)
    try {
      if (how === 'till') {
        await api.submitTillCode({ phone, planId, period, mpesaCode: code })
        setCode('')
        revalidator.revalidate()
      } else {
        setPayment(await api.paySubscription({ phone, planId, period }))
      }
    } catch (err) {
      setError(err)
    } finally {
      setSending(false)
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
          {store.status === 'draft' && !tillCode && <p className="text-sm text-muted">Your store opens to customers once the first payment is confirmed.</p>}
          <TillCodeStatus tillCode={tillCode} className="mb-3" />
          {payment ? (
            <PaymentPrompt payment={payment} poll={() => api.getSubscriptionPayment(payment.reference)} onPaid={onPaid} onRetry={() => setPayment(null)} />
          ) : paid ? (
            <p className="flex items-start gap-2 text-good"><CircleCheck className="mt-0.5 size-5 shrink-0" />Paid. M-PESA ref {paid.mpesaRef}. Next payment due {formatDate(paid.nextBillingAt)}.</p>
          ) : !checking && (
            <form onSubmit={pay} className="space-y-3">
              {planId !== plan.id && (
                <p className="text-sm">Paying for <span className="font-bold">{chosen.name}</span> · <button type="button" className="text-navy hover:underline" onClick={() => setPlanId(plan.id)}>keep {plan.name}</button></p>
              )}
              <TillPay options={options} amount={priceOf(chosen)} how={how} setHow={setHow} phone={phone} setPhone={setPhone} code={code} setCode={setCode} error={error} />
              <Button type="submit" className="w-full" pending={sending}>
                {how === 'till' ? 'Send M-PESA code' : `Pay ${formatKES(priceOf(chosen))} now`}
              </Button>
              <p className="text-xs text-muted">Paying early adds to your remaining time.</p>
            </form>
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
                <Button variant="outline" className="mt-4 w-full" disabled={!!payment || checking || planId === p.id}
                  onClick={() => { setPlanId(p.id); setError(null); window.scrollTo({ top: 0, behavior: 'smooth' }) }}>
                  {planId === p.id ? `Paying for ${p.name} above` : `Switch to ${p.name} (${formatKES(priceOf(p))})`}
                </Button>
              )}
            </article>
          )
        })}
      </div>
      <p className="mt-4 text-sm text-muted">To switch, pick the plan and pay its price above. The new plan starts as soon as the payment is confirmed. All plans pay into Topline’s Till {options.till}.</p>
    </div>
  )
}

export { Billing as Component }
