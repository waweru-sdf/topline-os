import { useEffect, useState } from 'react'
import { LoaderCircle } from 'lucide-react'

export function PageLoader() {
  // If the server was asleep (free hosting), the first load can take up to a minute: say so.
  const [slow, setSlow] = useState(false)
  useEffect(() => {
    const timer = setTimeout(() => setSlow(true), 4000)
    return () => clearTimeout(timer)
  }, [])

  return (
    <div className="flex min-h-dvh flex-col items-center justify-center gap-3 px-6 text-center" role="status">
      <LoaderCircle aria-hidden className="size-7 animate-spin text-navy" />
      {slow ? (
        <p className="max-w-xs text-sm text-muted">Starting up. This can take up to a minute the first time; please keep this page open.</p>
      ) : (
        <span className="sr-only">Loading</span>
      )}
    </div>
  )
}

/** Thin bar across the top while the router loads the next page. */
export function NavProgress({ active }) {
  return (
    <div aria-hidden className="pointer-events-none fixed inset-x-0 top-0 z-50 h-0.5 overflow-hidden">
      <div className={`h-full bg-amber transition-[width,opacity] duration-500 ${active ? 'w-4/5 opacity-100' : 'w-full opacity-0'}`} />
    </div>
  )
}
