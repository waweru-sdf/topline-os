import { useEffect } from 'react'
import { isRouteErrorResponse, useRouteError } from 'react-router'
import { api } from '@/services/api'
import { Button } from './ui/Button'
import { Logo } from './ui/Logo'

export function RouteError({ notFound = false }) {
  const error = useRouteError()
  const status = notFound ? 404 : isRouteErrorResponse(error) ? error.status : error?.status
  const is404 = status === 404

  const title = is404 ? 'This page isn’t here' : 'Something broke on our side'
  const message = is404
    ? error?.message && !isRouteErrorResponse(error)
      ? error.message
      : 'The link may be mistyped, or the store may have moved.'
    : 'Reload the page. If it keeps happening, WhatsApp Topline support on 0700 000 000.'

  useEffect(() => {
    if (error && !is404 && !(error.status >= 400 && error.status < 500)) {
      if (import.meta.env.DEV) console.error(error)
      else api.reportError({ message: error.message ?? String(error), stack: error.stack, path: window.location.pathname })
    }
  }, [error, is404])

  return (
    <main className="flex min-h-dvh flex-col items-start justify-center gap-6 bg-concrete px-6 sm:px-16">
      <Logo />
      <div>
        <p className="text-sm font-medium text-muted">Error {status ?? 500}</p>
        <h1 className="mt-1 text-3xl font-bold text-navy">{title}</h1>
        <p className="mt-2 max-w-md text-muted">{message}</p>
      </div>
      <div className="flex gap-3">
        <Button to="/" variant="navy">Go to Topline home</Button>
        {!is404 && <Button variant="outline" onClick={() => window.location.reload()}>Reload</Button>}
      </div>
    </main>
  )
}
