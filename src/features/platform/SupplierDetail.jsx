import { useState } from 'react'
import { Link, useLoaderData, useRevalidator } from 'react-router'
import { ArrowLeft, Check, KeyRound, X } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate, formatKES } from '@/lib/format'
import { SUPPLIER_ORDER_STATUS, SUPPLIER_STATUS } from '@/lib/labels'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { Img } from '@/components/ui/Img'
import { SupplierLogo } from '@/components/SupplierLogo'
import { TempPasswordCard } from '@/components/TempPasswordCard'
import { requirePlatformAdmin } from '@/features/vendor/guard'
import { reviewSignUp } from './Suppliers'

export async function loader({ params }) {
  requirePlatformAdmin()
  return { supplier: await api.adminSupplier(params.supplierId) }
}

const PROFILE_ROWS = [
  ['name', 'Name'], ['description', 'Description'], ['categories', 'Categories'], ['location', 'Location'], ['phone', 'Phone'],
  ['email', 'Email'], ['website', 'Website'], ['minOrder', 'Minimum order'], ['deliveryInfo', 'Delivery'],
  ['paymentOptions', 'Payment options'], ['paymentInstructions', 'Payment details for shops'], ['logo', 'Logo'],
]
const show = (v) => (Array.isArray(v) ? v.map((x) => x.label ?? x).join(', ') : v) || '—'

function SupplierDetail() {
  const { supplier: s } = useLoaderData()
  const revalidator = useRevalidator()
  const [error, setError] = useState(null)
  const [reset, setReset] = useState(null)
  const [label, tone] = SUPPLIER_STATUS[s.status] ?? [s.status, 'neutral']
  const p = s.profile

  async function run(fn) {
    setError(null)
    try {
      if ((await fn()) !== false) revalidator.revalidate()
    } catch (e) {
      setError(e.message)
    }
  }

  return (
    <>
      <title>{`${p.name} — Suppliers — Topline admin`}</title>
      <Link to="/admin/suppliers" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink"><ArrowLeft className="size-4" /> All suppliers</Link>

      <section className="flex flex-col gap-4 rounded-card bg-white p-6 ring-1 ring-line sm:flex-row sm:items-center">
        <SupplierLogo supplier={p} className="size-16" />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-bold text-navy">{p.name}</h1>
            <Badge tone={tone}>{label}</Badge>
          </div>
          <p className="mt-1 text-sm text-muted">
            Signed up {formatDate(s.createdAt)} by {s.login ? `${s.login.name}, ${s.login.phone}` : 'unknown'}
            {s.approvedAt && ` · ${s.login?.lastLoginAt ? `last in ${formatDate(s.login.lastLoginAt)}` : 'hasn’t logged in yet'} · works with ${s.connectedShops} shops`}
          </p>
          {s.rejectionReason && <p className="mt-1 text-sm text-bad">Rejected: {s.rejectionReason}</p>}
        </div>
        <div className="flex flex-wrap gap-2">
          {s.status === 'pending' && <Button variant="ghost" onClick={() => run(() => reviewSignUp({ id: s.id, name: p.name }, 'reject'))}><X className="size-4" />Reject</Button>}
          {(s.status === 'pending' || s.status === 'rejected') && <Button variant="navy" onClick={() => run(() => reviewSignUp({ id: s.id, name: p.name }, 'approve'))}><Check className="size-4" />Approve</Button>}
          {s.status === 'hidden' && <Button variant="outline" onClick={() => run(() => api.adminUpdateSupplier(s.id, { listed: true }))}>List again</Button>}
          {s.status === 'approved' && <Button variant="outline" onClick={() => window.confirm(`Hide ${p.name} from the home page and from shops?`) && run(() => api.adminUpdateSupplier(s.id, { listed: false }))}>Hide</Button>}
          {s.approvedAt && (
            <Button variant="ghost" onClick={() => window.confirm('Give them a new temporary password? The current one stops working.') && api.adminResetSupplierPassword(s.id).then(setReset, (e) => setError(e.message))}>
              <KeyRound className="size-4" /> Reset password
            </Button>
          )}
        </div>
      </section>
      {error && <p className="mt-4 rounded-lg bg-bad-tint px-4 py-3 text-sm text-bad" role="alert">{error}</p>}

      <section className="mt-6 rounded-card bg-white ring-1 ring-line" aria-labelledby="profile-h">
        <h2 id="profile-h" className="border-b border-line px-5 py-4 font-bold">Profile</h2>
        <dl className="divide-y divide-line text-sm">
          {PROFILE_ROWS.map(([key, name]) => (
            <div key={key} className="grid gap-1 px-5 py-2.5 sm:grid-cols-[12rem_minmax(0,1fr)]">
              <dt className="text-muted">{name}</dt>
              <dd className="min-w-0 break-words">{key === 'logo' && p.logo ? <Img name={p.logo} alt="" w={120} className="size-14 rounded-lg object-contain" /> : show(p[key])}</dd>
            </div>
          ))}
        </dl>
      </section>

      <section className="mt-6 rounded-card bg-white ring-1 ring-line" aria-labelledby="products-h">
        <h2 id="products-h" className="border-b border-line px-5 py-4 font-bold">Products ({s.products.length})</h2>
        {s.products.length === 0 && <p className="px-5 py-4 text-muted">No products yet.</p>}
        <ul className="divide-y divide-line">
          {s.products.map((v) => (
            <li key={v.id} className="flex flex-wrap items-center gap-4 px-5 py-3 text-sm">
              {v.image && <Img name={v.image} alt="" w={100} className="size-12 rounded-lg" />}
              <div className="min-w-0 flex-1">
                <p className="font-medium">{v.name}</p>
                <p className="text-muted">{v.price ? `${formatKES(v.price)} per ${v.unit}` : 'Price on request'} · min. {v.minQty} {v.unit}{v.inStock ? '' : ' · out of stock'}</p>
                {v.description && <p className="text-xs text-muted">{v.description}</p>}
              </div>
            </li>
          ))}
        </ul>
      </section>

      <section className="mt-6 rounded-card bg-white ring-1 ring-line" aria-labelledby="orders-h">
        <h2 id="orders-h" className="border-b border-line px-5 py-4 font-bold">Orders from shops ({s.orders.length})</h2>
        {s.orders.length === 0 && <p className="px-5 py-4 text-muted">No orders yet.</p>}
        <ul className="divide-y divide-line">
          {s.orders.map((o) => {
            const [olabel, otone] = SUPPLIER_ORDER_STATUS[o.status] ?? [o.status, 'neutral']
            return (
              <li key={o.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm">
                <span className="w-24 font-medium">{o.reference}</span>
                <span className="w-44">{o.store?.name}</span>
                <span className="min-w-0 flex-1 truncate text-muted">{o.items.map((i) => `${i.qty} ${i.name}`).join(', ')}</span>
                <span className="tabular">{o.estimatedTotal ? formatKES(o.estimatedTotal) : '—'}</span>
                <span className="text-muted">{formatDate(o.createdAt)}</span>
                <Badge tone={otone}>{olabel}</Badge>
              </li>
            )
          })}
        </ul>
      </section>

      <Dialog open={Boolean(reset)} onClose={() => setReset(null)} title="Temporary password">
        {reset && <TempPasswordCard name={reset.name} login={reset.login} password={reset.temporaryPassword} />}
      </Dialog>
    </>
  )
}

export { SupplierDetail as Component }
