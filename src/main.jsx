import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router/dom'
import { router } from './router'
import { api } from './services/api'
import './styles/index.css'

// Crashes in customers' browsers show up in Topline admin > System health.
const report = (message, stack) => api.reportError({ message: String(message).slice(0, 200), stack: String(stack ?? '').slice(0, 3000), path: location.pathname })
if (import.meta.env.PROD) {
  window.addEventListener('error', (e) => report(e.message, e.error?.stack))
  window.addEventListener('unhandledrejection', (e) => report(e.reason?.message ?? e.reason, e.reason?.stack))
}

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <RouterProvider router={router} />
  </StrictMode>,
)
