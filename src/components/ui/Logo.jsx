import { Link } from 'react-router'
import { cn } from '@/lib/cn'

/**
 * Topline wordmark. Placeholder drawn in code until the official SVG logo is
 * supplied — swap the contents of this component for the real file.
 */
export function Logo({ tone = 'dark', className, to = '/' }) {
  const top = tone === 'dark' ? 'text-navy' : 'text-white'
  return (
    <Link to={to} className={cn('inline-flex items-center gap-2', className)} aria-label="Topline home">
      <svg viewBox="0 0 64 64" className="size-8" aria-hidden>
        <rect width="64" height="64" rx="14" fill={tone === 'dark' ? '#0B3A6E' : '#ffffff'} />
        <path d="M12 16h26l-2 8h-8l-6 24h-9l6-24h-9z" fill={tone === 'dark' ? '#fff' : '#0B3A6E'} />
        <path d="M36 28h9l-4 14h11l-2 6H30z" fill="#F5A623" />
      </svg>
      <span className="text-xl font-bold tracking-tight italic">
        <span className={top}>TOP</span>
        <span className="text-amber">LINE</span>
      </span>
    </Link>
  )
}
