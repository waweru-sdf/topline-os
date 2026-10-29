import { Link, NavLink, Outlet, data, useNavigation } from 'react-router'
import { Clock, MapPin, MessageCircle, Phone, ShoppingCart, Store } from 'lucide-react'
import { api } from '@/services/api'
import { getTenantSlugFromHost, storeHost } from '@/lib/tenant'
import { CartProvider, useCart } from '@/context/CartContext'
import { NavProgress } from '@/components/PageLoader'
import { Logo } from '@/components/ui/Logo'
import { Img } from '@/components/ui/Img'
import { useStore } from './useStore'

export async function loader({ params }) {
  const slug = params.slug ?? getTenantSlugFromHost()
  try {
    return await api.getStorefront(slug)
  } catch (e) {
    throw data({ message: e.message }, { status: e.status ?? 500 })
  }
}

// Store data rarely changes while browsing; don't refetch on every in-store click.
export function shouldRevalidate({ currentParams, nextParams }) {
  return currentParams.slug !== nextParams.slug
}

function StoreLayout() {
  const { store } = useStore()
  const navigation = useNavigation()

  if (store.status === 'suspended' || store.status === 'draft') return <ClosedStore store={store} />

  return (
    // Re-theme this subtree with the vendor's accent colour: Tailwind v4
    // utilities read --color-amber, so overriding the variable is enough.
    <div style={{ '--color-amber': store.accent }} className="flex min-h-dvh flex-col bg-concrete">
      <title>{`${store.name} — ${store.town}`}</title>
      <meta name="description" content={store.tagline || `Building materials and hardware in ${store.town}.`} />
      <NavProgress active={navigation.state === 'loading'} />
      <CartProvider key={store.slug} slug={store.slug}>
        <StoreHeader />
        <main className="flex-1">
          <Outlet />
        </main>
        <StoreFooter />
      </CartProvider>
    </div>
  )
}

function StoreHeader() {
  const { store, base } = useStore()
  const { count } = useCart()
  return (
    <header className="sticky top-0 z-30 bg-navy text-white">
      <div className="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4 sm:px-5">
        <Link to={base || '/'} className="flex min-w-0 items-center gap-3">
          {store.logo ? (
            <Img name={store.logo} alt="" w={96} className="size-9 shrink-0 rounded-lg bg-white" />
          ) : (
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-amber text-lg font-bold text-navy-deep">
              {store.name[0]}
            </span>
          )}
          <span className="min-w-0">
            <span className="block truncate font-bold leading-tight">{store.name}</span>
            <span className="block truncate text-xs text-mist">{store.town}, {store.county}</span>
          </span>
        </Link>
        <div className="ml-auto flex items-center gap-1 sm:gap-2">
          {store.whatsapp && (
            <a
              href={`https://wa.me/${store.whatsapp}`}
              className="hidden items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-white/10 sm:flex"
            >
              <MessageCircle className="size-4" /> WhatsApp
            </a>
          )}
          <NavLink
            to={`${base}/cart`}
            className="relative flex items-center gap-2 rounded-lg bg-white/10 px-3 py-2 text-sm font-medium hover:bg-white/15 aria-[current=page]:bg-amber aria-[current=page]:text-navy-deep"
          >
            <ShoppingCart className="size-5" />
            <span className="hidden sm:inline">Cart</span>
            {count > 0 && (
              <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-amber px-1.5 text-xs font-bold text-navy-deep tabular">
                {count}
              </span>
            )}
            <span className="sr-only">, {count} items</span>
          </NavLink>
        </div>
      </div>
    </header>
  )
}

function StoreFooter() {
  const { store } = useStore()
  return (
    <footer className="mt-16 border-t border-line bg-white">
      <div className="mx-auto grid max-w-6xl gap-8 px-5 py-10 text-sm sm:grid-cols-3">
        <div>
          <p className="font-bold text-ink">{store.name}</p>
          {store.tagline && <p className="mt-1 text-muted">{store.tagline}</p>}
        </div>
        <ul className="space-y-2 text-muted">
          <li className="flex gap-2"><MapPin className="size-4 shrink-0 text-steel" />{[store.address, store.town, store.county].filter(Boolean).join(', ')}</li>
          {store.hours && <li className="flex gap-2"><Clock className="size-4 shrink-0 text-steel" />{store.hours}</li>}
          {store.phone && <li className="flex gap-2"><Phone className="size-4 shrink-0 text-steel" /><a href={`tel:${store.phone.replace(/\s/g, '')}`} className="hover:text-ink">{store.phone}</a></li>}
        </ul>
        <div className="sm:text-right">
          <p className="text-muted">Online store by</p>
          <Logo className="mt-1" to="/" />
          <p className="mt-2 text-xs text-muted">{storeHost(store.slug)}</p>
        </div>
      </div>
    </footer>
  )
}

function ClosedStore({ store }) {
  return (
    <main className="flex min-h-dvh flex-col items-center justify-center bg-concrete px-6 text-center">
      <title>{`${store.name} — temporarily closed`}</title>
      <Store className="size-10 text-steel" aria-hidden />
      <h1 className="mt-4 text-2xl font-bold text-navy">{store.name} is closed online for now</h1>
      <p className="mt-2 max-w-sm text-muted">
        You can still reach the shop directly{store.owner?.phone ? ` on ${store.owner.phone}` : ''}.
      </p>
      <Logo className="mt-10" />
    </main>
  )
}

export { StoreLayout as Component }
