import { Link, useFetcher, useLoaderData } from 'react-router'
import { Factory, MapPin, Package, Store, Truck } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate, formatKES, pluralize } from '@/lib/format'
import { SUPPLIER_ORDER_STATUS } from '@/lib/labels'
import { PageHeader } from '@/components/layout/AppShell'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { SupplierLogo } from '@/components/SupplierLogo'
import { requireStoreId } from './guard'

export async function loader() {
  const storeId = requireStoreId()
  const [suppliers, orders] = await Promise.all([api.listSuppliers(storeId), api.listSupplierOrders(storeId)])
  return { suppliers, orders }
}

export async function action({ request }) {
  const fd = await request.formData()
  try {
    await api.cancelSupplierOrder(requireStoreId(), fd.get('orderId'))
    return { ok: true }
  } catch (e) {
    return { error: e.message }
  }
}

function Suppliers() {
  const { suppliers, orders } = useLoaderData()
  return (
    <>
      <title>Suppliers — Topline</title>
      <PageHeader title="Suppliers">Order stock straight from manufacturers and distributors. They call you to confirm, then you pay them directly.</PageHeader>

      {suppliers.length === 0 ? (
        <EmptyState icon={Factory} title="No suppliers yet">Topline is signing up manufacturers and distributors. They’ll appear here as soon as they’re approved.</EmptyState>
      ) : (
        <ul className="grid gap-4 md:grid-cols-2">
          {suppliers.map((s) => (
            <li key={s.id}>
              <Link to={`/dashboard/suppliers/${s.slug}`} className="flex h-full gap-4 rounded-card bg-white p-5 ring-1 ring-line transition hover:ring-navy/40">
                <SupplierLogo supplier={s} />
                <span className="min-w-0 flex-1">
                  <span className="block font-bold text-ink">{s.name}</span>
                  {s.location && <span className="mt-0.5 flex items-center gap-1 text-sm text-muted"><MapPin className="size-3.5" />{s.location}</span>}
                  <span className="mt-2 line-clamp-2 block text-sm text-muted">{s.description}</span>
                  <span className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                    <span className="flex items-center gap-1"><Package className="size-3.5" />{pluralize(s.productCount, 'product')}</span>
                    <span className="flex items-center gap-1"><Store className="size-3.5" />Works with {pluralize(s.connectedShops, 'shop')}</span>
                    {s.minOrder && <span className="flex items-center gap-1"><Truck className="size-3.5" />Min. {s.minOrder}</span>}
                  </span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}

      <section className="mt-10" aria-labelledby="my-orders">
        <h2 id="my-orders" className="text-lg font-bold">Your orders to suppliers</h2>
        {orders.length === 0 ? (
          <p className="mt-2 text-muted">You haven’t ordered from a supplier yet.</p>
        ) : (
          <div className="relative mt-3 overflow-x-auto rounded-card bg-white ring-1 ring-line">
            <table className="w-full min-w-[46rem] text-sm">
              <thead className="text-left text-muted">
                <tr className="border-b border-line">
                  <th className="px-4 py-3 font-medium">Order</th>
                  <th className="px-3 py-3 font-medium">Supplier</th>
                  <th className="px-3 py-3 font-medium">Items</th>
                  <th className="px-3 py-3 text-right font-medium">Estimate</th>
                  <th className="px-3 py-3 font-medium">Status</th>
                  <th className="px-4 py-3"><span className="sr-only">Actions</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {orders.map((o) => <OrderRow key={o.id} order={o} />)}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  )
}

function OrderRow({ order }) {
  const fetcher = useFetcher()
  const [label, tone] = SUPPLIER_ORDER_STATUS[order.status] ?? [order.status, 'neutral']
  return (
    <tr>
      <td className="px-4 py-3">
        <span className="block font-medium">{order.reference}</span>
        <span className="block text-xs text-muted">{formatDate(order.createdAt)}</span>
      </td>
      <td className="px-3 py-3">
        <span className="block">{order.supplier?.name}</span>
        {order.supplier?.phone && <a href={`tel:${order.supplier.phone.replace(/\s/g, '')}`} className="text-xs text-navy hover:underline">{order.supplier.phone}</a>}
      </td>
      <td className="max-w-[18rem] truncate px-3 py-3">{order.items.map((i) => `${i.qty} ${i.unit || ''} ${i.name}`.replace(/\s+/g, ' ')).join(', ')}</td>
      <td className="px-3 py-3 text-right tabular">{order.estimatedTotal ? formatKES(order.estimatedTotal) : '—'}</td>
      <td className="px-3 py-3"><Badge tone={tone}>{label}</Badge></td>
      <td className="px-4 py-3 text-right">
        {order.status === 'new' && (
          <fetcher.Form method="post" onSubmit={(e) => { if (!window.confirm(`Cancel order ${order.reference}?`)) e.preventDefault() }}>
            <input type="hidden" name="orderId" value={order.id} />
            <button className="rounded-lg px-2.5 py-1.5 font-medium text-bad hover:bg-bad-tint" disabled={fetcher.state !== 'idle'}>Cancel</button>
          </fetcher.Form>
        )}
        {fetcher.data?.error && <p className="mt-1 text-xs text-bad" role="alert">{fetcher.data.error}</p>}
      </td>
    </tr>
  )
}

export { Suppliers as Component }
