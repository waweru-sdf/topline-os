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
  const [payments, payouts, health] = await Promise.all([api.listPayments(), api.listAllPayouts(), api.health()])
  return { payments, payouts, mode: health.payments }
}

export async function action({ request }) {
  const fd = await request.formData()
  try {
    await api.retryPayout(fd.get('payoutId'))
    return { ok: true }
  } catch (e) {
    return { error: e.message }
  }
}

const TONE = { paid: 'good', pending: 'amber', failed: 'bad' }

function Payments() {
  const { payments, payouts, mode } = useLoaderData()
  const [tab, setTab] = useState('in')

  return (
    <>
      <title>Payments — Topline admin</title>
      <PageHeader title="Payments">
        {mode === 'simulation'
          ? 'Payments are simulated: no real money moves. Add Paystack keys in backend/.env to go live.'
          : 'Live Paystack payments.'}
      </PageHeader>

      <div className="mb-4 flex gap-1 border-b border-line">
        {[['in', `Money in (${payments.length})`], ['out', `Sent to shops (${payouts.length})`]].map(([id, label]) => (
          <button key={id} type="button" aria-pressed={tab === id} onClick={() => setTab(id)}
                  className={cn('-mb-px border-b-2 px-4 py-2.5 text-sm font-medium', tab === id ? 'border-navy text-navy' : 'border-transparent text-muted hover:text-ink')}>
            {label}
          </button>
        ))}
      </div>

      <div className="overflow-x-auto rounded-card bg-white ring-1 ring-line">
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
    </>
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
