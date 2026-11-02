import { useLoaderData } from 'react-router'
import { Activity, Calculator, CreditCard, Factory, HandCoins, History, Image, KeyRound, LayoutDashboard, LogOut, Store } from 'lucide-react'
import { api } from '@/services/api'
import { clearSession } from '@/services/session'
import { AppShell } from '@/components/layout/AppShell'
import { requirePlatformAdmin } from '@/features/vendor/guard'

export async function loader() {
  const user = requirePlatformAdmin()
  // Counts for the menu badges; a failing count never blocks the admin.
  const [incidents, suppliers, credit] = await Promise.all([
    api.listIncidents('open').catch(() => []),
    api.adminSuppliers().catch(() => ({ pendingReviews: 0 })),
    api.adminCreditRequests().catch(() => ({ open: 0 })),
  ])
  return { user, openIncidents: incidents.length, pendingReviews: suppliers.pendingReviews, openCredit: credit.open }
}

function AdminLayout() {
  const { user, openIncidents, pendingReviews, openCredit } = useLoaderData()
  const nav = [
    { to: '/admin', label: 'Overview', icon: LayoutDashboard, end: true },
    { to: '/admin/stores', label: 'Stores', icon: Store },
    { to: '/admin/activity', label: 'Activity', icon: History },
    { to: '/admin/payments', label: 'Payments', icon: CreditCard },
    { to: '/admin/suppliers', label: 'Suppliers', icon: Factory, badge: pendingReviews || null },
    { to: '/admin/credit', label: 'Credit requests', icon: HandCoins, badge: openCredit || null },
    { to: '/admin/accounting', label: 'Accounting', icon: Calculator },
    { to: '/admin/site', label: 'Site content', icon: Image },
    { to: '/admin/health', label: 'System health', icon: Activity, badge: openIncidents || null },
  ]
  return (
    <AppShell
      nav={nav}
      homeTo="/admin"
      top={
        <div className="flex flex-1 items-center justify-between gap-3">
          <p className="font-bold">Topline admin</p>
          <p className="hidden text-sm text-muted sm:block">{user.name}</p>
        </div>
      }
      footer={
        <>
        <a href="/change-password" className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-mist hover:bg-white/10 hover:text-white">
          <KeyRound className="size-5" /> Change password
        </a>
        <button
          type="button"
          onClick={() => { clearSession(); window.location.assign('/login') }}
          className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-mist hover:bg-white/10 hover:text-white"
        >
          <LogOut className="size-5" /> Log out
        </button>
        </>
      }
    />
  )
}

export { AdminLayout as Component }
