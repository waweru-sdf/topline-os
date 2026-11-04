import { useEffect, useState } from 'react'
import { useLoaderData, useRevalidator } from 'react-router'
import { BookOpenText, Phone, Plus, Trash2 } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate, formatKES } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { EmptyState } from '@/components/ui/EmptyState'
import { Field } from '@/components/ui/Field'
import { useVendor } from './VendorLayout'
import { requireStoreId } from './guard'

export async function loader() {
  return api.listSupplierAccounts(requireStoreId())
}

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Nairobi' })
const isOverdue = (date) => date && date < today()

/** What the shop owes the suppliers it buys from on credit: bills add to a balance, payments take it down. */
function SupplierBalances() {
  const { accounts, totalOwed } = useLoaderData()
  const [open, setOpen] = useState(null) // account id
  const [adding, setAdding] = useState(false)

  return (
    <>
      <title>Supplier balances — Topline</title>
      <PageHeader title="Supplier balances" actions={<Button variant="navy" onClick={() => setAdding(true)}><Plus className="size-4" />Add supplier</Button>}>
        Keep track of what you owe the suppliers you buy from on credit. Record each bill when goods come in, and each payment you make.
      </PageHeader>

      <div className="mb-5 rounded-card bg-white p-5 ring-1 ring-line">
        <p className="text-sm text-muted">You owe suppliers</p>
        <p className="mt-1 text-3xl font-bold text-navy tabular">{formatKES(totalOwed)}</p>
      </div>

      {accounts.length === 0 ? (
        <EmptyState icon={BookOpenText} title="No suppliers yet">Add the wholesalers and distributors you buy from on credit.</EmptyState>
      ) : (
        <ul className="divide-y divide-line rounded-card bg-white ring-1 ring-line">
          {accounts.map((a) => (
            <li key={a.id}>
              <button type="button" onClick={() => setOpen(a.id)} className="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-left hover:bg-concrete/50">
                <span className="min-w-0 flex-1">
                  <span className="block font-medium">{a.name}</span>
                  <span className="block text-sm text-muted">
                    {a.phone ?? 'No phone'}
                    {a.nextDue && <span className={cn(isOverdue(a.nextDue) && 'font-medium text-bad')}> · {isOverdue(a.nextDue) ? 'overdue since' : 'due'} {formatDate(a.nextDue)}</span>}
                  </span>
                </span>
                <span className={cn('font-bold tabular', a.balance > 0 ? 'text-ink' : 'text-good')}>{a.balance > 0 ? formatKES(a.balance) : a.balance < 0 ? `${formatKES(-a.balance)} in credit` : 'Settled'}</span>
              </button>
            </li>
          ))}
        </ul>
      )}

      {adding && <AccountDialog onClose={() => setAdding(false)} onSaved={(a) => { setAdding(false); setOpen(a.id) }} />}
      {open && <AccountDetail accountId={open} onClose={() => setOpen(null)} />}
    </>
  )
}

function AccountDialog({ account, onClose, onSaved }) {
  const { store } = useVendor()
  const revalidator = useRevalidator()
  const [form, setForm] = useState({ id: account?.id, name: account?.name ?? '', phone: account?.phone ?? '', note: account?.note ?? '' })
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  async function save(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const saved = await api.saveSupplierAccount(store.id, form)
      revalidator.revalidate()
      onSaved(saved)
    } catch (e2) {
      setError(e2)
      setBusy(false)
    }
  }
  const err = (f) => (error?.field === f ? error.message : undefined)
  return (
    <Dialog open onClose={onClose} title={account ? `Edit ${account.name}` : 'Add supplier'}>
      <form onSubmit={save} className="space-y-4">
        <Field label="Supplier name" value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} error={err('name')} required />
        <Field label="Phone (optional)" type="tel" value={form.phone} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} error={err('phone')} />
        <Field label="Note (optional)" value={form.note} onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))} placeholder="e.g. Paybill 123456, account MJENGO" />
        {error && !['name', 'phone'].includes(error.field) && <p className="text-sm text-bad" role="alert">{error.message}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="navy" pending={busy}>Save</Button>
        </div>
      </form>
    </Dialog>
  )
}

