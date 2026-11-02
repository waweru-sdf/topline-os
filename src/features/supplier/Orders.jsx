import { Navigate, useFetcher, useLoaderData } from 'react-router'
import { ClipboardList, MapPin, MessageCircle, Phone } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate, formatKES, formatTime, normalizePhone } from '@/lib/format'
import { SUPPLIER_ORDER_STATUS } from '@/lib/labels'
import { PageHeader } from '@/components/layout/AppShell'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { requireSupplier } from '@/features/vendor/guard'
import { useSupplier } from './SupplierLayout'

export async function loader() {
  requireSupplier()
  return { orders: await api.listIncomingOrders() }
}

export async function action({ request }) {
  const fd = await request.formData()
  try {
    await api.updateIncomingOrder(fd.get('orderId'), fd.get('status'))
    return { ok: true }
  } catch (e) {
    return { error: e.message }
  }
}

const NEXT = {
  new: [['contacted', 'I’ve contacted them'], ['completed', 'Delivered'], ['declined', 'Decline']],
  contacted: [['completed', 'Delivered'], ['declined', 'Decline']],
}

function Orders() {
  const { orders } = useLoaderData()
  const { me } = useSupplier()
  // Just approved, nothing listed yet: start on the profile.
  if (me.productCount === 0 && me.totalOrders === 0) return <Navigate to="/supplier/profile" replace />
  return (
    <>
      <title>Orders — Topline supplier</title>
      <PageHeader title="Orders from hardware shops">
        Call or WhatsApp each shop to agree prices, delivery and payment. Money is paid to you directly, outside Topline.
      </PageHeader>
      {me.status === 'hidden' && (
        <p className="mb-4 rounded-lg bg-bad-tint px-4 py-3 text-sm text-bad">Topline has hidden your profile, so shops can’t send new orders. Contact Topline to find out why.</p>
      )}
      {orders.length === 0 ? (
        <EmptyState icon={ClipboardList} title="No orders yet">When a hardware shop orders from you, it shows up here and you get an SMS.</EmptyState>
      ) : (
        <ul className="space-y-4">
          {orders.map((o) => <OrderCard key={o.id} order={o} />)}
        </ul>
      )}
    </>
  )
}

function OrderCard({ order: o }) {
  const fetcher = useFetcher()
  const status = fetcher.formData?.get('status') ?? o.status
  const [label, tone] = SUPPLIER_ORDER_STATUS[status] ?? [status, 'neutral']
  const phone = normalizePhone(o.contactPhone)
  const greeting = `Habari ${o.contactName}, this is about your Topline order ${o.reference}.`
  return (
    <li className="rounded-card bg-white p-5 ring-1 ring-line">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="font-bold">{o.store?.name} <span className="font-normal text-muted">· {o.reference}</span></p>
          <p className="text-sm text-muted">{[o.store?.town, o.store?.county].filter(Boolean).join(', ')} · {formatDate(o.createdAt)}, {formatTime(o.createdAt)}</p>
        </div>
        <Badge tone={tone}>{label}</Badge>
      </div>
      <ul className="mt-4 divide-y divide-line rounded-lg bg-concrete px-4 text-sm">
        {o.items.map((i, n) => (
          <li key={n} className="flex justify-between gap-3 py-2">
            <span>{i.qty} {i.unit} × {i.name}</span>
            <span className="tabular text-muted">{i.price ? formatKES(i.price * i.qty) : 'To quote'}</span>
          </li>
        ))}
      </ul>
      <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-3">
        <div><dt className="text-muted">Estimated total</dt><dd className="font-bold tabular">{o.estimatedTotal ? formatKES(o.estimatedTotal) : 'To quote'}</dd></div>
        <div><dt className="text-muted">Will pay by</dt><dd>{o.paymentOption.label}</dd></div>
        <div><dt className="text-muted">Deliver to</dt><dd className="flex items-start gap-1"><MapPin className="mt-0.5 size-3.5 shrink-0 text-steel" />{o.deliveryLocation}</dd></div>
      </dl>
      {o.notes && <p className="mt-3 rounded-lg bg-amber-tint/60 px-3 py-2 text-sm">“{o.notes}”</p>}
      <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
        <span className="mr-auto text-sm"><span className="text-muted">Contact:</span> <span className="font-medium">{o.contactName}</span>, {o.contactPhone}</span>
        <a href={`tel:${o.contactPhone.replace(/\s/g, '')}`} className="flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-navy ring-1 ring-line hover:bg-concrete"><Phone className="size-4" />Call</a>
        {phone && (
          <a href={`https://wa.me/${phone}?text=${encodeURIComponent(greeting)}`} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 rounded-lg bg-good px-3 py-2 text-sm font-medium text-white hover:opacity-90">
            <MessageCircle className="size-4" />WhatsApp
          </a>
        )}
        {(NEXT[status] ?? []).map(([to, text]) => (
          <fetcher.Form key={to} method="post">
            <input type="hidden" name="orderId" value={o.id} />
            <button name="status" value={to} className={to === 'declined' ? 'rounded-lg px-3 py-2 text-sm font-medium text-bad hover:bg-bad-tint' : 'rounded-lg bg-navy px-3 py-2 text-sm font-medium text-white hover:bg-navy-deep'}
              onClick={(e) => { if (to === 'declined' && !window.confirm(`Decline order ${o.reference}?`)) e.preventDefault() }}>
              {text}
            </button>
          </fetcher.Form>
        ))}
      </div>
      {fetcher.data?.error && <p className="mt-2 text-sm text-bad" role="alert">{fetcher.data.error}</p>}
    </li>
  )
}

export { Orders as Component }
