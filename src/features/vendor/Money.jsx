import { useLoaderData } from 'react-router'
import { Wallet } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate, formatKES, formatTime } from '@/lib/format'
import { payToLine } from '@/lib/payments'
import { PageHeader } from '@/components/layout/AppShell'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { useVendor } from './VendorLayout'
import { requireStoreId } from './guard'

export async function loader() {
  return { payouts: await api.listPayouts(requireStoreId()) }
}

const STATUS = { paid: ['Sent', 'good'], pending: ['On the way', 'amber'], failed: ['Not sent', 'bad'] }

function Money() {
  const { payouts } = useLoaderData()
  const { store } = useVendor()
  const sent = payouts.filter((p) => p.status === 'paid').reduce((s, p) => s + p.amount, 0)
  const failed = payouts.filter((p) => p.status === 'failed')

  return (
    <>
      <title>Money received — Topline</title>
      <PageHeader title="Money received" actions={<Button to="/dashboard/settings" variant="outline">Change where I get paid</Button>}>
        Online orders paid by M-PESA prompt are sent to {payToLine(store.payments) || 'your M-PESA (not set yet)'}.
      </PageHeader>

      {!store.payments.canReceive && (
        <p className="mb-4 rounded-lg bg-bad-tint px-4 py-3 text-sm text-bad" role="alert">
          You haven’t set where to receive money, so online payments can’t reach you. Add your till, paybill or M-PESA number in Store settings.
        </p>
      )}
      {failed.length > 0 && (
        <p className="mb-4 rounded-lg bg-amber-tint px-4 py-3 text-sm text-[#5c3b00]">
          {failed.length} payment{failed.length > 1 ? 's' : ''} couldn’t be sent. Topline support is notified and will resend once your details are correct.
        </p>
      )}

      <section aria-label="Totals" className="mb-6 grid divide-y divide-line rounded-card bg-white ring-1 ring-line sm:grid-cols-2 sm:divide-x sm:divide-y-0">
        <div className="p-5"><p className="text-sm text-muted">Sent to you (last 200 orders)</p><p className="mt-1 text-2xl font-bold tabular">{formatKES(sent)}</p></div>
        <div className="p-5"><p className="text-sm text-muted">Payments</p><p className="mt-1 text-2xl font-bold tabular">{payouts.length}</p></div>
      </section>

      {payouts.length === 0 ? (
        <EmptyState icon={Wallet} title="No online payments yet">When customers pay with the M-PESA prompt, each payment shows here once it’s sent to you.</EmptyState>
      ) : (
        <div className="overflow-x-auto rounded-card bg-white ring-1 ring-line">
          <table className="w-full min-w-[40rem] text-sm">
            <thead className="text-left text-muted">
              <tr className="border-b border-line">
                <th className="px-4 py-3 font-medium">Date</th>
                <th className="px-3 py-3 font-medium">Order</th>
                <th className="px-3 py-3 font-medium">Sent to</th>
                <th className="px-3 py-3 font-medium">Status</th>
                <th className="px-4 py-3 text-right font-medium">Amount</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {payouts.map((p) => {
                const [label, tone] = STATUS[p.status] ?? [p.status, 'neutral']
                return (
                  <tr key={p.id}>
                    <td className="px-4 py-3 whitespace-nowrap">{formatDate(p.createdAt)} <span className="text-muted">{formatTime(p.createdAt)}</span></td>
                    <td className="px-3 py-3">{p.orderId}</td>
                    <td className="px-3 py-3 text-muted">{p.destination}</td>
                    <td className="px-3 py-3"><Badge tone={tone}>{label}</Badge></td>
                    <td className="px-4 py-3 text-right font-medium tabular">
                      {formatKES(p.amount)}
                      {p.commission > 0 && <span className="block text-xs text-muted">after {formatKES(p.commission)} fee</span>}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}

export { Money as Component }
