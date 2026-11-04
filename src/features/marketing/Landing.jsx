import { useState } from 'react'
import { Link, useLoaderData, useNavigate, useRouteLoaderData } from 'react-router'
import { Check, CircleCheck, Factory, HandCoins, Link2, MessageCircle, Package, Receipt, ShieldCheck, Store, Users } from 'lucide-react'
import { api } from '@/services/api'
import { formatKES, slugify } from '@/lib/format'
import { storeLinkParts } from '@/lib/tenant'
import { cn } from '@/lib/cn'
import { Button } from '@/components/ui/Button'
import { Img } from '@/components/ui/Img'
import { SupplierLogo } from '@/components/SupplierLogo'

export async function loader() {
  // Suppliers are a nice-to-have on this page: if they can't load, the page still shows.
  const [plans, suppliers] = await Promise.all([api.getPlans(), api.listPublicSuppliers().catch(() => [])])
  return { plans, suppliers }
}

function Landing() {
  const { plans, suppliers } = useLoaderData()
  const { site } = useRouteLoaderData('marketing')
  return (
    <>
      <title>Topline — Run your hardware shop, sell online</title>
      <Hero site={site} />
      <Features site={site} />
      <GrowSection site={site} suppliers={suppliers} />
      <HowToStart site={site} />
      <Pricing site={site} plans={plans} />
      <Faq site={site} />
      <ClosingCta site={site} />
    </>
  )
}

/* ---------------- Hero ---------------- */

const PREVIEW_ITEMS = [
  { name: 'Bamburi cement 32.5R', unit: 'bag', price: 780, image: 'cement' },
  { name: 'D12 deformed bar', unit: '12 m length', price: 1540, image: 'rebar' },
  { name: 'Box profile mabati', unit: 'metre', price: 890, image: 'roofing' },
]

function Hero({ site }) {
  const navigate = useNavigate()
  const [shopName, setShopName] = useState('')
  const slug = slugify(shopName) || 'yourshop'
  const displayName = shopName.trim() || 'Your Hardware'

  function start(e) {
    e.preventDefault()
    navigate(`/start${shopName.trim() ? `?name=${encodeURIComponent(shopName.trim())}` : ''}`)
  }

  return (
    <section className="relative overflow-hidden bg-navy text-white">
      <div className="mx-auto grid max-w-6xl items-center gap-12 px-5 pt-14 pb-16 md:pt-20 lg:grid-cols-[1.05fr_1fr] lg:gap-10 lg:pb-24">
        <div className="animate-rise">
          <h1 className="text-[2.6rem] leading-[1.05] font-bold tracking-tight sm:text-6xl">{site.heroTitle}</h1>
          <p className="mt-5 max-w-lg text-lg leading-relaxed text-mist">{site.heroText}</p>

          <form onSubmit={start} className="mt-9 max-w-lg">
            <label htmlFor="hero-shop" className="text-sm font-medium text-mist">
              Type your shop name to see your link
            </label>
            <div className="mt-2 flex flex-col gap-2 sm:flex-row">
              <input
                id="hero-shop"
                value={shopName}
                onChange={(e) => setShopName(e.target.value)}
                placeholder="e.g. Mjengo Hardware"
                autoComplete="organization"
                className="h-13 flex-1 rounded-lg border border-white/20 bg-white/10 px-4 text-lg text-white placeholder:text-mist/60 focus:border-amber focus:outline-none"
              />
              <Button type="submit" size="lg">Open your store</Button>
            </div>
            <p className="mt-3 flex items-center gap-2 text-[0.95rem]" aria-live="polite">
              <Link2 aria-hidden className="size-4 text-amber" />
              <span className="font-medium break-all">
                <span className="text-mist">{storeLinkParts().before}</span>
                <span className="text-amber">{slug}</span>
                <span className="text-mist">{storeLinkParts().after}</span>
              </span>
            </p>
          </form>

          <p className="mt-8 text-sm text-mist/80">
            Want to look around first?{' '}
            <Link to="/s/mjengo" className="font-medium text-white underline decoration-amber decoration-2 underline-offset-4 hover:text-amber">
              Visit a demo store
            </Link>
          </p>
        </div>

        <StorePreview name={displayName} slug={slug} image={site.heroImage} />
      </div>
    </section>
  )
}

