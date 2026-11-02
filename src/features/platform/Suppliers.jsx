import { useState } from 'react'
import { Link, useLoaderData, useRevalidator } from 'react-router'
import { Factory, Plus } from 'lucide-react'
import { api } from '@/services/api'
import { pluralize } from '@/lib/format'
import { SUPPLIER_STATUS } from '@/lib/labels'
import { PageHeader } from '@/components/layout/AppShell'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { EmptyState } from '@/components/ui/EmptyState'
import { Field } from '@/components/ui/Field'
import { SupplierLogo } from '@/components/SupplierLogo'
import { TempPasswordCard } from '@/components/TempPasswordCard'
import { requirePlatformAdmin } from '@/features/vendor/guard'

export async function loader() {
  requirePlatformAdmin()
  return api.adminSuppliers()
}

function Suppliers() {
  const { suppliers, pendingReviews } = useLoaderData()
  const [adding, setAdding] = useState(false)
  return (
    <>
      <title>Suppliers — Topline admin</title>
      <PageHeader title="Suppliers" actions={<Button variant="navy" onClick={() => setAdding(true)}><Plus className="size-4" />Add supplier</Button>}>
        {pendingReviews ? `${pluralize(pendingReviews, 'item')} waiting for your approval.` : 'Nothing waiting for approval.'} Approved suppliers appear on the home page and to every shop.
      </PageHeader>
      {suppliers.length === 0 ? (
        <EmptyState icon={Factory} title="No suppliers yet">Add a manufacturer or distributor. They get a login to fill in their profile and products, and you approve them.</EmptyState>
      ) : (
        <div className="relative overflow-x-auto rounded-card bg-white ring-1 ring-line">
          <table className="w-full min-w-[48rem] text-sm">
            <thead className="text-left text-muted">
              <tr className="border-b border-line">
                <th className="px-4 py-3 font-medium">Supplier</th>
                <th className="px-3 py-3 font-medium">Status</th>
                <th className="px-3 py-3 font-medium">Waiting for you</th>
                <th className="px-3 py-3 text-right font-medium">Products</th>
                <th className="px-3 py-3 text-right font-medium">Orders</th>
                <th className="px-4 py-3 text-right font-medium">Shops</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {suppliers.map((s) => {
                const [label, tone] = SUPPLIER_STATUS[s.status] ?? [s.status, 'neutral']
                const waiting = [s.reviewStatus === 'pending' && 'profile', s.productsWaiting && pluralize(s.productsWaiting, 'product')].filter(Boolean)
                return (
                  <tr key={s.id}>
                    <td className="px-4 py-3">
                      <Link to={`/admin/suppliers/${s.id}`} className="flex items-center gap-3 hover:underline">
                        <SupplierLogo supplier={s} className="size-10 text-base" />
                        <span><span className="block font-medium">{s.name}</span><span className="block text-xs text-muted">{s.location || '—'}</span></span>
                      </Link>
                    </td>
                    <td className="px-3 py-3"><Badge tone={tone}>{label}</Badge></td>
                    <td className="px-3 py-3">{waiting.length ? <Badge tone="amber">Review {waiting.join(' + ')}</Badge> : <span className="text-muted">—</span>}</td>
                    <td className="px-3 py-3 text-right tabular">{s.products}</td>
                    <td className="px-3 py-3 text-right tabular">{s.orders}</td>
                    <td className="px-4 py-3 text-right tabular">{s.connectedShops}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
      <AddSupplierDialog open={adding} onClose={() => setAdding(false)} />
    </>
  )
}

function AddSupplierDialog({ open, onClose }) {
  const revalidator = useRevalidator()
  const [form, setForm] = useState({ name: '', contactName: '', phone: '', email: '' })
  const [created, setCreated] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const err = (f) => (error?.field === f ? error.message : undefined)
  function close() {
    setForm({ name: '', contactName: '', phone: '', email: '' })
    setCreated(null)
    setError(null)
    onClose()
  }
  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      setCreated(await api.adminCreateSupplier(form))
      revalidator.revalidate()
    } catch (err2) {
      setError({ message: err2.message, field: err2.field })
    } finally {
      setBusy(false)
    }
  }
  return (
    <Dialog open={open} onClose={close} title={created ? 'Supplier added' : 'Add a supplier'}>
      {created ? (
        <>
          <TempPasswordCard name={form.contactName} login={created.login} password={created.temporaryPassword}
            intro={`${created.supplier.live.name} can now log in to fill in their profile and products. Give ${form.contactName} this temporary password; it’s shown only once.`} />
          <div className="mt-6 flex justify-end gap-2">
            <Button variant="ghost" onClick={close}>Done</Button>
            <Button variant="navy" to={`/admin/suppliers/${created.supplier.id}`} onClick={close}>Open supplier</Button>
          </div>
        </>
      ) : (
        <form onSubmit={submit} className="space-y-4" noValidate>
          <Field label="Company name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={err('name')} required />
          <Field label="Person who will log in" value={form.contactName} onChange={(e) => setForm({ ...form, contactName: e.target.value })} error={err('contactName')} required />
          <Field label="Their mobile number (their login)" type="tel" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} error={err('phone')} required />
          <Field label="Email (optional)" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} error={err('email')} />
          {error && !['name', 'contactName', 'phone', 'email'].includes(error.field) && <p className="text-sm text-bad" role="alert">{error.message}</p>}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={close}>Cancel</Button>
            <Button type="submit" variant="navy" pending={busy}>Add supplier</Button>
          </div>
        </form>
      )}
    </Dialog>
  )
}

export { Suppliers as Component }
