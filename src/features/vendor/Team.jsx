import { useState } from 'react'
import { useLoaderData, useRevalidator } from 'react-router'
import { KeyRound, MapPin, Pencil, Plus, Trash2, UserPlus } from 'lucide-react'
import { api } from '@/services/api'
import { formatDate } from '@/lib/format'
import { STAFF_ROLE } from '@/lib/labels'
import { PageHeader } from '@/components/layout/AppShell'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { Field } from '@/components/ui/Field'
import { TempPasswordCard } from '@/components/TempPasswordCard'
import { useVendor } from './VendorLayout'
import { requireStoreId } from './guard'

export async function loader() {
  return { staff: await api.listStaff(requireStoreId()) }
}

const ROLE_HELP = {
  manager: 'Everything except store settings, billing and staff. Sees buying prices and profit.',
  cashier: 'Sells at the counter, handles orders and credit payments. Never sees buying prices or profit.',
}

/** Owners: staff logins and branches, within the plan's limits. */
function Team() {
  const { staff } = useLoaderData()
  const { store } = useVendor()
  const revalidator = useRevalidator()
  const [editing, setEditing] = useState(null) // a staff member, or {} for new
  const [branchEdit, setBranchEdit] = useState(null)
  const [password, setPassword] = useState(null)
  const [error, setError] = useState(null)
  const staffCount = staff.filter((m) => m.role !== 'owner').length
  const staffLimit = store.limits.staff
  const branchLimit = store.limits.branches
  const branchName = (id) => store.branches.find((b) => b.id === id)?.name
  const run = async (fn) => {
    setError(null)
    try {
      await fn()
      revalidator.revalidate()
    } catch (e) {
      setError(e.message)
    }
  }

  return (
    <>
      <title>Staff & branches — Topline</title>
      <PageHeader title="Staff & branches">Give each person their own login, so every sale shows who made it and cashiers never see your buying prices.</PageHeader>
      {error && <p className="mb-4 rounded-lg bg-bad-tint px-4 py-3 text-sm text-bad" role="alert">{error}</p>}

      <section aria-labelledby="staff-h">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h2 id="staff-h" className="text-lg font-bold">Staff</h2>
            <p className="text-sm text-muted">{staffLimit === null ? `${staffCount} staff logins` : `${staffCount} of ${staffLimit} staff logins on your plan`}</p>
          </div>
          <Button variant="navy" onClick={() => setEditing({})} disabled={staffLimit !== null && staffCount >= staffLimit}><UserPlus className="size-4" />Add staff</Button>
        </div>
        <ul className="mt-4 divide-y divide-line rounded-card bg-white ring-1 ring-line">
          {staff.map((m) => (
            <li key={m.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3">
              <div className="min-w-0 flex-1">
                <p className="font-medium">{m.name} <Badge tone={m.role === 'owner' ? 'navy' : 'neutral'} className="ml-1">{STAFF_ROLE[m.role]}</Badge></p>
                <p className="text-sm text-muted">
                  {m.phone}
                  {store.branches.length > 1 && m.role !== 'owner' && ` · ${m.branchId ? branchName(m.branchId) : 'All branches'}`}
                  {m.role !== 'owner' && (m.mustChangePassword ? ' · hasn’t logged in yet' : m.lastLoginAt ? ` · last logged in ${formatDate(m.lastLoginAt)}` : '')}
                </p>
              </div>
              {m.role !== 'owner' && (
                <div className="flex gap-1">
                  <button type="button" onClick={() => setEditing(m)} className="rounded-lg p-2 text-muted hover:bg-concrete hover:text-ink" aria-label={`Change ${m.name}`}><Pencil className="size-4" /></button>
                  <button type="button" onClick={() => window.confirm(`Give ${m.name} a new temporary password? The current one stops working.`) && run(async () => setPassword(await api.resetStaffPassword(store.id, m.id)))}
                    className="rounded-lg p-2 text-muted hover:bg-concrete hover:text-ink" aria-label={`New password for ${m.name}`}><KeyRound className="size-4" /></button>
                  <button type="button" onClick={() => window.confirm(`Remove ${m.name}? They can’t log in any more. Their name stays on past sales.`) && run(() => api.removeStaff(store.id, m.id))}
                    className="rounded-lg p-2 text-muted hover:bg-bad-tint hover:text-bad" aria-label={`Remove ${m.name}`}><Trash2 className="size-4" /></button>
                </div>
              )}
            </li>
          ))}
        </ul>
      </section>

      <section aria-labelledby="branches-h" className="mt-10">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h2 id="branches-h" className="text-lg font-bold">Branches</h2>
            <p className="text-sm text-muted">
              Each branch has its own stock and sales. Pick the branch you’re working at from the top bar. Online orders come from the main branch.
              {' '}{branchLimit === null ? '' : `Your plan includes ${branchLimit} ${branchLimit === 1 ? 'branch' : 'branches'}.`}
            </p>
          </div>
          <Button variant="outline" onClick={() => setBranchEdit({})} disabled={branchLimit !== null && store.branches.length >= branchLimit}><Plus className="size-4" />Add branch</Button>
        </div>
        <ul className="mt-4 divide-y divide-line rounded-card bg-white ring-1 ring-line">
          {store.branches.map((b) => (
            <li key={b.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3">
              <MapPin className="size-5 text-muted" />
              <div className="min-w-0 flex-1">
                <p className="font-medium">{b.name} {b.isMain && <Badge className="ml-1">Main</Badge>}</p>
                <p className="text-sm text-muted">{b.location || 'No location'} · {b.staff} staff tied to it</p>
              </div>
              <div className="flex gap-1">
                <button type="button" onClick={() => setBranchEdit(b)} className="rounded-lg p-2 text-muted hover:bg-concrete hover:text-ink" aria-label={`Rename ${b.name}`}><Pencil className="size-4" /></button>
                {!b.isMain && (
                  <button type="button" onClick={() => window.confirm(`Remove the branch ${b.name}?`) && run(() => api.removeBranch(store.id, b.id))}
                    className="rounded-lg p-2 text-muted hover:bg-bad-tint hover:text-bad" aria-label={`Remove ${b.name}`}><Trash2 className="size-4" /></button>
                )}
              </div>
            </li>
          ))}
        </ul>
        {branchLimit !== null && store.branches.length >= branchLimit && (
          <p className="mt-2 text-sm text-muted">To add more branches, change plan under Plan & billing.</p>
        )}
      </section>

      {editing && <StaffDialog member={editing} store={store} onClose={() => setEditing(null)} onSaved={(r) => { setEditing(null); if (r?.temporaryPassword) setPassword({ ...r.member, temporaryPassword: r.temporaryPassword, login: r.member.phone }); revalidator.revalidate() }} />}
      {branchEdit && <BranchDialog branch={branchEdit} store={store} onClose={() => setBranchEdit(null)} onSaved={() => { setBranchEdit(null); revalidator.revalidate() }} />}
      <Dialog open={Boolean(password)} onClose={() => setPassword(null)} title="Temporary password">
        {password && <TempPasswordCard name={password.name} login={password.login} password={password.temporaryPassword} />}
      </Dialog>
    </>
  )
}

function StaffDialog({ member, store, onClose, onSaved }) {
  const isNew = !member.id
  const [form, setForm] = useState({ name: member.name ?? '', phone: member.phone ?? '', role: member.role ?? 'cashier', branchId: member.branchId ?? '' })
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const set = (patch) => setForm((f) => ({ ...f, ...patch }))
  const err = (f) => (error?.field === f ? error.message : undefined)
  async function save(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      onSaved(isNew ? await api.addStaff(store.id, form) : await api.updateStaff(store.id, member.id, form))
    } catch (e2) {
      setError(e2)
      setBusy(false)
    }
  }
  return (
    <Dialog open onClose={onClose} title={isNew ? 'Add staff' : `Change ${member.name}`}>
      <form onSubmit={save} className="space-y-4">
        {isNew && (
          <>
            <Field label="Name" value={form.name} onChange={(e) => set({ name: e.target.value })} error={err('name')} required />
            <Field label="Mobile number" type="tel" value={form.phone} onChange={(e) => set({ phone: e.target.value })} error={err('phone')} placeholder="0712 345 678" hint="They log in with this number." required />
          </>
        )}
        <fieldset>
          <legend className="text-sm font-medium">Role</legend>
          <div className="mt-1.5 space-y-2">
            {['cashier', 'manager'].map((r) => (
              <label key={r} className="flex cursor-pointer gap-3 rounded-lg border border-line p-3 has-checked:border-navy has-checked:bg-mist/40">
                <input type="radio" name="role" value={r} checked={form.role === r} onChange={() => set({ role: r })} className="mt-1 size-4 accent-navy" />
                <span><span className="block font-medium">{STAFF_ROLE[r]}</span><span className="block text-sm text-muted">{ROLE_HELP[r]}</span></span>
              </label>
            ))}
          </div>
          {err('role') && <p className="mt-1 text-sm text-bad">{err('role')}</p>}
        </fieldset>
        {store.branches.length > 1 && (
          <Field as="select" label="Works at" value={form.branchId} onChange={(e) => set({ branchId: e.target.value })} error={err('branchId')}
            hint="Someone tied to one branch only sells and sees stock there.">
            <option value="">All branches</option>
            {store.branches.map((b) => <option key={b.id} value={b.id}>{b.name} only</option>)}
          </Field>
        )}
        {error && !['name', 'phone', 'role', 'branchId'].includes(error.field) && <p className="text-sm text-bad" role="alert">{error.message}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="navy" pending={busy}>{isNew ? 'Add and get password' : 'Save'}</Button>
        </div>
      </form>
    </Dialog>
  )
}

function BranchDialog({ branch, store, onClose, onSaved }) {
  const isNew = !branch.id
  const [form, setForm] = useState({ name: branch.name ?? '', location: branch.location ?? '' })
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  async function save(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await (isNew ? api.addBranch(store.id, form) : api.updateBranch(store.id, branch.id, form))
      onSaved()
    } catch (e2) {
      setError(e2)
      setBusy(false)
    }
  }
  return (
    <Dialog open onClose={onClose} title={isNew ? 'Add branch' : `Rename ${branch.name}`}>
      <form onSubmit={save} className="space-y-4">
        <Field label="Branch name" value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} error={error?.field === 'name' ? error.message : undefined} placeholder="e.g. Kitengela" required />
        <Field label="Location (optional)" value={form.location} onChange={(e) => setForm((f) => ({ ...f, location: e.target.value }))} placeholder="Street or landmark" />
        {isNew && <p className="text-sm text-muted">The new branch starts with no stock. Move stock to it from Products & stock, or count it in.</p>}
        {error && error.field !== 'name' && <p className="text-sm text-bad" role="alert">{error.message}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="navy" pending={busy}>Save</Button>
        </div>
      </form>
    </Dialog>
  )
}

export { Team as Component }
