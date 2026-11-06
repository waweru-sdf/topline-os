import { useState } from 'react'
import { useFetcher, useLoaderData } from 'react-router'
import { api } from '@/services/api'
import { displayPhone, formatDate, formatKES, formatTime } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { Badge } from '@/components/ui/Badge'
import { requirePlatformAdmin } from '@/features/vendor/guard'

export async function loader() {
  requirePlatformAdmin()
  const [payments, checks, payouts, health, options] = await Promise.all([
    api.listPayments(), api.listPayments({ status: 'checking' }), api.listAllPayouts(), api.health(), api.getBillingOptions(),
  ])
  return { payments, checks, payouts, mode: health.payments, options }
}

export async function action({ request }) {
  const fd = await request.formData()
  try {
    if (fd.get('intent') === 'review') {
      await api.reviewPayment(fd.get('reference'), fd.get('action'), fd.get('note') ?? '')
      return { ok: true }
    }
    await api.retryPayout(fd.get('payoutId'))
    return { ok: true }
  } catch (e) {
    return { error: e.message }
  }
}

const TONE = { paid: 'good', pending: 'amber', checking: 'amber', failed: 'bad' }

const MODE_NOTE = {
  simulation: 'Payments are simulated: no real money moves. Set PAYMENTS_MODE=mpesa in the site’s .env to go live.',
  paystack: 'Live Paystack payments.',
}

