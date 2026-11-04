import { useState } from 'react'
import { useFetcher, useLoaderData } from 'react-router'
import { FileText, Phone, Receipt, Share2 } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate, formatKES } from '@/lib/format'
import { QUOTE_STATUS } from '@/lib/labels'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { useVendor } from './VendorLayout'
import { quotationText, waNumber } from './quotation'
import { requireStoreId } from './guard'

export async function loader() {
  return { quotes: await api.listQuotes(requireStoreId()) }
}

export async function action({ request }) {
  const fd = await request.formData()
  try {
    await api.setQuoteStatus(requireStoreId(), fd.get('quoteId'), fd.get('status'))
    return { ok: true }
  } catch (e) {
    return { error: e.message }
  }
}

const TABS = [['open', 'Open'], ['won', 'Became sales'], ['all', 'All']]

/** Quotations: customers' requests from the store page, and ones priced at the till. Each can turn into a sale. */
function Quotations() {
  const { quotes } = useLoaderData()
  const [tab, setTab] = useState('open')
  const rows = quotes.filter((q) => (tab === 'all' ? true : tab === 'won' ? q.status === 'won' : ['new', 'replied'].includes(q.status)))

  return (
    <>
      <title>Quotations — Topline</title>
      <PageHeader title="Quotations" actions={<Button to="/dashboard/sell" variant="navy"><FileText className="size-4" />New quotation</Button>}>
        Customers ask for prices on your store page; you price them at the till and send them on WhatsApp. When they come to buy, turn the quotation into a sale.
      </PageHeader>
      <div className="mb-4 inline-flex gap-1 rounded-lg bg-white p-1 ring-1 ring-line">
        {TABS.map(([id, label]) => (
          <button key={id} type="button" aria-pressed={tab === id} onClick={() => setTab(id)}
            className={cn('rounded-md px-3 py-1.5 text-sm', tab === id ? 'bg-navy text-white' : 'text-muted hover:text-ink')}>{label}</button>
        ))}
      </div>
      {rows.length === 0 ? (
        <EmptyState icon={FileText} title="No quotations here">On the till, add items and press “Save as quotation”. Requests from your store page show up here too.</EmptyState>
      ) : (
        <ul className="space-y-4">{rows.map((q) => <QuoteCard key={q.id} quote={q} />)}</ul>
      )}
    </>
  )
}

function QuoteCard({ quote: q }) {
  const { store } = useVendor()
  const fetcher = useFetcher()
  const [label, tone] = QUOTE_STATUS[q.status] ?? [q.status, 'neutral']
  const priced = q.lines.length > 0
  const waPhone = waNumber(q.phone)
  return (
    <li className="rounded-card bg-white p-5 ring-1 ring-line">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="font-bold">{q.reference} · {q.name}</p>
          <p className="mt-0.5 flex flex-wrap items-center gap-x-3 text-sm text-muted">
            <span>{q.source === 'customer' ? 'Asked on your store' : 'Made at the till'} · {formatDate(q.createdAt)}</span>
            {q.phone && <a href={`tel:${waPhone ? `+${waPhone}` : q.phone}`} className="inline-flex items-center gap-1 text-navy hover:underline"><Phone className="size-3.5" />{q.phone}</a>}
          </p>
        </div>
        <Badge tone={tone}>{label}</Badge>
      </div>
      {q.items && <p className="mt-3 rounded-lg bg-concrete px-3 py-2 text-sm break-words whitespace-pre-line">“{q.items}”{q.site && <span className="mt-1 block text-muted">Site: {q.site}</span>}</p>}
      {priced && (
        <ul className="mt-3 space-y-1 text-sm">
          {q.lines.map((l) => (
            <li key={l.productId} className="flex justify-between gap-3"><span>{l.qty} × {l.name}</span><span className="tabular">{formatKES(l.qty * l.price)}</span></li>
          ))}
          <li className="flex justify-between border-t border-line pt-1 font-bold"><span>Total</span><span className="tabular">{formatKES(q.total)}</span></li>
        </ul>
      )}
      <div className="mt-4 flex flex-wrap gap-2">
        {q.status !== 'won' && (
          <Button size="sm" variant="navy" to={`/dashboard/sell?quote=${q.id}`}>
            <Receipt className="size-4" />{priced ? 'Turn into a sale' : 'Price it at the till'}
          </Button>
        )}
        {priced && q.status !== 'won' && (
          <Button size="sm" variant="outline" href={`https://wa.me/${waPhone}?text=${encodeURIComponent(quotationText(store, q))}`} target="_blank" rel="noreferrer">
            <Share2 className="size-4" />Send on WhatsApp
          </Button>
        )}
        {q.saleId && <span className="self-center text-sm text-muted">Sale {q.saleId}</span>}
        {['new', 'replied'].includes(q.status) && (
          <fetcher.Form method="post">
            <input type="hidden" name="quoteId" value={q.id} />
            <button name="status" value="lost" className="rounded-lg px-2.5 py-1.5 text-sm text-muted hover:bg-concrete hover:text-ink" disabled={fetcher.state !== 'idle'}>Mark as lost</button>
          </fetcher.Form>
        )}
      </div>
      {fetcher.data?.error && <p className="mt-2 text-sm text-bad" role="alert">{fetcher.data.error}</p>}
    </li>
  )
}

export { Quotations as Component }
