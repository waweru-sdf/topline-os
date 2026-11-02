import { useState } from 'react'
import { useLoaderData, useRevalidator, useSearchParams } from 'react-router'
import { CircleCheck, HandCoins } from 'lucide-react'
import { api } from '@/services/api'
import { displayPhone, formatDate, formatKES } from '@/lib/format'
import { CREDIT_PURPOSE, CREDIT_STATUS } from '@/lib/labels'
import { PageHeader } from '@/components/layout/AppShell'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { useVendor } from './VendorLayout'
import { requireStoreId } from './guard'

export async function loader() {
  return { requests: await api.listCreditRequests(requireStoreId()) }
}

const OPEN = ['new', 'reviewing']

function Credit() {
  const { requests } = useLoaderData()
  const open = requests.find((r) => OPEN.includes(r.status))
  const [sent, setSent] = useState(null)

  return (
    <>
      <title>Stock credit — Topline</title>
      <PageHeader title="Stock credit">Credit for stock through Topline’s lending partner, based on how your shop is doing on Topline.</PageHeader>

      {sent ? (
        <Received request={sent} />
      ) : open ? (
        <section className="rounded-card bg-white p-6 ring-1 ring-line">
          <HandCoins className="size-8 text-amber-deep" aria-hidden />
          <h2 className="mt-3 text-lg font-bold">We’re processing your request {open.reference}</h2>
          <p className="mt-1 text-muted">You asked for {formatKES(open.amount)} on {formatDate(open.createdAt)}. We’ll reach out on {open.contactPhone} after processing.</p>
        </section>
      ) : (
        <CreditForm onSent={setSent} />
      )}

      {requests.length > 0 && (
        <section className="mt-8" aria-labelledby="past-h">
          <h2 id="past-h" className="text-lg font-bold">Your requests</h2>
          <ul className="mt-3 divide-y divide-line rounded-card bg-white ring-1 ring-line">
            {requests.map((r) => {
              const [label, tone] = CREDIT_STATUS[r.status] ?? [r.status, 'neutral']
              return (
                <li key={r.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm">
                  <span className="w-24 font-medium">{r.reference}</span>
                  <span className="w-28 font-bold tabular">{formatKES(r.amount)}</span>
                  <span className="min-w-0 flex-1 text-muted">{CREDIT_PURPOSE[r.purpose] ?? r.purpose} · {r.periodMonths} months · {formatDate(r.createdAt)}</span>
                  <Badge tone={tone}>{label}</Badge>
                </li>
              )
            })}
          </ul>
        </section>
      )}
    </>
  )
}

function CreditForm({ onSent }) {
  const { user, store } = useVendor()
  const [params] = useSearchParams()
  const revalidator = useRevalidator()
  const fromOrder = params.get('order')
  const [form, setForm] = useState({
    amount: params.get('amount') || '',
    purpose: fromOrder ? 'supplier_order' : 'stock',
    periodMonths: '3',
    monthlySales: '',
    contactName: user.name,
    contactPhone: displayPhone(user.phone) || '',
    notes: '',
    consent: false,
  })
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const update = (patch) => setForm((f) => ({ ...f, ...patch }))
  const err = (f) => (error?.field === f ? error.message : undefined)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const made = await api.requestCredit(store.id, {
        ...form,
        amount: Number(form.amount),
        periodMonths: Number(form.periodMonths),
        monthlySales: form.monthlySales === '' ? null : Number(form.monthlySales),
        supplierOrderId: fromOrder || undefined,
      })
      onSent(made)
      revalidator.revalidate()
    } catch (err2) {
      setError({ message: err2.message, field: err2.field })
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} noValidate className="grid gap-6 rounded-card bg-white p-6 ring-1 ring-line lg:grid-cols-2">
      <div className="space-y-4">
        <h2 className="text-lg font-bold">Request credit</h2>
        <Field label="How much do you need?" prefix="KSh" inputMode="numeric" value={form.amount} onChange={(e) => update({ amount: e.target.value.replace(/\D/g, '') })} error={err('amount')} hint="From KSh 5,000." required />
        <Field as="select" label="What is it for?" value={form.purpose} onChange={(e) => update({ purpose: e.target.value })} error={err('purpose')}>
          {Object.entries(CREDIT_PURPOSE).map(([id, label]) => <option key={id} value={id}>{label}</option>)}
        </Field>
        <Field as="select" label="Repay over" value={form.periodMonths} onChange={(e) => update({ periodMonths: e.target.value })} error={err('periodMonths')}>
          {[1, 3, 6, 12].map((m) => <option key={m} value={m}>{m === 1 ? '1 month' : `${m} months`}</option>)}
        </Field>
        <Field label="Your shop’s usual monthly sales (optional)" prefix="KSh" inputMode="numeric" value={form.monthlySales} onChange={(e) => update({ monthlySales: e.target.value.replace(/\D/g, '') })} error={err('monthlySales')} hint="Include sales outside Topline. It helps the lender decide." />
      </div>
      <div className="space-y-4">
        <Field label="Who should we talk to?" value={form.contactName} onChange={(e) => update({ contactName: e.target.value })} error={err('contactName')} required />
        <Field label="Their phone" type="tel" value={form.contactPhone} onChange={(e) => update({ contactPhone: e.target.value })} error={err('contactPhone')} required />
        <Field as="textarea" label="Anything else? (optional)" value={form.notes} onChange={(e) => update({ notes: e.target.value })} error={err('notes')} maxLength={1000} />
        <label className="flex gap-3 rounded-lg bg-concrete p-3 text-sm">
          <input type="checkbox" checked={form.consent} onChange={(e) => update({ consent: e.target.checked })} className="mt-0.5 size-4 accent-navy" aria-invalid={err('consent') ? true : undefined} />
          <span>I agree that Topline may share a summary of {store.name}’s sales on Topline with its lending partner to assess this request.</span>
        </label>
        {err('consent') && <p className="text-sm text-bad">{err('consent')}</p>}
        {error && !['amount', 'purpose', 'periodMonths', 'monthlySales', 'contactName', 'contactPhone', 'notes', 'consent'].includes(error.field) && (
          <p className="rounded-lg bg-bad-tint px-3 py-2 text-sm text-bad" role="alert">{error.message}</p>
        )}
        <Button type="submit" variant="navy" size="lg" className="w-full" pending={busy}>Request credit</Button>
      </div>
    </form>
  )
}

function Received({ request }) {
  return (
    <section className="rounded-card bg-white p-6 ring-1 ring-line" role="status">
      <CircleCheck className="size-10 text-good" aria-hidden />
      <h2 className="mt-3 text-xl font-bold">Information received</h2>
      <p className="mt-2 max-w-xl text-muted">
        Thank you. Your request {request.reference} for {formatKES(request.amount)} has been received. We will reach out on {request.contactPhone} after processing.
      </p>
    </section>
  )
}

export { Credit as Component }