function StorePreview({ name, slug, image }) {
  return (
    <div className="relative mx-auto w-full max-w-md lg:max-w-none" aria-hidden>
      <Img name={image} alt="" w={900} h={1000} priority sizes="(min-width: 1024px) 520px, 90vw" className="aspect-[9/10] w-full rounded-2xl opacity-90" />

      {/* Mini storefront, updating as the owner types */}
      <div className="absolute inset-x-5 bottom-5 rounded-xl bg-white text-ink shadow-lift sm:inset-x-8">
        <div className="flex items-center gap-2 border-b border-line px-3 py-2">
          <span className="size-2.5 rounded-full bg-line" />
          <span className="truncate rounded-md bg-concrete px-2 py-1 text-xs text-muted">
            {storeLinkParts().before}<span className="font-medium text-navy">{slug}</span>{storeLinkParts().after}
          </span>
        </div>
        <div className="p-4">
          <p className="truncate text-lg font-bold text-navy">{name}</p>
          <ul className="mt-3 divide-y divide-line">
            {PREVIEW_ITEMS.map((item) => (
              <li key={item.name} className="flex items-center gap-3 py-2">
                <Img name={item.image} alt="" w={96} h={96} className="size-10 rounded-md" />
                <span className="min-w-0 flex-1 truncate text-sm">{item.name}</span>
                <span className="text-right text-sm whitespace-nowrap">
                  <span className="font-bold tabular">{formatKES(item.price)}</span>
                  <span className="text-muted"> / {item.unit}</span>
                </span>
              </li>
            ))}
          </ul>
        </div>
      </div>

      <div className="absolute -top-3 right-3 flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm text-ink shadow-lift sm:right-[-1rem]">
        <CircleCheck className="size-5 text-good" />
        <span>
          <span className="font-bold">M-PESA received</span>
          <span className="block text-xs text-muted">KSh 15,600 for 20 bags of cement</span>
        </span>
      </div>
    </div>
  )
}

/* ---------------- Features ---------------- */

// Words come from Site content in the Topline admin; icons and picture slots are fixed.
const FEATURES = [
  { n: 1, icon: Store, image: 'featureImageStore', alt: 'A hardware storefront' },
  { n: 2, icon: Receipt, image: 'featureImageTill', alt: 'Tools on display in a hardware shop' },
  { n: 3, icon: Users, image: 'featureImageStock', alt: 'An aisle of stocked shelves' },
]
const lines = (text) => (text || '').split('\n').map((l) => l.trim()).filter(Boolean)

function Features({ site }) {
  return (
    <section id="features" className="scroll-mt-20 bg-white">
      <div className="mx-auto max-w-6xl px-5 py-20 md:py-28">
        <h2 className="max-w-2xl text-3xl font-bold tracking-tight text-navy sm:text-4xl">
          {site.featuresTitle}
        </h2>
        <div className="mt-14 space-y-20 md:space-y-28">
          {FEATURES.map((f, i) => (
            <article key={f.n} className="grid items-center gap-8 md:grid-cols-2 md:gap-14">
              <div className={cn(i % 2 === 1 && 'md:order-2')}>
                <f.icon aria-hidden className="size-7 text-amber-deep" />
                <h3 className="mt-4 text-2xl leading-snug font-bold text-ink">{site[`feature${f.n}Title`]}</h3>
                <p className="mt-3 leading-relaxed text-muted">{site[`feature${f.n}Text`]}</p>
                <ul className="mt-6 space-y-2.5">
                  {lines(site[`feature${f.n}Points`]).map((pt) => (
                    <li key={pt} className="flex gap-3">
                      <Check aria-hidden className="mt-0.5 size-5 shrink-0 text-good" />
                      <span>{pt}</span>
                    </li>
                  ))}
                </ul>
              </div>
              <Img name={site[f.image]} alt={f.alt} w={800} h={600} sizes="(min-width: 768px) 560px, 92vw" className="aspect-[4/3] w-full rounded-2xl" />
            </article>
          ))}
        </div>
      </div>
    </section>
  )
}

/* ---------------- Suppliers and stock credit ---------------- */

