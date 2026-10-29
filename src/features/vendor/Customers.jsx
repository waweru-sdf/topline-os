import { useEffect, useState } from 'react'
import { useFetcher, useLoaderData } from 'react-router'
import { MessageCircle, Plus, Users } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate, formatKES, normalizePhone } from '@/lib/format'
import { payToLine } from '@/lib/payments'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Dialog } from '@/components/ui/Dialog'
import { Field } from '@/components/ui/Field'
import { EmptyState } from '@/components/ui/EmptyState'
import { useVendor } from './VendorLayout'
import { requireStoreId } from './guard'

const TODAY = new Date('2026-10-29T08:00:00.000Z')

export async function loader() {
  return { customers: await api.listCustomers(requireStoreId()) }
}

export async function action({ request }) {
  const storeId = requireStoreId()
  const fd = await request.formData()
  try {
    if (fd.get('intent') === 'payment') {
      await api.recordCustomerPayment(storeId, fd.get('customerId'), Number(fd.get('amount')))
    } else {
      await api.saveCustomer(storeId, { name: fd.get('name'), phone: fd.get('phone'), creditLimit: Number(fd.get('creditLimit')) || 0 })
    }
    return { ok: true }
  } catch (e) {
    return { error: e.message, field: e.field }
  }
}

function Customers() {
  const { customers } = useLoaderData()
  const { store } = useVendor()
  const [paying, setPaying] = useState(null)
  const [adding, setAdding] = useState(false)
  const owed = customers.reduce((s, c) => s + c.balance, 0)
  const sorted = [...customers].sort((a, b) => b.balance - a.balance)

  return (
    <>
      <title>Customers — Topline</title>
      <PageHeader title="Customers & credit" actions={<Button variant="navy" onClick={() => setAdding(true)}><Plus className="size-4" />Add customer</Button>}>
        Customers owe you {formatKES(owed)} in total.
      </PageHeader>

      {customers.length === 0 ? (
        <EmptyState icon={Users} title="No customers yet">Add the fundis and contractors who buy on credit so you can track what they owe.</EmptyState>
      ) : (
        <div className="overflow-x-auto rounded-card bg-white ring-1 ring-line">
          <table className="w-full min-w-[44rem] text-sm">
            <thead className="text-left text-muted">
              <tr className="border-b border-line">
                <th className="px-4 py-3 font-medium">Customer</th>
                <th className="px-3 py-3 text-right font-medium">Owes</th>
                <th className="px-3 py-3 font-medium">Credit used</th>
                <th className="px-3 py-3 font-medium">Due</th>
                <th className="px-4 py-3"><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {sorted.map((c) => {
                const overdue = c.balance > 0 && c.dueAt && new Date(c.dueAt) < TODAY
                const used = c.creditLimit ? Math.min(100, (c.balance / c.creditLimit) * 100) : 0
                const phone = normalizePhone(c.phone)
                const reminder = `Habari ${c.name}, this is ${store.name}. Your balance is ${formatKES(c.balance)}${c.dueAt ? `, due ${formatDate(c.dueAt)}` : ''}. Pay via M-PESA ${payToLine(store.payments)}. Asante!`
                return (
                  <tr key={c.id}>
                    <td className="px-4 py-3">
                      <span className="block font-medium">{c.name}</span>
                      <span className="block text-xs text-muted">{c.phone || 'No phone'}</span>
                    </td>
                    <td className={cn('px-3 py-3 text-right font-bold tabular', c.balance ? 'text-ink' : 'text-muted')}>{formatKES(c.balance)}</td>
                    <td className="px-3 py-3">
                      {c.creditLimit ? (
                        <div className="w-36">
                          <div className="h-1.5 rounded-full bg-concrete">
                            <div className={cn('h-full rounded-full', used > 90 ? 'bg-bad' : 'bg-navy')} style={{ width: `${used}%` }} />
                          </div>
                          <span className="mt-1 block text-xs text-muted">of {formatKES(c.creditLimit)} limit</span>
                        </div>
                      ) : <span className="text-xs text-muted">No credit</span>}
                    </td>
                    <td className="px-3 py-3">
                      {overdue ? <Badge tone="bad">Overdue since {formatDate(c.dueAt)}</Badge> : c.dueAt ? formatDate(c.dueAt) : <span className="text-muted">—</span>}
                    </td>
                    <td className="px-4 py-3">
                      {c.balance > 0 && (
                        <div className="flex justify-end gap-1">
                          {phone && (
                            <a href={`https://wa.me/${phone}?text=${encodeURIComponent(reminder)}`} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-muted hover:bg-concrete hover:text-ink">
                              <MessageCircle className="size-4" /> Remind
                            </a>
                          )}
                          <button type="button" onClick={() => setPaying(c)} className="rounded-lg px-2.5 py-1.5 font-medium text-navy hover:bg-concrete">Record payment</button>
                        </div>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {paying && <PaymentDialog customer={paying} onClose={() => setPaying(null)} />}
      {adding && <AddCustomerDialog onClose={() => setAdding(false)} />}
    </>
  )
}

/** Close a dialog once its fetcher action reports success. */
function useCloseOnSuccess(fetcher, onClose) {
  const done = fetcher.state === 'idle' && fetcher.data?.ok
  useEffect(() => {
    if (done) onClose()
  }, [done, onClose])
}

function PaymentDialog({ customer, onClose }) {
  const fetcher = useFetcher()
  const busy = fetcher.state !== 'idle'
  useCloseOnSuccess(fetcher, onClose)
  return (
    <Dialog open onClose={onClose} title={`Payment from ${customer.name}`}>
      <fetcher.Form method="post" className="space-y-4">
        <input type="hidden" name="intent" value="payment" />
        <input type="hidden" name="customerId" value={customer.id} />
        <p className="text-sm text-muted">They owe {formatKES(customer.balance)}.</p>
        <Field name="amount" label="Amount received" prefix="KSh" inputMode="numeric" defaultValue={customer.balance} autoFocus error={fetcher.data?.error} />
        <Button type="submit" variant="navy" className="w-full" pending={busy}>Record payment</Button>
      </fetcher.Form>
    </Dialog>
  )
}

function AddCustomerDialog({ onClose }) {
  const fetcher = useFetcher()
  useCloseOnSuccess(fetcher, onClose)
  return (
    <Dialog open onClose={onClose} title="Add a customer">
      <fetcher.Form method="post" className="space-y-4">
        <Field name="name" label="Name" placeholder="Otieno Contractors" required autoFocus error={fetcher.data?.field === 'name' ? fetcher.data.error : undefined} />
        <Field name="phone" label="Phone" type="tel" placeholder="0712 345 678" />
        <Field name="creditLimit" label="Credit limit" prefix="KSh" inputMode="numeric" hint="Leave at 0 if they always pay on the spot." defaultValue={0} />
        <Button type="submit" variant="navy" className="w-full" pending={fetcher.state !== 'idle'}>Add customer</Button>
      </fetcher.Form>
    </Dialog>
  )
}

export { Customers as Component }
