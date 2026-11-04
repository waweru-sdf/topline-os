import { useState, useTransition } from 'react'
import { useLoaderData, useRevalidator, useSearchParams } from 'react-router'
import { CircleCheck, FileText, Minus, Plus, Printer, Search, Share2, Trash2, X } from 'lucide-react'
import { api } from '@/services/api'
import { formatKES } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Img } from '@/components/ui/Img'
import { METHOD_LABEL } from '@/lib/labels'
import { Dialog } from '@/components/ui/Dialog'
import { branchParam, useVendor } from './VendorLayout'
import { requireStoreId } from './guard'
import { quotationText, waNumber } from './quotation'

export async function loader({ request }) {
  const storeId = requireStoreId()
  const quoteId = new URL(request.url).searchParams.get('quote')
  const [{ products, categories }, customers, quotes] = await Promise.all([
    api.listProducts(storeId, branchParam(storeId)), api.listCustomers(storeId), quoteId ? api.listQuotes(storeId) : [],
  ])
  return { products, categories, customers, quote: quotes.find((q) => q.id === quoteId) ?? null }
}

/** A quotation's lines as till lines, at today's products (lines for deleted products are dropped). */
const ticketFrom = (quote, products) =>
  (quote?.lines ?? []).flatMap((l) => { const product = products.find((p) => p.id === l.productId); return product ? [{ product, qty: l.qty }] : [] })

const METHODS = ['mpesa', 'cash', 'bank', 'credit']

