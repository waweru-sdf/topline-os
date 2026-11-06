import { useState } from 'react'
import { useNavigate, useRevalidator, useRouteLoaderData } from 'react-router'
import { ArrowLeft, BookOpenText, Calculator, Check, Copy, CreditCard, ExternalLink, Factory, FileText, HandCoins, History, KeyRound, LayoutDashboard, LogOut, MapPin, Package, Receipt, Settings, ShoppingBag, UserCog, Users, Wallet } from 'lucide-react'
import { api } from '@/services/api'
import { clearSession, getBranchId, getUser, setActingStore, setBranchId } from '@/services/session'
import { shopLink } from '@/lib/tenant'
import { AppShell } from '@/components/layout/AppShell'
import { StoreStatus } from '@/components/ui/Badge'
import { requireStoreId } from './guard'

export async function loader() {
  const storeId = requireStoreId()
  const store = await api.getVendorStore(storeId)
  // A remembered branch that no longer exists falls back to the main branch.
  if (getBranchId(storeId) && !store.branches.some((b) => b.id === getBranchId(storeId))) setBranchId(storeId, '')
  return { user: getUser(), store }
}

/** { user, store, branch } for any page under /dashboard. branch: where this person is working now. */
export const useVendor = () => {
  const data = useRouteLoaderData('vendor')
  return { ...data, branch: workingBranch(data.store) }
}

/** Staff tied to a branch always work there; others work at the branch picked in the top bar (default: main). */
export function workingBranch(store) {
  const id = store.myBranchId || getBranchId(store.id)
  return store.branches.find((b) => b.id === id) ?? store.branches[0]
}

/** For loaders, which can't use hooks: the branch id to send ('' = main). */
export const branchParam = (storeId) => getBranchId(storeId)

// `perm`: shown only to roles that may use it (the backend checks the same permission).
const NAV = [
  { to: '/dashboard', label: 'Overview', icon: LayoutDashboard, end: true, perm: 'view_dashboard' },
  { to: '/dashboard/sell', label: 'Sell at the counter', icon: Receipt, perm: 'sell' },
  { to: '/dashboard/orders', label: 'Online orders', icon: ShoppingBag, perm: 'manage_orders' },
  { to: '/dashboard/quotations', label: 'Quotations', icon: FileText, perm: 'manage_orders' },
  { to: '/dashboard/products', label: 'Products & stock', icon: Package, perm: 'view_products' },
  { to: '/dashboard/customers', label: 'Customers & credit', icon: Users, perm: 'view_customers' },
  { to: '/dashboard/money', label: 'Money received', icon: Wallet, perm: 'manage_settings', payouts: true },
  { to: '/dashboard/suppliers', label: 'Suppliers', icon: Factory, perm: 'buy_stock' },
  { to: '/dashboard/supplier-balances', label: 'Supplier balances', icon: BookOpenText, perm: 'buy_stock' },
  { to: '/dashboard/accounting', label: 'Accounting', icon: Calculator, perm: 'manage_accounts' },
  { to: '/dashboard/credit', label: 'Stock credit', icon: HandCoins, perm: 'manage_billing' },
  { to: '/dashboard/activity', label: 'Activity', icon: History, perm: 'manage_settings' },
  { to: '/dashboard/team', label: 'Staff & branches', icon: UserCog, perm: 'manage_staff' },
  { to: '/dashboard/settings', label: 'Store settings', icon: Settings, perm: 'manage_settings' },
  { to: '/dashboard/billing', label: 'Plan & billing', icon: CreditCard, perm: 'manage_billing' },
]

function VendorLayout() {
  const { user, store } = useVendor()
  // "Money received" lists Topline's payouts to the shop, which only happen when Topline collects order money.
  const nav = NAV.filter((item) => store.permissions?.includes(item.perm) && (!item.payouts || store.payments?.modes?.includes('prompt')))

  return (
    <AppShell
      nav={nav}
      homeTo={store.permissions?.includes('view_dashboard') ? '/dashboard' : '/dashboard/sell'}
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
  const link = shopLink(store)

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
        <p className="hidden truncate text-xs text-muted sm:block">{link.replace(/^https:\/\//, '')}</p>
      </div>
      <span className="hidden sm:contents"><StoreStatus status={store.status} /></span>
      <BranchPicker store={store} />
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

/** Shops with several branches: which branch this browser is selling and counting stock at. */
function BranchPicker({ store }) {
  const revalidator = useRevalidator()
  if (store.branches.length < 2) return null
  const current = workingBranch(store)
  if (store.myBranchId) {
    return <span className="hidden items-center gap-1 rounded-full bg-concrete px-2.5 py-1 text-xs font-medium sm:flex"><MapPin className="size-3.5" />{current.name}</span>
  }
  return (
    <label className="flex shrink-0 items-center gap-1.5 text-sm">
      <MapPin aria-hidden className="size-4 shrink-0 text-muted" />
      <span className="sr-only">Branch you’re working at</span>
      <select value={current.id} onChange={(e) => { setBranchId(store.id, e.target.value); revalidator.revalidate() }}
        className="h-9 w-28 shrink-0 truncate rounded-lg border border-line bg-white px-2 text-sm sm:w-auto sm:max-w-[12rem]">
        {store.branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
      </select>
    </label>
  )
}

export { VendorLayout as Component }
