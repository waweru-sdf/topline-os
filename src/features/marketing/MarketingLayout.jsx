import { useState } from 'react'
import { Link, Outlet, useLoaderData, useNavigation } from 'react-router'
import { api } from '@/services/api'
import { Menu, X } from 'lucide-react'
import { Logo } from '@/components/ui/Logo'
import { Button } from '@/components/ui/Button'
import { NavProgress } from '@/components/PageLoader'

const NAV = [
  { href: '#features', label: 'What you get' },
  { href: '#how', label: 'How to start' },
  { href: '#pricing', label: 'Pricing' },
  { href: '#faq', label: 'Questions' },
]

export async function loader() {
  return { site: await api.getSite({ view: 'landing' }) }
}

function MarketingLayout() {
  const { site } = useLoaderData()
  const [open, setOpen] = useState(false)
  const navigation = useNavigation()

  return (
    <>
      <NavProgress active={navigation.state === 'loading'} />
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">
        Skip to content
      </a>
      {site.announcement && (
        <p className="bg-amber px-5 py-2 text-center text-sm font-medium text-navy-deep">{site.announcement}</p>
      )}
      <header className="sticky top-0 z-40 border-b border-line/70 bg-white/95 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-6 px-5">
          <Logo />
          <nav aria-label="Main" className="hidden items-center gap-7 md:flex">
            {NAV.map((n) => (
              <a key={n.href} href={n.href} className="text-[0.95rem] text-ink/80 hover:text-navy">
                {n.label}
              </a>
            ))}
          </nav>
          <div className="hidden items-center gap-2 md:flex">
            <Button to="/login" variant="ghost">Log in</Button>
            <Button to="/start">Open your store</Button>
          </div>
          <button
            type="button"
            className="-mr-2 rounded-md p-2 md:hidden"
            aria-expanded={open}
            aria-controls="mobile-nav"
            aria-label={open ? 'Close menu' : 'Open menu'}
            onClick={() => setOpen((o) => !o)}
          >
            {open ? <X className="size-6" /> : <Menu className="size-6" />}
          </button>
        </div>
        {open && (
          <nav id="mobile-nav" aria-label="Main" className="border-t border-line px-5 py-4 md:hidden">
            <ul className="flex flex-col gap-1">
              {NAV.map((n) => (
                <li key={n.href}>
                  <a href={n.href} onClick={() => setOpen(false)} className="block rounded-md px-2 py-2.5 hover:bg-concrete">
                    {n.label}
                  </a>
                </li>
              ))}
            </ul>
            <div className="mt-3 grid grid-cols-2 gap-2">
              <Button to="/login" variant="outline">Log in</Button>
              <Button to="/start">Open your store</Button>
            </div>
          </nav>
        )}
      </header>
      <main id="main">
        <Outlet />
      </main>
      <Footer site={site} />
    </>
  )
}

function Footer({ site }) {
  return (
    <footer className="bg-navy-deep text-mist">
      <div className="mx-auto grid max-w-6xl gap-10 px-5 py-14 md:grid-cols-[1.4fr_1fr_1fr]">
        <div>
          <Logo tone="light" />
          <p className="mt-4 max-w-xs text-sm leading-relaxed text-mist/80">
            Shop software and an online store for hardware businesses in Kenya. Paid by M-PESA.
          </p>
        </div>
        <div>
          <h2 className="text-sm font-bold text-white">Topline</h2>
          <ul className="mt-3 space-y-2 text-sm">
            <li><a href="#features" className="hover:text-amber">What you get</a></li>
            <li><a href="#pricing" className="hover:text-amber">Pricing</a></li>
            <li><Link to="/s/mjengo" className="hover:text-amber">See a demo store</Link></li>
            <li><Link to="/login" className="hover:text-amber">Log in</Link></li>
          </ul>
        </div>
        <div>
          <h2 className="text-sm font-bold text-white">Talk to us</h2>
          <ul className="mt-3 space-y-2 text-sm">
            <li><a href={`https://wa.me/${site.supportWhatsapp}`} className="hover:text-amber">WhatsApp {site.supportPhone}</a></li>
            <li><a href={`mailto:${site.supportEmail}`} className="hover:text-amber">{site.supportEmail}</a></li>
            <li>Nairobi, Kenya</li>
          </ul>
        </div>
      </div>
      <div className="border-t border-white/10">
        <div className="mx-auto flex max-w-6xl flex-wrap justify-between gap-2 px-5 py-5 text-xs text-mist/60">
          <p>© {new Date().getFullYear()} Topline Marketplace. All rights reserved.</p>
          <p>Photos from <a href="https://unsplash.com" className="underline hover:text-amber">Unsplash</a></p>
        </div>
      </div>
    </footer>
  )
}

export { MarketingLayout as Component }
