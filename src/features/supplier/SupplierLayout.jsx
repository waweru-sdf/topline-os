import { useRouteLoaderData } from 'react-router'
import { ClipboardList, KeyRound, LogOut, Store } from 'lucide-react'
import { api } from '@/services/api'
import { clearSession, getUser } from '@/services/session'
import { AppShell } from '@/components/layout/AppShell'
import { Badge } from '@/components/ui/Badge'
import { requireSupplier } from '@/features/vendor/guard'

export async function loader() {
  requireSupplier()
  return { user: getUser(), me: await api.supplierMe() }
}

/** { user, me } for any page under /supplier. */
export const useSupplier = () => useRouteLoaderData('supplier')

const STATUS = {
  approved: ['Listed on Topline', 'good'],
  hidden: ['Hidden by Topline', 'bad'],
}

function SupplierLayout() {
  const { user, me } = useSupplier()
  const [label, tone] = STATUS[me.status] ?? [me.status, 'neutral']
  const nav = [
    { to: '/supplier', label: 'Orders', icon: ClipboardList, end: true, badge: me.newOrders || null },
    { to: '/supplier/profile', label: 'Profile & products', icon: Store },
  ]
  return (
    <AppShell
      nav={nav}
      homeTo="/supplier"
      top={
        <div className="flex min-w-0 flex-1 items-center gap-3">
          <p className="truncate font-bold">{me.profile.name}</p>
          <Badge tone={tone}>{label}</Badge>
          <p className="ml-auto hidden text-sm text-muted sm:block">Supplier account</p>
        </div>
      }
      footer={
        <div className="flex items-center gap-3 px-2">
          <span className="flex size-9 items-center justify-center rounded-full bg-white/15 text-sm font-bold text-white">{user.name[0]}</span>
          <span className="min-w-0 flex-1 truncate text-sm text-white">{user.name}</span>
          <a href="/change-password" className="rounded-md p-2 text-mist hover:bg-white/10 hover:text-white" aria-label="Change password" title="Change password">
            <KeyRound className="size-4" />
          </a>
          <button type="button" onClick={() => { clearSession(); window.location.assign('/login') }} className="rounded-md p-2 text-mist hover:bg-white/10 hover:text-white" aria-label="Log out">
            <LogOut className="size-4" />
          </button>
        </div>
      }
    />
  )
}

export { SupplierLayout as Component }