function Sell() {
  const { products, categories, customers, quote } = useLoaderData()
  const { store, branch } = useVendor()
  const revalidator = useRevalidator()
  const [, setParams] = useSearchParams()
  const [query, setQuery] = useState('')
  const [category, setCategory] = useState('')
  const [ticket, setTicket] = useState(() => ticketFrom(quote, products)) // { product, qty }
  const [quoting, setQuoting] = useState(false)
  const [method, setMethod] = useState('mpesa')
  const [customerId, setCustomerId] = useState('')
  const [error, setError] = useState(null)
  const [receipt, setReceipt] = useState(null)
  const [isPending, startTransition] = useTransition()

  const terms = query.toLowerCase().split(/\s+/).filter(Boolean)
  const list = products.filter((p) => (!category || p.categoryId === category) && terms.every((t) => p.name.toLowerCase().includes(t)))
  const total = ticket.reduce((s, l) => s + l.qty * l.product.sellPrice, 0)

  const addLine = (product) =>
    setTicket((t) => (t.some((l) => l.product.id === product.id) ? t.map((l) => (l.product.id === product.id ? { ...l, qty: l.qty + 1 } : l)) : [...t, { product, qty: 1 }]))
  const setQty = (id, qty) => setTicket((t) => (qty <= 0 ? t.filter((l) => l.product.id !== id) : t.map((l) => (l.product.id === id ? { ...l, qty } : l))))

  function completeSale() {
    setError(null)
    startTransition(async () => {
      try {
        const sale = await api.createSale(store.id, {
          items: ticket.map((l) => ({ productId: l.product.id, qty: l.qty })),
          method,
          customerId: method === 'credit' ? customerId : null,
          branchId: branch.id,
          quoteId: quote?.status !== 'won' ? quote?.id : undefined,
        })
        setReceipt(sale)
        setTicket([])
        setCustomerId('')
        if (quote) setParams({}, { replace: true })
        revalidator.revalidate() // refresh stock counts
      } catch (e) {
        setError(e.message)
      }
    })
  }

  if (receipt) return <Receipt sale={receipt} store={store} onNew={() => setReceipt(null)} />

  return (
    <div className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,1fr)_24rem]">
      <title>Sell — Topline</title>
      <section aria-label="Products">
        <h1 className="sr-only">Sell at the counter</h1>
        {quote && (
          <div className="mb-4 flex gap-3 rounded-card bg-amber-tint px-4 py-3 text-sm">
            <FileText className="mt-0.5 size-4 shrink-0" />
            <div className="min-w-0 flex-1">
              <p className="font-medium">Quotation {quote.reference} for {quote.name}{quote.phone && ` (${quote.phone})`}{quote.status === 'won' && ': already sold'}</p>
              {quote.items && <p className="mt-1 break-words whitespace-pre-line text-ink/80">“{quote.items}”</p>}
            </div>
            <button type="button" onClick={() => { setParams({}, { replace: true }); setTicket([]) }} aria-label="Stop working on this quotation" className="h-fit rounded p-1 hover:bg-amber/30"><X className="size-4" /></button>
          </div>
        )}
        <label className="relative block">
          <span className="sr-only">Find a product</span>
          <Search aria-hidden className="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-steel" />
          <input
            type="search"
            autoFocus
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Find a product: cement, Y12, mabati…"
            className="h-12 w-full rounded-lg border border-line bg-white pr-4 pl-11 focus:border-navy focus:ring-3 focus:ring-navy/15 focus:outline-none"
          />
        </label>
        <div className="relative mt-3 flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none]">
          {[{ id: '', name: 'All' }, ...categories].map((c) => (
            <button
              key={c.id || 'all'}
              type="button"
              aria-pressed={category === c.id}
              onClick={() => setCategory(c.id)}
              className={cn('shrink-0 rounded-full px-3.5 py-1.5 text-sm', category === c.id ? 'bg-navy text-white' : 'bg-white ring-1 ring-line hover:ring-steel')}
            >
              {c.name}
            </button>
          ))}
        </div>
        <ul className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
          {list.map((p) => (
            <li key={p.id}>
              <button
                type="button"
                onClick={() => addLine(p)}
                className="flex h-full w-full flex-col overflow-hidden rounded-card bg-white text-left ring-1 ring-line transition hover:ring-2 hover:ring-navy"
              >
                <Img name={p.image} alt="" w={320} h={200} className="aspect-[8/5] w-full" />
                <span className="flex flex-1 flex-col p-3">
                  <span className="text-sm leading-snug font-medium">{p.name}</span>
                  <span className="mt-auto pt-2 text-sm">
                    <span className="font-bold tabular">{formatKES(p.sellPrice)}</span>
                    <span className="text-muted"> / {p.unit}</span>
                  </span>
                  <span className={cn('text-xs', p.stock <= 0 ? 'text-bad' : p.stock <= p.reorderAt ? 'text-amber-deep' : 'text-muted')}>
                    {p.stock} in stock
                  </span>
                </span>
              </button>
            </li>
          ))}
        </ul>
      </section>

      <section aria-label="Current sale" className="h-fit rounded-card bg-white ring-1 ring-line lg:sticky lg:top-24">
        <h2 className="border-b border-line px-5 py-4 font-bold">Current sale</h2>
        {ticket.length === 0 ? (
          <p className="px-5 py-10 text-center text-sm text-muted">Tap a product to add it to the sale.</p>
        ) : (
          <ul className="max-h-[22rem] divide-y divide-line overflow-y-auto">
            {ticket.map(({ product, qty }) => (
              <li key={product.id} className="px-5 py-3">
                <div className="flex justify-between gap-3 text-sm">
                  <span className="font-medium">{product.name}</span>
                  <span className="font-bold tabular">{formatKES(qty * product.sellPrice)}</span>
                </div>
                <div className="mt-2 flex items-center gap-2">
                  <button type="button" aria-label="One less" onClick={() => setQty(product.id, qty - 1)} className="flex size-8 items-center justify-center rounded-md ring-1 ring-line hover:bg-concrete"><Minus className="size-3.5" /></button>
                  <input
                    aria-label={`Quantity of ${product.name}`}
                    inputMode="numeric"
                    value={qty}
                    onChange={(e) => setQty(product.id, Number(e.target.value.replace(/\D/g, '')) || 0)}
                    className="h-8 w-14 rounded-md text-center text-sm font-bold ring-1 ring-line tabular focus:ring-navy focus:outline-none"
                  />
                  <button type="button" aria-label="One more" onClick={() => setQty(product.id, qty + 1)} className="flex size-8 items-center justify-center rounded-md ring-1 ring-line hover:bg-concrete"><Plus className="size-3.5" /></button>
                  <span className="text-xs text-muted">{product.unit}</span>
                  <button type="button" aria-label={`Remove ${product.name}`} onClick={() => setQty(product.id, 0)} className="ml-auto text-muted hover:text-bad"><Trash2 className="size-4" /></button>
                </div>
              </li>
            ))}
          </ul>
        )}

        <div className="space-y-4 border-t border-line p-5">
          <fieldset>
            <legend className="text-sm font-medium">Paid by</legend>
            <div className="mt-2 grid grid-cols-4 gap-1.5">
              {METHODS.map((m) => (
                <label key={m} className={cn('cursor-pointer rounded-lg py-2 text-center text-sm ring-1', method === m ? 'bg-navy text-white ring-navy' : 'ring-line hover:ring-steel')}>
                  <input type="radio" name="method" value={m} checked={method === m} onChange={() => setMethod(m)} className="sr-only" />
                  {METHOD_LABEL[m]}
                </label>
              ))}
            </div>
          </fieldset>
          {method === 'credit' && (
            <Field as="select" label="Customer" value={customerId} onChange={(e) => setCustomerId(e.target.value)}>
              <option value="">Choose who is buying on credit</option>
              {customers.filter((c) => c.creditLimit > 0).map((c) => (
                <option key={c.id} value={c.id}>{c.name} ({formatKES(c.creditLimit - c.balance)} available)</option>
              ))}
            </Field>
          )}
          {error && <p className="text-sm text-bad" role="alert">{error}</p>}
          <div className="flex items-baseline justify-between">
            <span className="text-muted">Total</span>
            <span className="text-3xl font-bold text-navy tabular">{formatKES(total)}</span>
          </div>
          <Button size="lg" className="w-full" disabled={!ticket.length} pending={isPending} onClick={completeSale}>
            Complete sale
          </Button>
          <Button variant="ghost" className="w-full" disabled={!ticket.length} onClick={() => setQuoting(true)}>
            <FileText className="size-4" />Save as quotation
          </Button>
        </div>
      </section>
      {quoting && <SaveQuotation store={store} ticket={ticket} quote={quote} onClose={() => setQuoting(false)} />}
    </div>
  )
}

