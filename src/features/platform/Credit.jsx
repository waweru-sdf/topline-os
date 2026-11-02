import { useState } from 'react'
import { useFetcher, useLoaderData, useNavigate, useSearchParams } from 'react-router'
import { HandCoins, MessageCircle, Phone } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate, formatKES, normalizePhone } from '@/lib/format'
import { CREDIT_PURPOSE, CREDIT_STATUS } from '@/lib/labels'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { requirePlatformAdmin } from '@/features/vendor/guard'

export async function loader({ request }) {
  requirePlatformAdmin()
  return api.adminCreditRequests(new URL(request.url).searchParams.get('status') || undefined)
}

export async function action({ request }) {
  const fd = await request.formData()
  try {
    await api.adminUpdateCreditRequest(fd.get('id'), { status: fd.get('status'), adminNote: fd.get('adminNote') })
    return { ok: true }
  } catch (e) {
    return { error: e.message }
  }
}

const FILTERS = [['', 'All'], ['new', 'New'], ['reviewing', 'Being reviewed'], ['approved', 'Approved'], ['declined', 'Not approved'], ['closed', 'Closed']]

function Credit() {
  const { open, requests } = useLoaderData()
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const status = params.get('status') ?? ''
  return (
    <>
      <title>Credit requests — Topline admin</title>
      <PageHeader title="Credit requests">{open ? `${open} waiting for a decision.` : 'Nothing waiting.'} Shops asking for stock credit through your lending partner.</PageHeader>
      <div className="relative mb-4 flex gap-1 overflow-x-auto rounded-lg bg-white p-1 ring-1 ring-line">
        {FILTERS.map(([id, label]) => (
          <button key={id || 'all'} type="button" aria-pressed={status === id} onClick={() => navigate(id ? `?status=${id}` : '.')}
            className={cn('shrink-0 rounded-md px-3 py-1.5 text-sm', status === id ? 'bg-navy text-white' : 'text-muted hover:text-ink')}>{label}</button>
        ))}
      </div>
      {requests.length === 0 ? (
        <EmptyState icon={HandCoins} title="No credit requests">Shop owners request credit from Stock credit in their shop admin.</EmptyState>
      ) : (
        <ul className="space-y-4">{requests.map((r) => <RequestCard key={r.id} request={r} />)}</ul>
      )}
    </>
  )
}

function RequestCard({ request: r }) {
  const fetcher = useFetcher()
  const [note, setNote] = useState(r.adminNote)
  const [label, tone] = CREDIT_STATUS[fetcher.formData?.get('status') ?? r.status] ?? [r.status, 'neutral']
  const phone = normalizePhone(r.contactPhone)
  return (
    <li className="rounded-card bg-white p-5 ring-1 ring-line">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="font-bold">{r.store?.name} <span className="font-normal text-muted">· {r.reference} · {formatDate(r.createdAt)}</span></p>
          <p className="text-sm text-muted">{[r.store?.town, r.store?.county].filter(Boolean).join(', ')} · {r.store?.planId} plan · on Topline since {r.store?.createdAt ? formatDate(r.store.createdAt) : '—'}</p>
        </div>
        <Badge tone={tone}>{label}</Badge>
      </div>
      <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-4">
        <div><dt className="text-muted">Amount</dt><dd className="text-lg font-bold tabular">{formatKES(r.amount)}</dd></div>
        <div><dt className="text-muted">For</dt><dd>{CREDIT_PURPOSE[r.purpose] ?? r.purpose}</dd></div>
        <div><dt className="text-muted">Repay over</dt><dd>{r.periodMonths} months</dd></div>
        <div><dt className="text-muted">Their monthly sales</dt><dd className="tabular">{r.monthlySales != null ? formatKES(r.monthlySales) : 'Not given'}</dd></div>
      </dl>
      {r.notes && <p className="mt-3 rounded-lg bg-concrete px-3 py-2 text-sm">“{r.notes}”</p>}
      <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4 text-sm">
        <span className="mr-auto"><span className="text-muted">Contact:</span> <span className="font-medium">{r.contactName}</span>, {r.contactPhone}</span>
        <a href={`tel:${r.contactPhone.replace(/\s/g, '')}`} className="flex items-center gap-1.5 rounded-lg px-3 py-2 font-medium text-navy ring-1 ring-line hover:bg-concrete"><Phone className="size-4" />Call</a>
        {phone && <a href={`https://wa.me/${phone}`} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 rounded-lg bg-good px-3 py-2 font-medium text-white hover:opacity-90"><MessageCircle className="size-4" />WhatsApp</a>}
      </div>
      <fetcher.Form method="post" className="mt-3 flex flex-wrap items-end gap-2">
        <input type="hidden" name="id" value={r.id} />
        <label className="min-w-[14rem] flex-1 text-sm">
          <span className="text-muted">Note (only Topline admins see this)</span>
          <input name="adminNote" value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} className="mt-1 h-10 w-full rounded-lg border border-line px-3" />
        </label>
        <label className="text-sm">
          <span className="text-muted">Status</span>
          <select name="status" defaultValue={r.status} className="mt-1 block h-10 rounded-lg border border-line bg-white px-3">
            {Object.entries(CREDIT_STATUS).map(([id, [l]]) => <option key={id} value={id}>{l}</option>)}
          </select>
        </label>
        <button className="h-10 rounded-lg bg-navy px-4 text-sm font-medium text-white hover:bg-navy-deep" disabled={fetcher.state !== 'idle'}>Save</button>
      </fetcher.Form>
      {fetcher.data?.error && <p className="mt-2 text-sm text-bad" role="alert">{fetcher.data.error}</p>}
    </li>
  )
}

export { Credit as Component }
