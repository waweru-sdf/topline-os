import { useState } from 'react'
import { Link, useLoaderData, useRevalidator } from 'react-router'
import { Check, Factory, Phone, X } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate } from '@/lib/format'
import { SUPPLIER_STATUS } from '@/lib/labels'
import { PageHeader } from '@/components/layout/AppShell'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { SupplierLogo } from '@/components/SupplierLogo'
import { requirePlatformAdmin } from '@/features/vendor/guard'

export async function loader() {
  requirePlatformAdmin()
  return api.adminSuppliers()
}

/** Approve or reject a sign-up. Returns false if the admin cancelled. Used here and on the supplier's page. */
export async function reviewSignUp(supplier, action) {
  const note = action === 'reject' ? window.prompt(`Why isn’t ${supplier.name} approved? (they’ll see this in an SMS)`) : ''
  if (note === null) return false
  await api.adminReviewSupplier(supplier.id, action, note)
  return true
}

function Suppliers() {
  const { suppliers } = useLoaderData()
  const waiting = suppliers.filter((s) => s.status === 'pending')
  const others = suppliers.filter((s) => s.status !== 'pending')
  return (
    <>
      <title>Suppliers — Topline admin</title>
      <PageHeader title="Suppliers">
        Manufacturers and distributors sign up on the home page. Approve them and they appear on the home page and to every shop; after that they keep their own profile and prices up to date.
      </PageHeader>

      <section aria-labelledby="waiting-h">
        <h2 id="waiting-h" className="text-lg font-bold">Waiting for approval {waiting.length > 0 && <Badge tone="amber" className="ml-1 align-middle">{waiting.length}</Badge>}</h2>
        {waiting.length === 0 ? (
          <p className="mt-2 text-muted">No new sign-ups.</p>
        ) : (
          <ul className="mt-3 grid gap-4 lg:grid-cols-2">
            {waiting.map((s) => <SignUpCard key={s.id} supplier={s} />)}
          </ul>
        )}
      </section>

      <section className="mt-10" aria-labelledby="all-h">
        <h2 id="all-h" className="text-lg font-bold">All suppliers</h2>
        {others.length === 0 ? (
          <div className="mt-3"><EmptyState icon={Factory} title="No suppliers yet">Approved suppliers will be listed here.</EmptyState></div>
        ) : (
          <div className="relative mt-3 overflow-x-auto rounded-card bg-white ring-1 ring-line">
            <table className="w-full min-w-[44rem] text-sm">
              <thead className="text-left text-muted">
                <tr className="border-b border-line">
                  <th className="px-4 py-3 font-medium">Supplier</th>
                  <th className="px-3 py-3 font-medium">Status</th>
                  <th className="px-3 py-3 text-right font-medium">Products</th>
                  <th className="px-3 py-3 text-right font-medium">Orders</th>
                  <th className="px-3 py-3 text-right font-medium">Shops</th>
                  <th className="px-4 py-3 font-medium">Signed up</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {others.map((s) => {
                  const [label, tone] = SUPPLIER_STATUS[s.status] ?? [s.status, 'neutral']
                  return (
                    <tr key={s.id}>
                      <td className="px-4 py-3">
                        <Link to={`/admin/suppliers/${s.id}`} className="flex items-center gap-3 hover:underline">
                          <SupplierLogo supplier={s} className="size-10 text-base" />
                          <span><span className="block font-medium">{s.name}</span><span className="block text-xs text-muted">{s.location || '—'}</span></span>
                        </Link>
                      </td>
                      <td className="px-3 py-3"><Badge tone={tone}>{label}</Badge></td>
                      <td className="px-3 py-3 text-right tabular">{s.products}</td>
                      <td className="px-3 py-3 text-right tabular">{s.orders}</td>
                      <td className="px-3 py-3 text-right tabular">{s.connectedShops}</td>
                      <td className="px-4 py-3 text-muted">{formatDate(s.createdAt)}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  )
}

function SignUpCard({ supplier: s }) {
  const revalidator = useRevalidator()
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)
  async function decide(action) {
    setBusy(action)
    setError(null)
    try {
      if (await reviewSignUp(s, action)) revalidator.revalidate()
    } catch (e) {
      setError(e.message)
    } finally {
      setBusy(null)
    }
  }
  return (
    <li className="flex flex-col rounded-card bg-white p-5 ring-1 ring-line">
      <div className="flex items-start gap-4">
        <SupplierLogo supplier={s} className="size-12" />
        <div className="min-w-0 flex-1">
          <Link to={`/admin/suppliers/${s.id}`} className="font-bold hover:underline">{s.name}</Link>
          <p className="text-sm text-muted">{[s.location, s.categories].filter(Boolean).join(' · ') || '—'} · signed up {formatDate(s.createdAt)}</p>
        </div>
      </div>
      <p className="mt-3 flex-1 text-sm leading-relaxed break-words">{s.description}</p>
      {s.login && (
        <p className="mt-3 flex flex-wrap items-center gap-x-2 text-sm">
          <Phone className="size-4 text-muted" />{s.login.name},
          <a href={`tel:${s.login.phone.replace(/\s/g, '')}`} className="text-navy hover:underline">{s.login.phone}</a>
          {s.login.email && <span className="text-muted">· {s.login.email}</span>}
        </p>
      )}
      {error && <p className="mt-2 text-sm text-bad" role="alert">{error}</p>}
      <div className="mt-4 flex justify-end gap-2">
        <Button size="sm" variant="ghost" onClick={() => decide('reject')} pending={busy === 'reject'} disabled={Boolean(busy)}><X className="size-4" />Reject</Button>
        <Button size="sm" variant="navy" onClick={() => decide('approve')} pending={busy === 'approve'} disabled={Boolean(busy)}><Check className="size-4" />Approve</Button>
      </div>
    </li>
  )
}

export { Suppliers as Component }
