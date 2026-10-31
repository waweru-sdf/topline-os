import { formatCompact, formatKES } from '@/lib/format'

const DAY = new Intl.DateTimeFormat('en-KE', { weekday: 'short' })
const DATE = new Intl.DateTimeFormat('en-KE', { day: 'numeric', month: 'short' })

/**
 * Daily sales as bars, profit as a darker inset. Plain SVG so the admin bundle
 * stays small on shop phones. The last 7 days are drawn solid, the 7 before
 * them faded, so "this week vs last week" reads without a legend.
 */
export function SalesChart({ days }) {
  const W = 720
  const H = 220
  const pad = { t: 12, r: 8, b: 28, l: 44 }
  const max = Math.max(1, ...days.map((d) => d.revenue))
  const step = niceStep(max)
  const top = Math.ceil(max / step) * step
  const ticks = Array.from({ length: Math.round(top / step) + 1 }, (_, i) => i * step)
  const innerW = W - pad.l - pad.r
  const innerH = H - pad.t - pad.b
  const bw = innerW / days.length
  const y = (v) => pad.t + innerH - (v / top) * innerH

  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label="Daily sales for the last 14 days">
      {ticks.map((t) => (
        <g key={t}>
          <line x1={pad.l} x2={W - pad.r} y1={y(t)} y2={y(t)} stroke="var(--color-line)" />
          <text x={pad.l - 8} y={y(t) + 4} textAnchor="end" fontSize="11" fill="var(--color-muted)">{formatCompact(t)}</text>
        </g>
      ))}
      {days.map((d, i) => {
        const x = pad.l + i * bw + bw * 0.18
        const w = bw * 0.64
        const recent = i >= days.length - 7
        const date = new Date(d.date)
        return (
          <g key={d.date}>
            <title>{`${DATE.format(date)}: ${formatKES(d.revenue)} sales, ${formatKES(d.profit)} profit, ${d.count} sales`}</title>
            <rect x={x} y={y(d.revenue)} width={w} height={Math.max(0, y(0) - y(d.revenue))} rx="3" fill={recent ? 'var(--color-navy)' : 'var(--color-mist)'} />
            <rect x={x} y={y(d.profit)} width={w} height={Math.max(0, y(0) - y(d.profit))} rx="3" fill={recent ? 'var(--color-amber)' : 'var(--color-steel)'} opacity={recent ? 1 : 0.5} />
            <text x={x + w / 2} y={H - 10} textAnchor="middle" fontSize="11" fill="var(--color-muted)">
              {i === days.length - 1 ? 'Today' : DAY.format(date)}
            </text>
          </g>
        )
      })}
    </svg>
  )
}

function niceStep(max) {
  const raw = max / 4
  const mag = 10 ** Math.floor(Math.log10(raw))
  const n = raw / mag
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * mag
}
