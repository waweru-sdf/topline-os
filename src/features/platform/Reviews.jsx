import { useFetcher, useLoaderData, useSearchParams } from 'react-router'
import { Check, MessageSquareText, Phone, RotateCcw, Trash2 } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { Stars } from '@/components/Stars'
import { requirePlatformAdmin } from '@/features/vendor/guard'

export async function loader({ request }) {
  requirePlatformAdmin()
  const storeId = new URL(request.url).searchParams.get('store') || undefined
  return api.adminReviews({ storeId })
}

export async function action({ request }) {
  const fd = await request.formData()
  try {
    if (fd.get('intent') === 'delete') await api.adminDeleteReview(fd.get('id'))
    else await api.adminSetReviewStatus(fd.get('id'), fd.get('status'))
    return { ok: true }
  } catch (e) {
    return { error: e.message }
  }
}

/** Topline admin: reviews of shops, sent from the public Reviews page. Shops never see them. */
function Reviews() {
  const { stores, reviews } = useLoaderData()
  const [params, setParams] = useSearchParams()
  const storeId = params.get('store') ?? ''

  return (
    <>
      <title>Reviews — Topline admin</title>
      <PageHeader title="Reviews">What customers say about shops on Topline. Only Topline admins see these, never the shops. Use them to follow up on shops that treat customers badly.</PageHeader>

      {stores.length > 0 && (
        <div className="mb-5 flex flex-wrap items-center gap-3">
          <label htmlFor="store-filter" className="text-sm font-medium">Topline shop</label>
          <select id="store-filter" value={storeId} onChange={(e) => setParams(e.target.value ? { store: e.target.value } : {})}
            className="h-10 rounded-lg border border-line bg-white px-3 text-sm">
            <option value="">All reviews</option>
            {stores.map((s) => <option key={s.id} value={s.id}>{s.name}: {s.average} ★ from {s.count}</option>)}
          </select>
        </div>
      )}

      {reviews.length === 0 ? (
        <EmptyState icon={MessageSquareText} title="No reviews yet">Customers can review a shop from the Reviews page in the main menu, or from the link at the bottom of every store.</EmptyState>
      ) : (
        <ul className="space-y-4">
          {reviews.map((r) => <ReviewCard key={r.id} review={r} />)}
        </ul>
      )}
    </>
  )
}

function ReviewCard({ review: r }) {
  const fetcher = useFetcher()
  const busy = fetcher.state !== 'idle'
  const done = r.status === 'done'
  return (
    <li className={cn('rounded-card bg-white p-5 ring-1 ring-line', done && 'opacity-70')}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="font-bold">{r.storeName}</p>
          <p className="text-xs text-muted">{r.store ? `Topline shop: ${r.store.name}` : 'Not matched to a Topline shop'}</p>
          <p className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
            <Stars rating={r.rating} />
            <span>Dealt with the shop on {formatDate(r.visitedOn)}</span>
            <span>Sent {formatDate(r.createdAt)}</span>
          </p>
        </div>
        {!done && <Badge tone="amber">New</Badge>}
      </div>
      <p className="mt-3 leading-relaxed break-words whitespace-pre-line">{r.body}</p>
      <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3 text-sm">
        <p className="flex items-center gap-2">
          {r.name}
          {r.phone && <a href={`tel:${r.phone.replace(/\s/g, '')}`} className="inline-flex items-center gap-1 text-navy hover:underline"><Phone className="size-3.5" />{r.phone}</a>}
        </p>
        <fetcher.Form method="post" className="flex gap-2">
          <input type="hidden" name="id" value={r.id} />
          <button name="status" value={done ? 'new' : 'done'} disabled={busy} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 font-medium text-navy hover:bg-mist/40">
            {done ? <><RotateCcw className="size-4" />Mark as new</> : <><Check className="size-4" />Mark as done</>}
          </button>
          <button name="intent" value="delete" disabled={busy} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 font-medium text-bad hover:bg-bad-tint"
            onClick={(e) => { if (!window.confirm('Delete this review for good? Use this for spam.')) e.preventDefault() }}>
            <Trash2 className="size-4" />Delete
          </button>
        </fetcher.Form>
      </div>
      {fetcher.data?.error && <p className="mt-2 text-sm text-bad" role="alert">{fetcher.data.error}</p>}
    </li>
  )
}

export { Reviews as Component }
