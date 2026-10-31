import { useState } from 'react'
import { useNavigate, useRouteLoaderData } from 'react-router'
import { ArrowLeft, Check, Copy, CreditCard, ExternalLink, History, KeyRound, LayoutDashboard, LogOut, Package, Receipt, Settings, ShoppingBag, Users, Wallet } from 'lucide-react'
import { api } from '@/services/api'
import { clearSession, getUser, setActingStore } from '@/services/session'
import { storeHost, storeUrl } from '@/lib/tenant'
import { AppShell } from '@/components/layout/AppShell'
import { StoreStatus } from '@/components/ui/Badge'
import { requireStoreId } from './guard'

export async function loader() {
  const storeId = requireStoreId()
  const store = await api.getVendorStore(storeId)
  return { user: getUser(), store }
}

/** { user, store } for any page under /dashboard. */
export const useVendor = () => useRouteLoaderData('vendor')

const NAV = [
  { to: '/dashboard', label: 'Overview', icon: LayoutDashboard, end: true },
  { to: '/dashboard/sell', label: 'Sell at the counter', icon: Receipt },
  { to: '/dashboard/orders', label: 'Online orders', icon: ShoppingBag },
  { to: '/dashboard/products', label: 'Products & stock', icon: Package },
  { to: '/dashboard/customers', label: 'Customers & credit', icon: Users },
  { to: '/dashboard/money', label: 'Money received', icon: Wallet },
  { to: '/dashboard/activity', label: 'Activity', icon: History, ownerOnly: true },
  { to: '/dashboard/settings', label: 'Store settings', icon: Settings },
  { to: '/dashboard/billing', label: 'Plan & billing', icon: CreditCard },
]

function VendorLayout() {
  const { user, store } = useVendor()
  // Cashiers don't see the shop's activity (it shows sales totals and settings changes).
  const nav = NAV.filter((item) => !item.ownerOnly || user.role === 'platform_admin' || user.storeRole !== 'cashier')

  return (
    <AppShell
      nav={nav}
      homeTo="/dashboard"
      top={<StoreBar store={store} asAdmin={user.role === 'platform_admin'} />}
      footer={
        <div className="flex items-center gap-3 px-2">
          <span className="flex size-9 items-center justify-center rounded-full bg-white/15 text-sm font-bold text-white">{user.name[0]}</span>
          <span className="min-w-0 flex-1 truncate text-sm text-white">{user.name}</span>
          <a href="/change-password" className="rounded-md p-2 text-mist hover:bg-white/10 hover:text-white" aria-label="Change password" title="Change password">
            <KeyRound className="size-4" />
          </a>
          <button
            type="button"
            onClick={() => { clearSession(); window.location.assign('/login') }}
            className="rounded-md p-2 text-mist hover:bg-white/10 hover:text-white"
            aria-label="Log out"
          >
            <LogOut className="size-4" />
          </button>
        </div>
      }
    />
  )
}

function StoreBar({ store, asAdmin }) {
  const navigate = useNavigate()
  const [copied, setCopied] = useState(false)
  const link = storeUrl(store.slug)

  async function copy() {
    try {
      await navigator.clipboard.writeText(link)
      setCopied(true)
      setTimeout(() => setCopied(false), 1500)
    } catch {
      /* clipboard blocked: the link is visible to copy by hand */
    }
  }

  return (
    <div className="flex min-w-0 flex-1 items-center gap-3">
      {asAdmin && (
        <button
          type="button"
          onClick={() => { setActingStore(null); navigate('/admin') }}
          className="flex shrink-0 items-center gap-1.5 rounded-lg bg-amber-tint px-3 py-2 text-sm font-medium text-[#7a4f00] hover:bg-amber/30"
        >
          <ArrowLeft className="size-4" /> Topline admin
        </button>
      )}
      <div className="min-w-0">
        <p className="truncate font-bold">{store.name}</p>
        <p className="hidden truncate text-xs text-muted sm:block">{storeHost(store.slug)}</p>
      </div>
      <StoreStatus status={store.status} />
      <div className="ml-auto flex items-center gap-1">
        <button type="button" onClick={copy} className="flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm text-muted hover:bg-concrete hover:text-ink">
          {copied ? <Check className="size-4 text-good" /> : <Copy className="size-4" />}
          <span className="hidden md:inline">{copied ? 'Copied' : 'Copy store link'}</span>
        </button>
        <a href={`/s/${store.slug}`} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm text-muted hover:bg-concrete hover:text-ink">
          <ExternalLink className="size-4" />
          <span className="hidden md:inline">View store</span>
        </a>
      </div>
    </div>
  )
}

export { VendorLayout as Component }
