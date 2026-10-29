import { useState } from 'react'
import { NavLink, Outlet, useNavigation } from 'react-router'
import { Menu, X } from 'lucide-react'
import { cn } from '@/lib/cn'
import { Logo } from '@/components/ui/Logo'
import { NavProgress } from '@/components/PageLoader'

/**
 * Shared shell for the vendor admin and the Topline admin: navy sidebar on
 * desktop, slide-down menu on phones (most shop owners will be on a phone).
 */
export function AppShell({ nav, top, footer, homeTo }) {
  const [open, setOpen] = useState(false)
  const navigation = useNavigation()

  const links = (
    <ul className="space-y-1">
      {nav.map(({ to, label, icon: Icon, end, badge }) => (
        <li key={to}>
          <NavLink
            to={to}
            end={end}
            onClick={() => setOpen(false)}
            className={({ isActive }) =>
              cn(
                'flex items-center gap-3 rounded-lg px-3 py-2.5 text-[0.95rem] transition-colors',
                isActive ? 'bg-white text-navy font-medium' : 'text-mist hover:bg-white/10 hover:text-white',
              )
            }
          >
            <Icon aria-hidden className="size-5" />
            <span className="flex-1">{label}</span>
            {badge ? <span className="rounded-full bg-amber px-2 text-xs font-bold text-navy-deep tabular">{badge}</span> : null}
          </NavLink>
        </li>
      ))}
    </ul>
  )

  return (
    <div className="min-h-dvh bg-concrete lg:grid lg:grid-cols-[16rem_1fr]">
      <NavProgress active={navigation.state === 'loading'} />

      <aside className="hidden bg-navy lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col">
        <div className="px-5 py-5"><Logo tone="light" to={homeTo} /></div>
        <nav aria-label="Admin" className="flex-1 overflow-y-auto px-3">{links}</nav>
        {footer && <div className="border-t border-white/10 p-3">{footer}</div>}
      </aside>

      <div className="min-w-0">
        <header className="sticky top-0 z-30 border-b border-line bg-white">
          <div className="flex h-16 items-center gap-3 px-4 sm:px-6">
            <button
              type="button"
              className="-ml-1 rounded-md p-2 lg:hidden"
              aria-expanded={open}
              aria-controls="app-nav"
              aria-label={open ? 'Close menu' : 'Open menu'}
              onClick={() => setOpen((o) => !o)}
            >
              {open ? <X className="size-6" /> : <Menu className="size-6" />}
            </button>
            {top}
          </div>
          {open && (
            <nav id="app-nav" aria-label="Admin" className="bg-navy px-3 py-3 lg:hidden">
              {links}
              {footer && <div className="mt-3 border-t border-white/10 pt-3">{footer}</div>}
            </nav>
          )}
        </header>
        <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

export function PageHeader({ title, children, actions }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-navy sm:text-3xl">{title}</h1>
        {children && <p className="mt-1 text-muted">{children}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  )
}