function Payments() {
  const { payments, checks, payouts, mode, options } = useLoaderData()
  const [tab, setTab] = useState(checks.length ? 'check' : 'in')
  const tabs = [
    ['check', `Codes to check (${checks.length})`],
    ['in', `Money in (${payments.length})`],
    (mode !== 'mpesa' || payouts.length > 0) && ['out', `Sent to shops (${payouts.length})`],
  ].filter(Boolean)

  return (
    <>
      <title>Payments — Topline admin</title>
      <PageHeader title="Payments">
        {MODE_NOTE[mode] ?? `Plans are paid into Till ${options.till} (${options.tillName}). ${options.prompt ? 'M-PESA prompts are on.' : 'M-PESA prompts are off until the Daraja keys are added, so owners pay from the M-PESA menu and send the code.'}`}
      </PageHeader>

      <div className="mb-4 flex gap-1 border-b border-line">
        {tabs.map(([id, label]) => (
          <button key={id} type="button" aria-pressed={tab === id} onClick={() => setTab(id)}
                  className={cn('-mb-px border-b-2 px-4 py-2.5 text-sm font-medium', tab === id ? 'border-navy text-navy' : 'border-transparent text-muted hover:text-ink')}>
            {label}
          </button>
        ))}
      </div>

      {tab === 'check' ? <CodeChecks checks={checks} till={options.till} /> : (
      <div className="relative overflow-x-auto rounded-card bg-white ring-1 ring-line">
        {tab === 'in' ? (
          <table className="w-full min-w-[52rem] text-sm">
            <thead className="text-left text-muted">
              <tr className="border-b border-line">
                <th className="px-4 py-3 font-medium">When</th><th className="px-3 py-3 font-medium">For</th><th className="px-3 py-3 font-medium">Paid to</th>
                <th className="px-3 py-3 font-medium">Phone</th><th className="px-3 py-3 font-medium">Status</th><th className="px-4 py-3 text-right font-medium">Amount</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {payments.map((p) => (
                <tr key={p.reference}>
                  <td className="px-4 py-3 whitespace-nowrap">{formatDate(p.createdAt)} <span className="text-muted">{formatTime(p.createdAt)}</span></td>
                  <td className="px-3 py-3">{p.kind === 'subscription' ? `Plan: ${p.planId}` : 'Online order'}<span className="block text-xs text-muted">{p.storeName ?? 'New sign-up'}</span></td>
                  <td className="px-3 py-3">{p.payee}</td>
                  <td className="px-3 py-3 text-muted">{displayPhone(p.phone)}</td>
                  <td className="px-3 py-3"><Badge tone={TONE[p.status]}>{p.status}</Badge>{p.failureReason && <span className="mt-1 block max-w-[14rem] text-xs text-bad">{p.failureReason}</span>}</td>
                  <td className="px-4 py-3 text-right font-medium tabular">{formatKES(p.amount)}<span className="block text-xs font-normal text-muted">{p.mpesaRef}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <table className="w-full min-w-[52rem] text-sm">
            <thead className="text-left text-muted">
              <tr className="border-b border-line">
                <th className="px-4 py-3 font-medium">When</th><th className="px-3 py-3 font-medium">Shop</th><th className="px-3 py-3 font-medium">Sent to</th>
                <th className="px-3 py-3 font-medium">Status</th><th className="px-3 py-3 text-right font-medium">Amount</th><th className="px-4 py-3"><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {payouts.map((p) => <PayoutRow key={p.id} payout={p} />)}
            </tbody>
          </table>
        )}
      </div>
      )}
    </>
  )
}

/** M-PESA codes owners sent after paying the Till: find each in the Till's statement, then confirm or turn down. */
function CodeChecks({ checks, till }) {
  if (!checks.length) return <p className="rounded-card bg-white p-6 text-sm text-muted ring-1 ring-line">No codes waiting. When an owner pays Till {till} and sends the code, it shows here.</p>
  return (
    <>
      <p className="mb-3 text-sm text-muted">Open the Till {till} statement (M-PESA app or the M-PESA business portal) and look for each code with the same amount. Confirm only codes you can see there.</p>
      <ul className="space-y-3">{checks.map((p) => <CodeCheck key={p.reference} payment={p} />)}</ul>
    </>
  )
}

function CodeCheck({ payment: p }) {
  const fetcher = useFetcher()
  const [rejecting, setRejecting] = useState(false)
  const busy = fetcher.state !== 'idle'
  return (
    <li className="rounded-card bg-white p-4 ring-1 ring-line">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="font-mono text-lg font-bold tracking-wide">{p.mpesaRef}</p>
          <p className="text-sm text-muted">{p.storeName ?? 'New sign-up'} · {p.planId} plan, {p.period} · from {displayPhone(p.phone)} · {formatDate(p.createdAt)} {formatTime(p.createdAt)}</p>
        </div>
        <p className="text-xl font-bold tabular">{formatKES(p.amount)}</p>
      </div>
      <fetcher.Form method="post" className="mt-3 flex flex-wrap items-end gap-2">
        <input type="hidden" name="intent" value="review" />
        <input type="hidden" name="reference" value={p.reference} />
        {rejecting && (
          <label className="min-w-[14rem] flex-1 text-sm">
            <span className="mb-1 block text-muted">Reason (sent to the owner by SMS)</span>
            <input name="note" required defaultValue="We couldn’t find this code in the till statement." className="h-10 w-full rounded-lg border border-line px-3" />
          </label>
        )}
        {rejecting ? (
          <>
            <button name="action" value="reject" disabled={busy} className="h-10 rounded-lg bg-bad px-4 text-sm font-medium text-white disabled:opacity-60">Turn down</button>
            <button type="button" onClick={() => setRejecting(false)} className="h-10 rounded-lg px-3 text-sm text-muted hover:bg-concrete">Cancel</button>
          </>
        ) : (
          <>
            <button name="action" value="approve" disabled={busy} className="h-10 rounded-lg bg-good px-4 text-sm font-medium text-white disabled:opacity-60">{busy ? 'Saving…' : `Confirm ${formatKES(p.amount)} received`}</button>
            <button type="button" onClick={() => setRejecting(true)} className="h-10 rounded-lg px-3 text-sm font-medium text-bad hover:bg-bad-tint">Not in the till</button>
          </>
        )}
      </fetcher.Form>
      {fetcher.data?.error && <p className="mt-2 text-sm text-bad" role="alert">{fetcher.data.error}</p>}
    </li>
  )
}

function PayoutRow({ payout: p }) {
  const fetcher = useFetcher()
  return (
    <tr>
      <td className="px-4 py-3 whitespace-nowrap">{formatDate(p.createdAt)} <span className="text-muted">{formatTime(p.createdAt)}</span></td>
      <td className="px-3 py-3">{p.storeName}<span className="block text-xs text-muted">Order {p.orderId}</span></td>
      <td className="px-3 py-3 text-muted">{p.destination}</td>
      <td className="px-3 py-3"><Badge tone={TONE[p.status]}>{p.status}</Badge>{p.failureReason && <span className="mt-1 block max-w-[14rem] text-xs text-bad">{p.failureReason}</span>}</td>
      <td className="px-3 py-3 text-right font-medium tabular">{formatKES(p.amount)}{p.commission > 0 && <span className="block text-xs font-normal text-muted">fee {formatKES(p.commission)}</span>}</td>
      <td className="px-4 py-3 text-right">
        {p.status === 'failed' && (
          <fetcher.Form method="post">
            <input type="hidden" name="payoutId" value={p.id} />
            <button className="rounded-lg px-3 py-1.5 font-medium text-navy hover:bg-concrete" disabled={fetcher.state !== 'idle'}>
              {fetcher.state !== 'idle' ? 'Sending…' : 'Send again'}
            </button>
            {fetcher.data?.error && <span className="block text-xs text-bad">{fetcher.data.error}</span>}
          </fetcher.Form>
        )}
      </td>
    </tr>
  )
}

export { Payments as Component }
