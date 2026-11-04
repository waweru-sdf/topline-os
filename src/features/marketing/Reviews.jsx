import { useState } from 'react'
import { useRouteLoaderData, useSearchParams } from 'react-router'
import { CircleCheck, Lock, Star } from 'lucide-react'
import { api } from '@/services/api'
import { cn } from '@/lib/cn'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'

const RATING_WORDS = ['', 'Very poor', 'Poor', 'Okay', 'Good', 'Excellent']
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Nairobi' }) // YYYY-MM-DD

/** /reviews: anyone tells Topline how a hardware shop treated them. Only Topline admins read it. */
function Reviews() {
  const { site } = useRouteLoaderData('marketing')
  // From a store's "review this shop" link: its slug (sent along, never shown) and its name to start with.
  const [params] = useSearchParams()
  const empty = { store: params.get('store') ?? '', storeName: (params.get('name') ?? '').slice(0, 120), rating: 0, body: '', visitedOn: today(), name: '', phone: '' }
  const [form, setForm] = useState(empty)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [sent, setSent] = useState(false)
  const update = (patch) => { setError(null); setForm((f) => ({ ...f, ...patch })) }
  const err = (f) => (error?.field === f ? error.message : undefined)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.sendReview(form)
      setSent(true)
      window.scrollTo(0, 0)
    } catch (e2) {
      setError({ message: e2.message, field: e2.field })
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="bg-concrete">
      <title>Reviews — Topline</title>
      <div className="mx-auto max-w-2xl px-5 py-12 md:py-16">
        {sent ? (
          <div className="rounded-card bg-white p-7 text-center shadow-lift" role="status">
            <CircleCheck className="mx-auto size-12 text-good" />
            <h1 className="mt-4 text-2xl font-bold text-navy">Thank you for your review</h1>
            <p className="mt-3 leading-relaxed text-muted">The Topline team will read it. If you left your number, we may call you about it.</p>
            <div className="mt-6 flex flex-wrap justify-center gap-3">
              <Button variant="outline" onClick={() => { setForm({ ...empty, store: '', storeName: '' }); setSent(false) }}>Review another shop</Button>
              <Button to="/" variant="navy">Back to the home page</Button>
            </div>
          </div>
        ) : (
          <>
            <h1 className="text-3xl font-bold tracking-tight text-navy">{site.reviewsTitle}</h1>
            <p className="mt-3 leading-relaxed text-muted">{site.reviewsText}</p>
            <p className="mt-3 flex items-center gap-2 text-sm text-muted"><Lock className="size-4" />Only the Topline team sees your review and your details.</p>

            <form onSubmit={submit} noValidate className="mt-8 space-y-5 rounded-card bg-white p-6 shadow-lift sm:p-7">
              <Field label="Shop name" value={form.storeName} onChange={(e) => update({ storeName: e.target.value, store: '' })} error={err('storeName')} maxLength={120}
                placeholder="e.g. Mjengo Hardware" hint="As it appears on the shop’s signboard or Topline link. Add the town if you know it." required />

              <fieldset>
                <legend className="text-sm font-medium">How was it?</legend>
                <div className="mt-1.5 flex flex-wrap items-center gap-3">
                  <div className="flex gap-1">
                    {[1, 2, 3, 4, 5].map((n) => (
                      <label key={n} className="cursor-pointer rounded-md p-1 has-focus-visible:ring-3 has-focus-visible:ring-navy/30">
                        <input type="radio" name="rating" value={n} checked={form.rating === n} onChange={() => update({ rating: n })} className="sr-only" />
                        <Star aria-hidden className={cn('size-8 transition-colors', n <= form.rating ? 'fill-amber text-amber' : 'text-line hover:text-amber')} />
                        <span className="sr-only">{n} {n === 1 ? 'star' : 'stars'}: {RATING_WORDS[n]}</span>
                      </label>
                    ))}
                  </div>
                  <span className="text-sm text-muted" aria-hidden>{RATING_WORDS[form.rating]}</span>
                </div>
                {err('rating') && <p className="mt-1 text-sm text-bad">{err('rating')}</p>}
              </fieldset>

              <Field as="textarea" label="Your review" value={form.body} onChange={(e) => update({ body: e.target.value })} error={err('body')} maxLength={2000} rows={5}
                hint="What did you buy or ask for, and how were you treated? Prices, quality, delivery, honesty." required />
              <Field label="When did you deal with the shop?" type="date" value={form.visitedOn} max={today()} onChange={(e) => update({ visitedOn: e.target.value })} error={err('visitedOn')} required />
              <div className="grid gap-5 sm:grid-cols-2">
                <Field label="Your name" value={form.name} onChange={(e) => update({ name: e.target.value })} error={err('name')} autoComplete="name" required />
                <Field label="Phone (optional)" type="tel" value={form.phone} onChange={(e) => update({ phone: e.target.value })} error={err('phone')}
                  placeholder="0712 345 678" hint="So we can follow up if needed." autoComplete="tel" />
              </div>
              {error && !['storeName', 'rating', 'body', 'visitedOn', 'name', 'phone'].includes(error.field) && <p className="text-sm text-bad" role="alert">{error.message}</p>}
              <Button type="submit" variant="navy" className="w-full" pending={busy}>Send review</Button>
            </form>
          </>
        )}
      </div>
    </section>
  )
}

export { Reviews as Component }
