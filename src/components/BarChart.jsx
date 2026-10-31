import { formatCompact } from '@/lib/format'

/**
 * Small stacked bar chart in plain SVG.
 * rows:   [{ label, title, values: [n, n] }]   (one value per series)
 * series: [{ name, color }]                    (colors as CSS variables)
 */
export function BarChart({ rows, series, height = 200, ariaLabel, labelEvery = 1 }) {
  const W = 720
  const pad = { t: 10, r: 6, b: 26, l: 40 }
  const totals = rows.map((r) => r.values.reduce((a, b) => a + b, 0))
  const max = Math.max(1, ...totals)
  const step = niceStep(max)
  const top = Math.ceil(max / step) * step
  const ticks = Array.from({ length: Math.round(top / step) + 1 }, (_, i) => i * step)
  const innerW = W - pad.l - pad.r
  const innerH = height - pad.t - pad.b
  const bw = innerW / Math.max(rows.length, 1)
  const y = (v) => pad.t + innerH - (v / top) * innerH

  return (
    <svg viewBox={`0 0 ${W} ${height}`} className="h-auto w-full" role="img" aria-label={ariaLabel}>
      {ticks.map((t) => (
        <g key={t}>
          <line x1={pad.l} x2={W - pad.r} y1={y(t)} y2={y(t)} stroke="var(--color-line)" />
          <text x={pad.l - 6} y={y(t) + 4} textAnchor="end" fontSize="11" fill="var(--color-muted)">{formatCompact(t)}</text>
        </g>
      ))}
      {rows.map((row, i) => {
        const x = pad.l + i * bw + bw * 0.15
        const w = Math.max(1, bw * 0.7)
        let base = 0
        return (
          <g key={row.label + i}>
            <title>{row.title ?? `${row.label}: ${row.values.join(' + ')}`}</title>
            {row.values.map((v, s) => {
              const y0 = y(base)
              base += v
              return <rect key={s} x={x} y={y(base)} width={w} height={Math.max(0, y0 - y(base))} fill={series[s].color} rx="1.5" />
            })}
            {i % labelEvery === 0 && (
              <text x={x + w / 2} y={height - 8} textAnchor="middle" fontSize="10.5" fill="var(--color-muted)">{row.label}</text>
            )}
          </g>
        )
      })}
    </svg>
  )
}

export function Legend({ series }) {
  return (
    <div className="flex flex-wrap gap-4 text-xs text-muted">
      {series.map((s) => (
        <span key={s.name} className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm" style={{ background: s.color }} />{s.name}</span>
      ))}
    </div>
  )
}

function niceStep(max) {
  const raw = max / 4
  const mag = 10 ** Math.floor(Math.log10(raw))
  const n = raw / mag
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * mag
}
