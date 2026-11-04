import { Star } from 'lucide-react'
import { cn } from '@/lib/cn'

/** A 1–5 star rating, read-only. */
export function Stars({ rating, className }) {
  return (
    <span className={cn('inline-flex gap-0.5', className)} role="img" aria-label={`${rating} out of 5 stars`}>
      {[1, 2, 3, 4, 5].map((n) => <Star key={n} aria-hidden className={cn('size-4', n <= rating ? 'fill-amber text-amber' : 'text-line')} />)}
    </span>
  )
}