function GrowSection({ site, suppliers }) {
  return (
    <section className="bg-navy text-white">
      <div className="mx-auto max-w-6xl px-5 py-20 md:py-24">
        <div id="suppliers" className="scroll-mt-24">
          <p className="text-sm font-semibold tracking-wide text-amber uppercase">{site.suppliersKicker}</p>
          <h2 className="mt-2 max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">{site.suppliersTitle}</h2>
          <p className="mt-3 max-w-2xl leading-relaxed text-mist">{site.suppliersText}</p>
          {suppliers.length === 0 ? (
            <div className="mt-10 flex items-center gap-4 rounded-2xl bg-white/5 p-6 ring-1 ring-white/10">
              <Factory aria-hidden className="size-8 shrink-0 text-amber" />
              <p className="text-mist">{site.suppliersEmpty}</p>
            </div>
          ) : (
            <ul className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
              {suppliers.map((sup) => (
                <li key={sup.id} className="flex flex-col rounded-2xl bg-white/5 p-6 ring-1 ring-white/10">
                  <div className="flex items-center gap-4">
                    <SupplierLogo supplier={sup} className="size-14 ring-white/10" />
                    <div className="min-w-0">
                      <h3 className="text-lg leading-snug font-bold">{sup.name}</h3>
                      {sup.location && <p className="text-sm text-mist">{sup.location}</p>}
                    </div>
                  </div>
                  <p className="mt-4 flex-1 leading-relaxed text-mist">{sup.description}</p>
                  {sup.categories.length > 0 && (
                    <ul className="mt-4 flex flex-wrap gap-1.5" aria-label="Categories">
                      {sup.categories.map((c) => <li key={c} className="rounded-full bg-white/10 px-2.5 py-0.5 text-xs">{c}</li>)}
                    </ul>
                  )}
                  <p className="mt-4 flex items-center gap-2 border-t border-white/10 pt-4 text-sm">
                    <Store aria-hidden className="size-4 text-amber" />
                    {sup.connectedShops === 0 ? 'New on Topline' : `Works with ${sup.connectedShops} hardware ${sup.connectedShops === 1 ? 'shop' : 'shops'} on Topline`}
                  </p>
                </li>
              ))}
            </ul>
          )}
          <p className="mt-8 flex flex-col gap-3 rounded-2xl bg-white/5 p-5 ring-1 ring-white/10 sm:flex-row sm:items-center">
            <Factory aria-hidden className="size-6 shrink-0 text-amber" />
            <span className="flex-1 text-mist">{site.suppliersJoinText}</span>
            <Button to="/suppliers/join" className="shrink-0">Sign up as a supplier</Button>
          </p>
        </div>

        <div id="stock-credit" className="mt-14 flex scroll-mt-24 flex-col gap-5 rounded-2xl bg-amber p-6 text-navy-deep sm:flex-row sm:items-center sm:p-8">
          <HandCoins aria-hidden className="size-10 shrink-0" />
          <div className="flex-1">
            <h2 className="text-2xl font-bold">{site.creditTitle}</h2>
            <p className="mt-1 max-w-2xl leading-relaxed">{site.creditText}</p>
          </div>
          <Link to="/dashboard/credit" className="shrink-0 rounded-lg bg-navy px-5 py-3 font-medium text-white hover:bg-navy-deep">Request stock credit</Link>
        </div>
      </div>
    </section>
  )
}

/* ---------------- How to start (a real sequence, so numbered) ---------------- */

function HowToStart({ site }) {
  return (
    <section id="how" className="scroll-mt-20 bg-concrete">
      <div className="mx-auto max-w-6xl px-5 py-20 md:py-24">
        <h2 className="text-3xl font-bold tracking-tight text-navy sm:text-4xl">{site.stepsTitle}</h2>
        <ol className="mt-12 grid gap-10 md:grid-cols-3 md:gap-8">
          {[1, 2, 3].map((n) => (
            <li key={n} className="border-t-2 border-navy pt-5">
              <span className="text-4xl font-bold text-amber-deep tabular">{n}</span>
              <h3 className="mt-3 text-lg font-bold">{site[`step${n}Title`]}</h3>
              <p className="mt-2 leading-relaxed text-muted">{site[`step${n}Text`]}</p>
            </li>
          ))}
        </ol>
      </div>
    </section>
  )
}

/* ---------------- Pricing ---------------- */

