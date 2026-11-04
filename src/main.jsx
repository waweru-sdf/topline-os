import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router/dom'
import { api } from './services/api'
import { isOtherDomain, setOwnDomainSlug } from './lib/tenant'
import './styles/index.css'

// Crashes in customers' browsers show up in Topline admin > System health.
const report = (message, stack) => api.reportError({ message: String(message).slice(0, 200), stack: String(stack ?? '').slice(0, 3000), path: location.pathname })
if (import.meta.env.PROD) {
  window.addEventListener('error', (e) => report(e.message, e.error?.stack))
  window.addEventListener('unhandledrejection', (e) => report(e.reason?.message ?? e.reason, e.reason?.stack))
}

async function start() {
  // On a shop's own domain the shop *is* the site: find out which shop before building the routes.
  if (isOtherDomain()) {
    const found = await api.resolveDomain(location.hostname).catch(() => null)
    if (found?.slug) setOwnDomainSlug(found.slug)
  }
  const { router } = await import('./router')
  createRoot(document.getElementById('root')).render(
    <StrictMode>
      <RouterProvider router={router} />
    </StrictMode>,
  )
}
start()