/** One supplier: balance, history, and recording a bill or a payment. */
function AccountDetail({ accountId, onClose }) {
  const { store } = useVendor()
  const revalidator = useRevalidator()
  const [account, setAccount] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [kind, setKind] = useState('bill')
  const [form, setForm] = useState({ amount: '', date: today(), dueOn: '', note: '' })
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [editing, setEditing] = useState(false)

  useEffect(() => {
    let current = true
    api.getSupplierAccount(store.id, accountId).then((a) => current && setAccount(a), (e) => current && setLoadError(e.message))
    return () => { current = false }
  }, [store.id, accountId])

  async function add(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      setAccount(await api.addSupplierEntry(store.id, accountId, { kind, ...form, dueOn: kind === 'bill' ? form.dueOn : '' }))
      setForm({ amount: '', date: today(), dueOn: '', note: '' })
      revalidator.revalidate()
    } catch (e2) {
      setError(e2)
    } finally {
      setBusy(false)
    }
  }
  async function remove() {
    if (!window.confirm(`Remove ${account.name} and its history?`)) return
    try {
      await api.deleteSupplierAccount(store.id, accountId)
      revalidator.revalidate()
      onClose()
    } catch (e2) {
      setError(e2)
    }
  }
  const err = (f) => (error?.field === f ? error.message : undefined)

  return (
    <Dialog open onClose={onClose} title={account?.name ?? 'Supplier'} className="sm:max-w-xl">
      {loadError && <p className="text-sm text-bad" role="alert">{loadError}</p>}
      {!account && !loadError && <p className="text-sm text-muted">Loading…</p>}
      {account && (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-sm text-muted">You owe</p>
              <p className="text-2xl font-bold text-navy tabular">{formatKES(Math.max(0, account.balance))}</p>
              {account.phone && <a href={`tel:${account.phone.replace(/\s/g, '')}`} className="mt-1 inline-flex items-center gap-1 text-sm text-navy hover:underline"><Phone className="size-3.5" />{account.phone}</a>}
              {account.note && <p className="mt-1 text-sm text-muted">{account.note}</p>}
            </div>
            <div className="flex gap-1">
              <Button size="sm" variant="ghost" onClick={() => setEditing(true)}>Edit</Button>
              <button type="button" onClick={remove} className="rounded-lg p-2 text-muted hover:bg-bad-tint hover:text-bad" aria-label={`Remove ${account.name}`}><Trash2 className="size-4" /></button>
            </div>
          </div>

          <form onSubmit={add} className="space-y-3 rounded-lg border border-line p-4">
            <div role="radiogroup" aria-label="What happened" className="inline-flex rounded-lg bg-concrete p-1">
              {[['bill', 'They supplied goods (bill)'], ['payment', 'You paid them']].map(([k, label]) => (
                <button key={k} type="button" role="radio" aria-checked={kind === k} onClick={() => setKind(k)}
                  className={cn('rounded-md px-3 py-1.5 text-sm', kind === k ? 'bg-white text-navy shadow-sm' : 'text-muted')}>{label}</button>
              ))}
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Amount" prefix="KSh" inputMode="numeric" value={form.amount} onChange={(e) => setForm((f) => ({ ...f, amount: e.target.value }))} error={err('amount')} required />
              <Field label="Date" type="date" value={form.date} max={today()} onChange={(e) => setForm((f) => ({ ...f, date: e.target.value }))} error={err('date')} />
              {kind === 'bill' && <Field label="Due on (optional)" type="date" value={form.dueOn} onChange={(e) => setForm((f) => ({ ...f, dueOn: e.target.value }))} error={err('dueOn')} />}
              <Field label="Note (optional)" value={form.note} onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))} placeholder={kind === 'bill' ? 'Invoice number, goods' : 'M-PESA code'} />
            </div>
            {error && !['amount', 'date', 'dueOn'].includes(error.field) && <p className="text-sm text-bad" role="alert">{error.message}</p>}
            <Button type="submit" variant="navy" size="sm" pending={busy}>{kind === 'bill' ? 'Record bill' : 'Record payment'}</Button>
          </form>

          <div>
            <h3 className="font-bold">History</h3>
            {account.entries.length === 0 ? <p className="mt-1 text-sm text-muted">Nothing recorded yet.</p> : (
              <ul className="mt-2 divide-y divide-line text-sm">
                {account.entries.map((e) => (
                  <li key={e.id} className="flex flex-wrap justify-between gap-x-3 py-2">
                    <span>
                      {formatDate(e.date)} · {e.kind === 'bill' ? 'Bill' : 'Payment'}{e.note && `: ${e.note}`}
                      {e.dueOn && <span className="text-muted"> (due {formatDate(e.dueOn)})</span>}
                      <span className="block text-xs text-muted">by {e.by}</span>
                    </span>
                    <span className={cn('font-medium tabular', e.kind === 'payment' && 'text-good')}>{e.kind === 'payment' ? '−' : '+'}{formatKES(e.amount)}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
      {editing && <AccountDialog account={account} onClose={() => setEditing(false)} onSaved={(a) => { setEditing(false); setAccount(a) }} />}
    </Dialog>
  )
}

export { SupplierBalances as Component }
