import { cn } from '@/lib/cn'

const tones = {
  neutral: 'bg-concrete text-muted',
  navy: 'bg-mist text-navy',
  amber: 'bg-amber-tint text-[#8a5a00]',
  good: 'bg-good-tint text-good',
  bad: 'bg-bad-tint text-bad',
}

export function Badge({ tone = 'neutral', className, children }) {
  return (
    <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap', tones[tone], className)}>
      {children}
    </span>
  )
}

const ORDER_STATUS = {
  awaiting_confirmation: ['Check payment', 'amber'],
  new: ['New', 'amber'],
  confirmed: ['Confirmed', 'navy'],
  dispatched: ['On the way', 'navy'],
  completed: ['Completed', 'good'],
  cancelled: ['Cancelled', 'bad'],
}
export function OrderStatus({ status }) {
  const [label, tone] = ORDER_STATUS[status] ?? [status, 'neutral']
  return <Badge tone={tone}>{label}</Badge>
}

const STORE_STATUS = {
  active: ['Active', 'good'],
  past_due: ['Payment due', 'amber'],
  suspended: ['Suspended', 'bad'],
  draft: ['Not paid yet', 'neutral'],
}
export function StoreStatus({ status }) {
  const [label, tone] = STORE_STATUS[status] ?? [status, 'neutral']
  return <Badge tone={tone}>{label}</Badge>
}
