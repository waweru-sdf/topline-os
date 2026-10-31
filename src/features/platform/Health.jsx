import { useState } from 'react'
import { useFetcher, useLoaderData, useSearchParams } from 'react-router'
import { Bell, CircleCheck } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate, formatTime } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PageHeader } from '@/components/layout/AppShell'
import { BarChart, Legend } from '@/components/BarChart'
import { Badge } from '@/components/ui/Badge'
import { requirePlatformAdmin } from '@/features/vendor/guard'

export async function loader({ request }) {
  requirePlatformAdmin()
  const show = new URL(request.url).searchParams.get('show') ?? 'open'
  const [incidents, overview] = await Promise.all([api.listIncidents(show), api.getOverview()])
  return { incidents, health: overview.health, show }
}

export async function action({ request }) {
  const fd = await request.formData()
  await api.resolveIncident(fd.get('id'), fd.get('resolved') === 'true')
  return null
}

const KIND = { server_error: ['Server error', 'bad'], payment: ['Payment', 'amber'], payout: ['Payout', 'amber'], browser: ['Browser', 'navy'], sms: ['SMS', 'amber'] }
const HOURS = new Intl.DateTimeFormat('en-KE', { hour: 'numeric' })
const SERIES = [{ name: 'Requests', color: 'var(--color-mist)' }, { name: 'Server errors', color: 'var(--color-bad)' }]

function Health() {
  const { incidents, health, show } = useLoaderData()
  const [, setParams] = useSearchParams()
  const errorRate = health.requests24 ? (health.serverErrors24 / health.requests24) * 100 : 0

  return (
    <>
      <title>System health — Topline admin</title>
      <PageHeader title="System health">Problems are grouped, counted and sent to you as alerts.</PageHeader>

      <section aria-label="Last 24 hours" className="grid divide-y divide-line rounded-card bg-white ring-1 ring-line sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 lg:divide-x">
        <Stat label="Requests (24 h)" value={health.requests24.toLocaleString('en-KE')} />
        <Stat label="Failed (server errors)" value={`${errorRate.toFixed(2)}%`} note={`${health.serverErrors24} of ${health.requests24}`} tone={health.serverErrors24 ? 'text-bad' : 'text-good'} />
        <Stat label="Average response" value={`${health.avgMs} ms`} note={`Slowest ${health.slowestMs} ms`} />
        <Stat label="Open problems" value={health.openIncidents} tone={health.openIncidents ? 'text-bad' : 'text-good'} />
      </section>

      {health.byHour.length > 0 && (
        <section className="mt-6 rounded-card bg-white p-5 ring-1 ring-line">
          <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="font-bold">Requests per hour</h2><Legend series={SERIES} /></div>
          <div className="mt-4">
            <BarChart ariaLabel="API requests per hour" series={SERIES} height={170} labelEvery={3}
                      rows={health.byHour.map((h) => ({ label: HOURS.format(new Date(h.hour)), values: [h.requests - h.errors, h.errors], title: `${h.requests} requests, ${h.errors} errors` }))} />
          </div>
        </section>
      )}

      <section className="mt-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-lg font-bold">Problems</h2>
          <div className="flex gap-1 rounded-lg bg-white p-1 ring-1 ring-line">
            {[['open', 'Open'], ['all', 'All']].map(([id, label]) => (
              <button key={id} type="button" aria-pressed={show === id} onClick={() => setParams(id === 'open' ? {} : { show: id })}
                      className={cn('rounded-md px-3 py-1.5 text-sm', show === id ? 'bg-navy text-white' : 'text-muted hover:text-ink')}>{label}</button>
            ))}
          </div>
        </div>
        {incidents.length === 0 ? (
          <p className="mt-3 flex items-center gap-2 rounded-card bg-white p-5 text-good ring-1 ring-line"><CircleCheck className="size-5" />No open problems.</p>
        ) : (
          <ul className="mt-3 space-y-3">{incidents.map((i) => <IncidentRow key={i.id} incident={i} />)}</ul>
        )}
      </section>

      <section className="mt-6 rounded-card bg-white p-5 text-sm ring-1 ring-line">
        <h2 className="flex items-center gap-2 font-bold"><Bell className="size-4" />How you’re alerted</h2>
        <p className="mt-2 text-muted">Each problem alerts you the 1st, 10th, 100th and 1,000th time it happens, and again if it comes back after you mark it fixed. Set in <code className="rounded bg-concrete px-1">backend/.env</code>:</p>
        <ul className="mt-2 list-disc space-y-1 pl-5 text-muted">
          <li><code className="rounded bg-concrete px-1">ALERT_WEBHOOK_URL</code>: a Slack, Discord or Microsoft Teams channel webhook (works now)</li>
          <li><code className="rounded bg-concrete px-1">ALERT_SMS_PHONES</code>: phone numbers for SMS alerts (sent once SMS is live)</li>
        </ul>
      </section>
    </>
  )
}

function IncidentRow({ incident: i }) {
  const fetcher = useFetcher()
  const [open, setOpen] = useState(false)
  const [kind, tone] = KIND[i.kind] ?? [i.kind, 'neutral']
  return (
    <li className={cn('rounded-card bg-white p-5 ring-1 ring-line', i.resolved && 'opacity-60')}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="flex flex-wrap items-center gap-2 font-medium"><Badge tone={tone}>{kind}</Badge>{i.title}</p>
          <p className="mt-1 text-sm text-muted">
            {i.count.toLocaleString('en-KE')} time{i.count > 1 ? 's' : ''}, last {formatDate(i.lastSeen)} at {formatTime(i.lastSeen)}{i.path ? `, on ${i.path}` : ''}
          </p>
        </div>
        <fetcher.Form method="post">
          <input type="hidden" name="id" value={i.id} />
          <input type="hidden" name="resolved" value={String(!i.resolved)} />
          <button className="rounded-lg px-3 py-1.5 text-sm font-medium text-navy ring-1 ring-line hover:bg-concrete">{i.resolved ? 'Reopen' : 'Mark fixed'}</button>
        </fetcher.Form>
      </div>
      {i.detail && (
        <>
          <button type="button" onClick={() => setOpen((o) => !o)} className="mt-2 text-sm text-navy hover:underline">{open ? 'Hide details' : 'Show details'}</button>
          {open && <pre className="mt-2 max-h-72 overflow-auto rounded-lg bg-ink p-3 text-xs text-mist">{i.detail}</pre>}
        </>
      )}
    </li>
  )
}

function Stat({ label, value, note, tone }) {
  return (
    <div className="p-5">
      <p className="text-sm text-muted">{label}</p>
      <p className={cn('mt-1 text-2xl font-bold tabular', tone ?? 'text-ink')}>{value}</p>
      {note && <p className="mt-1 text-sm text-muted">{note}</p>}
    </div>
  )
}

export { Health as Component }
