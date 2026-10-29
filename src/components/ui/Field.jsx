import { useId } from 'react'
import { cn } from '@/lib/cn'

const control =
  'w-full rounded-lg border border-line bg-white px-3.5 text-ink placeholder:text-muted/70 transition-colors hover:border-steel focus:border-navy focus:outline-none focus:ring-3 focus:ring-navy/15 aria-invalid:border-bad aria-invalid:ring-bad/15 disabled:bg-concrete'

/** Label + control + hint/error, wired up for screen readers. */
export function Field({ label, hint, error, as = 'input', className, children, prefix, ...props }) {
  const id = useId()
  const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined
  const Control = as
  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      {label && (
        <label htmlFor={id} className="text-sm font-medium text-ink">
          {label}
        </label>
      )}
      <div className={cn(prefix && 'flex items-stretch')}>
        {prefix && (
          <span className="flex items-center rounded-l-lg border border-r-0 border-line bg-concrete px-3 text-sm text-muted">
            {prefix}
          </span>
        )}
        <Control
          id={id}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          className={cn(control, as === 'textarea' ? 'min-h-24 py-2.5' : 'h-11', prefix && 'rounded-l-none')}
          {...props}
        >
          {children}
        </Control>
      </div>
      {error ? (
        <p id={`${id}-error`} className="text-sm text-bad">{error}</p>
      ) : hint ? (
        <p id={`${id}-hint`} className="text-sm text-muted">{hint}</p>
      ) : null}
    </div>
  )
}
