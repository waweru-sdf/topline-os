import { Link } from 'react-router'
import { LoaderCircle } from 'lucide-react'
import { cn } from '@/lib/cn'

const variants = {
  primary: 'bg-amber text-navy-deep hover:bg-[#ffb73d] active:bg-amber-deep',
  navy: 'bg-navy text-white hover:bg-navy-soft active:bg-navy-deep',
  outline: 'border border-line bg-white text-ink hover:border-steel hover:bg-concrete',
  ghostLight: 'border border-white/35 text-white hover:bg-white/10',
  ghost: 'text-ink hover:bg-concrete',
  danger: 'bg-bad text-white hover:bg-[#a83524]',
}
const sizes = {
  sm: 'h-9 px-3 text-sm gap-1.5',
  md: 'h-11 px-5 text-[0.95rem] gap-2',
  lg: 'h-13 px-7 text-base gap-2.5',
}

/**
 * One button for actions and navigation. Pass `to` for an in-app link,
 * `href` for an external one, otherwise it renders a <button>.
 */
export function Button({ variant = 'primary', size = 'md', to, href, pending, disabled, className, children, ...props }) {
  const classes = cn(
    'inline-flex shrink-0 items-center justify-center rounded-lg font-medium whitespace-nowrap transition-colors disabled:pointer-events-none disabled:opacity-55',
    variants[variant],
    sizes[size],
    className,
  )
  const content = (
    <>
      {pending && <LoaderCircle aria-hidden className="size-4 animate-spin" />}
      {children}
    </>
  )
  if (to) return <Link to={to} className={classes} {...props}>{content}</Link>
  if (href) return <a href={href} className={classes} {...props}>{content}</a>
  return (
    <button type="button" className={classes} {...props} disabled={pending || disabled} aria-busy={pending || undefined}>
      {content}
    </button>
  )
}