function Pricing({ site, plans }) {
  const [yearly, setYearly] = useState(false)
  return (
    <section id="pricing" className="scroll-mt-20 bg-white">
      <div className="mx-auto max-w-6xl px-5 py-20 md:py-28">
        <div className="flex flex-wrap items-end justify-between gap-6">
          <div>
            <h2 className="text-3xl font-bold tracking-tight text-navy sm:text-4xl">{site.pricingTitle}</h2>
            <p className="mt-3 max-w-xl text-muted">{site.pricingText}</p>
          </div>
          <div role="radiogroup" aria-label="Billing period" className="inline-flex rounded-lg bg-concrete p-1">
            {[
              [false, 'Monthly'],
              [true, 'Yearly (2 months free)'],
            ].map(([value, label]) => (
              <button
                key={label}
                type="button"
                role="radio"
                aria-checked={yearly === value}
                onClick={() => setYearly(value)}
                className={cn('rounded-md px-4 py-2 text-sm font-medium transition-colors', yearly === value ? 'bg-white text-navy shadow-sm' : 'text-muted hover:text-ink')}
              >
                {label}
              </button>
            ))}
          </div>
        </div>

        <div className="mt-12 grid gap-6 lg:grid-cols-3">
          {plans.map((plan) => (
            <article
              key={plan.id}
              className={cn('flex flex-col rounded-card border p-7', plan.popular ? 'border-navy bg-navy text-white' : 'border-line bg-white')}
            >
              <div className="flex items-center justify-between">
                <h3 className="text-xl font-bold">{plan.name}</h3>
                {plan.popular && <span className="rounded-full bg-amber px-2.5 py-0.5 text-xs font-bold text-navy-deep">Most shops pick this</span>}
              </div>
              <p className={cn('mt-2 text-sm', plan.popular ? 'text-mist' : 'text-muted')}>{plan.blurb}</p>
              <p className="mt-6">
                <span className="text-4xl font-bold tabular">{formatKES(yearly ? plan.priceYearly : plan.priceMonthly)}</span>
                <span className={cn('ml-1 text-sm', plan.popular ? 'text-mist' : 'text-muted')}>/ {yearly ? 'year' : 'month'}</span>
              </p>
              <ul className="mt-6 flex-1 space-y-2.5 text-[0.95rem]">
                {plan.features.map((f) => (
                  <li key={f} className="flex gap-2.5">
                    <Check aria-hidden className={cn('mt-0.5 size-4.5 shrink-0', plan.popular ? 'text-amber' : 'text-good')} />
                    {f}
                  </li>
                ))}
              </ul>
              <Button
                to={`/start?plan=${plan.id}&billing=${yearly ? 'yearly' : 'monthly'}`}
                variant={plan.popular ? 'primary' : 'outline'}
                className="mt-8 w-full"
              >
                Start with {plan.name}
              </Button>
            </article>
          ))}
        </div>
        {site.pricingNote && (
          <p className="mt-6 flex items-center gap-2 text-sm text-muted">
            <ShieldCheck aria-hidden className="size-4" /> {site.pricingNote}
          </p>
        )}
      </div>
    </section>
  )
}

/* ---------------- FAQ ---------------- */

/** The questions list from Site content; a broken value shows no questions rather than breaking the page. */
function parseFaqs(json) {
  try {
    const list = JSON.parse(json || '[]')
    return Array.isArray(list) ? list.filter((f) => f?.q) : []
  } catch {
    return []
  }
}

function Faq({ site }) {
  return (
    <section id="faq" className="scroll-mt-20 bg-concrete">
      <div className="mx-auto grid max-w-6xl gap-10 px-5 py-20 md:grid-cols-[1fr_2fr] md:py-24">
        <div>
          <h2 className="text-3xl font-bold tracking-tight text-navy sm:text-4xl">{site.faqTitle}</h2>
          <p className="mt-4 text-muted">
            Something else? <a href={`https://wa.me/${site.supportWhatsapp}`} className="inline-flex items-center gap-1 font-medium text-navy underline underline-offset-4"><MessageCircle className="size-4" />WhatsApp us</a>
          </p>
        </div>
        <div className="divide-y divide-line border-y border-line">
          {parseFaqs(site.faqs).map(({ q, a }) => (
            <details key={q} className="group py-5">
              <summary className="flex cursor-pointer list-none items-center justify-between gap-4 text-lg font-medium [&::-webkit-details-marker]:hidden">
                {q}
                <span aria-hidden className="text-2xl leading-none text-steel transition-transform group-open:rotate-45">+</span>
              </summary>
              <p className="mt-3 max-w-2xl leading-relaxed text-muted">{a}</p>
            </details>
          ))}
        </div>
      </div>
    </section>
  )
}

function ClosingCta({ site }) {
  return (
    <section className="bg-amber">
      <div className="mx-auto flex max-w-6xl flex-col items-start gap-6 px-5 py-14 md:flex-row md:items-center md:justify-between">
        <div className="flex items-start gap-4">
          <Package aria-hidden className="mt-1 size-8 shrink-0 text-navy-deep" />
          <div>
            <h2 className="text-2xl font-bold text-navy-deep sm:text-3xl">{site.closingTitle}</h2>
            <p className="mt-1 text-navy-deep/80">{site.closingText}</p>
          </div>
        </div>
        <Button to="/start" variant="navy" size="lg">Open your store</Button>
      </div>
    </section>
  )
}

export { Landing as Component }
