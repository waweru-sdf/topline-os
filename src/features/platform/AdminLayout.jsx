import { useLoaderData } from 'react-router'
import { Activity, CreditCard, Image, LayoutDashboard, LogOut, Store } from 'lucide-react'
import { api } from '@/services/api'
import { clearSession } from '@/services/session'
import { AppShell } from '@/components/layout/AppShell'
import { requirePlatformAdmin } from '@/features/vendor/guard'

export async function loader() {
  const user = requirePlatformAdmin()
  const incidents = await api.listIncidents('open').catch(() => [])
  return { user, openIncidents: incidents.length }
}

function AdminLayout() {
  const { user, openIncidents } = useLoaderData()
  const nav = [
    { to: '/admin', label: 'Overview', icon: LayoutDashboard, end: true },
    { to: '/admin/stores', label: 'Stores', icon: Store },
    { to: '/admin/payments', label: 'Payments', icon: CreditCard },
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
        <button
          type="button"
          onClick={() => { clearSession(); window.location.assign('/login') }}
          className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-mist hover:bg-white/10 hover:text-white"
        >
          <LogOut className="size-5" /> Log out
        </button>
      }
    />
  )
}

export { AdminLayout as Component }
