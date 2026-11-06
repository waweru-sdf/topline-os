import { Check, Circle } from 'lucide-react'
import { passwordChecks } from '@/lib/password'
import { cn } from '@/lib/cn'
import { PasswordField } from './PasswordField'

/** A password the person is choosing, with the rules ticked off as they type. */
export function NewPasswordField({ value, phone, className, ...props }) {
  return (
    <div className={className}>
      <PasswordField value={value} autoComplete="new-password" {...props} />
      <ul className="mt-2 space-y-1 text-sm" aria-label="Password rules">
        {passwordChecks(value, phone).map((c) => (
          <li key={c.label} className={cn('flex items-start gap-1.5', c.ok ? 'text-good' : 'text-muted')}>
            {c.ok ? <Check aria-hidden className="mt-0.5 size-4 shrink-0" /> : <Circle aria-hidden className="mt-1 size-3 shrink-0" />}
            <span>{c.label}<span className="sr-only">{c.ok ? ': done' : ': not yet'}</span></span>
          </li>
        ))}
      </ul>
    </div>
  )
}
