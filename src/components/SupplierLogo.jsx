import { Img } from '@/components/ui/Img'
import { cn } from '@/lib/cn'

/** A supplier's logo, or its first letter on navy when there's no logo yet. */
export function SupplierLogo({ supplier, className = 'size-14' }) {
  return supplier.logo ? (
    <Img name={supplier.logo} alt="" w={160} className={cn('shrink-0 rounded-xl bg-white object-contain ring-1 ring-line', className)} />
  ) : (
    <span aria-hidden className={cn('flex shrink-0 items-center justify-center rounded-xl bg-navy text-xl font-bold text-white', className)}>
      {supplier.name?.[0] ?? '?'}
    </span>
  )
}
