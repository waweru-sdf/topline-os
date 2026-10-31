import { useState } from 'react'
import { useFetcher, useLoaderData } from 'react-router'
import { MapPin, Phone, ShoppingBag, Smartphone, Store } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate, formatKES, formatTime } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { OrderStatus } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { requireStoreId } from './guard'

export async function loader() {
  return { orders: await api.listOrders(requireStoreId(), { channel: 'online' }) }
}

export async function action({ request }) {
  const fd = await request.formData()
  return api.updateOrderStatus(requireStoreId(), fd.get('orderId'), fd.get('status'))
}

const TABS = [
  ['open', 'To do'],
  ['completed', 'Completed'],
  ['all', 'All'],
]
// What the shop does next with an order in each state.
const NEXT = {
  awaiting_confirmation: [['new', 'Payment received'], ['cancelled', 'Not received, cancel']],
  new: [['confirmed', 'Confirm order'], ['cancelled', 'Cancel']],
  confirmed: [['dispatched', 'Mark as on the way'], ['completed', 'Mark as picked up']],
  dispatched: [['completed', 'Mark as delivered']],
}

function Orders() {
  const { orders } = useLoaderData()
  const [tab, setTab] = useState('open')
  const rows = orders.filter((o) => (tab === 'all' ? true : tab === 'completed' ? ['completed', 'cancelled'].includes(o.status) : !['completed', 'cancelled'].includes(o.status)))
  const openCount = orders.filter((o) => !['completed', 'cancelled'].includes(o.status)).length

  return (
    <>
      <title>Online orders — Topline</title>
      <PageHeader title="Online orders">Orders customers placed and paid for on your store.</PageHeader>
      <div className="mb-4 flex gap-1 border-b border-line">
        {TABS.map(([id, label]) => (
          <button
            key={id}
            type="button"
            aria-pressed={tab === id}
            onClick={() => setTab(id)}
            className={cn('-mb-px border-b-2 px-4 py-2.5 text-sm font-medium', tab === id ? 'border-navy text-navy' : 'border-transparent text-muted hover:text-ink')}
          >
            {label}{id === 'open' && openCount ? ` (${openCount})` : ''}
          </button>
        ))}
      </div>

      {rows.length === 0 ? (
        <EmptyState icon={ShoppingBag} title={tab === 'open' ? 'No orders waiting' : 'No orders here yet'}>
          Share your store link on WhatsApp to get orders coming in.
        </EmptyState>
      ) : (
        <ul className="space-y-3">
          {rows.map((o) => <OrderRow key={o.id} order={o} />)}
        </ul>
      )}
    </>
  )
}

function OrderRow({ order }) {
  const fetcher = useFetcher()
  const status = fetcher.formData?.get('status') ?? order.status // optimistic
  const actions = NEXT[status] ?? []

  return (
    <li className="rounded-card bg-white p-5 ring-1 ring-line">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="flex items-center gap-2 font-bold">{order.id} <OrderStatus status={status} /></p>
          <p className="mt-0.5 text-sm text-muted">{order.customerName}, {formatDate(order.createdAt)} at {formatTime(order.createdAt)}</p>
        </div>
        <p className="text-xl font-bold tabular">{formatKES(order.total)}</p>
      </div>
      {status === 'awaiting_confirmation' && (
        <p className="mt-3 rounded-lg bg-amber-tint px-3 py-2 text-sm text-[#5c3b00]">
          The customer says they paid {formatKES(order.total)} to your number with M-PESA code <span className="font-bold">{order.mpesaRef}</span>.
          Check your M-PESA messages before confirming.
        </p>
      )}
      <ul className="mt-3 text-sm">
        {order.items.map((i) => <li key={i.productId}>{i.qty} × {i.name} <span className="text-muted">({i.unit})</span></li>)}
        {order.deliveryFee > 0 && <li className="text-muted">Delivery: {formatKES(order.deliveryFee)}</li>}
      </ul>
      <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-sm text-muted">
        {order.delivery?.type === 'delivery' ? (
          <span className="flex items-center gap-1.5"><MapPin className="size-4" />Deliver to {order.delivery.address}{order.delivery.zone ? ` (${order.delivery.zone})` : ''}</span>
        ) : (
          <span className="flex items-center gap-1.5"><Store className="size-4" />Pickup at the shop</span>
        )}
        <span className="flex items-center gap-1.5"><Smartphone className="size-4" />{order.paymentMode === 'direct' ? 'Paid to your number' : `M-PESA ${order.mpesaRef ?? ''}`}</span>
        {order.customerPhone && <a href={`tel:+${order.customerPhone}`} className="flex items-center gap-1.5 hover:text-ink"><Phone className="size-4" />Call customer</a>}
      </div>
      {actions.length > 0 && (
        <fetcher.Form method="post" className="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">
          <input type="hidden" name="orderId" value={order.id} />
          {actions.map(([value, label], i) => (
            <button
              key={value}
              name="status"
              value={value}
              className={cn('h-9 rounded-lg px-4 text-sm font-medium', i === 0 ? 'bg-navy text-white hover:bg-navy-soft' : 'text-muted ring-1 ring-line hover:text-ink')}
            >
              {label}
            </button>
          ))}
        </fetcher.Form>
      )}
    </li>
  )
}

export { Orders as Component }