/** Saves the current items as a priced quotation (or prices a customer's request) and offers to send it on WhatsApp. */
function SaveQuotation({ store, ticket, quote, onClose }) {
  const [name, setName] = useState(quote?.name ?? '')
  const [phone, setPhone] = useState(quote?.phone ?? '')
  const [saved, setSaved] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  async function save(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      setSaved(await api.saveQuotation(store.id, { quoteId: quote?.status !== 'won' ? quote?.id : undefined, name, phone,
        items: ticket.map((l) => ({ productId: l.product.id, qty: l.qty })) }))
    } catch (e2) {
      setError(e2)
    } finally {
      setBusy(false)
    }
  }
  const waPhone = waNumber(saved?.phone)
  return (
    <Dialog open onClose={onClose} title={saved ? `Quotation ${saved.reference} saved` : 'Save as quotation'}>
      {saved ? (
        <div className="space-y-4">
          <p className="text-sm text-muted">{saved.name}: {formatKES(saved.total)}. When they come back, open it from Quotations and turn it into a sale.</p>
          <div className="grid gap-2 sm:grid-cols-2">
            <Button variant="navy" href={`https://wa.me/${waPhone}?text=${encodeURIComponent(quotationText(store, saved))}`} target="_blank" rel="noreferrer"><Share2 className="size-4" />Send on WhatsApp</Button>
            <Button variant="outline" to="/dashboard/quotations">Go to quotations</Button>
          </div>
        </div>
      ) : (
        <form onSubmit={save} className="space-y-4">
          {!quote && (
            <>
              <Field label="Customer’s name" value={name} onChange={(e) => setName(e.target.value)} error={error?.field === 'name' ? error.message : undefined} required />
              <Field label="Phone (optional)" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} error={error?.field === 'phone' ? error.message : undefined} placeholder="0712 345 678" />
            </>
          )}
          <p className="text-sm text-muted">{ticket.length} items at today’s prices. Saving doesn’t take anything from stock.</p>
          {error && !['name', 'phone'].includes(error.field) && <p className="text-sm text-bad" role="alert">{error.message}</p>}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
            <Button type="submit" variant="navy" pending={busy}>Save quotation</Button>
          </div>
        </form>
      )}
    </Dialog>
  )
}

function Receipt({ sale, store, onNew }) {
  const lines = sale.items.map((i) => `${i.qty} × ${i.name} = ${formatKES(i.qty * i.price)}`).join('\n')
  const text = `${store.name}\nReceipt ${sale.id}\n\n${lines}\n\nTotal: ${formatKES(sale.total)}\nPaid by: ${METHOD_LABEL[sale.method]}\n\nAsante!`
  return (
    <div className="mx-auto max-w-md">
      <title>Sale complete — Topline</title>
      <div className="rounded-card bg-white p-6 ring-1 ring-line">
        <CircleCheck className="size-10 text-good" aria-hidden />
        <h1 className="mt-3 text-2xl font-bold text-navy">Sale {sale.id} complete</h1>
        <ul className="mt-5 space-y-1.5 text-sm">
          {sale.items.map((i) => (
            <li key={i.productId} className="flex justify-between gap-3">
              <span>{i.qty} × {i.name}</span>
              <span className="tabular">{formatKES(i.qty * i.price)}</span>
            </li>
          ))}
        </ul>
        <p className="mt-4 flex justify-between border-t border-line pt-4 text-lg font-bold">
          <span>Total</span><span className="tabular">{formatKES(sale.total)}</span>
        </p>
        <p className="mt-1 text-sm text-muted">Paid by {METHOD_LABEL[sale.method]}</p>
        <div className="mt-6 grid grid-cols-2 gap-2">
          <Button variant="outline" href={`https://wa.me/?text=${encodeURIComponent(text)}`} target="_blank" rel="noreferrer">
            <Share2 className="size-4" />WhatsApp receipt
          </Button>
          <Button variant="outline" onClick={() => window.print()}><Printer className="size-4" />Print</Button>
        </div>
        <Button variant="navy" className="mt-2 w-full" onClick={onNew}>Start a new sale</Button>
      </div>
    </div>
  )
}

export { Sell as Component }
