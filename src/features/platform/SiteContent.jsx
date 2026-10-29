import { useState } from 'react'
import { useLoaderData } from 'react-router'
import { CircleCheck, ExternalLink } from 'lucide-react'
import { api } from '@/services/api'
import { PageHeader } from '@/components/layout/AppShell'
import { PhotoUpload } from '@/components/PhotoUpload'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { requirePlatformAdmin } from '@/features/vendor/guard'

export async function loader() {
  requirePlatformAdmin()
  return { site: await api.getSite() }
}

const IMAGES = [
  ['heroImage', 'Main picture (top of the home page)', 'aspect-[9/10]'],
  ['featureImageStore', 'Picture: online store section', 'aspect-[4/3]'],
  ['featureImageTill', 'Picture: counter till section', 'aspect-[4/3]'],
  ['featureImageStock', 'Picture: stock and credit section', 'aspect-[4/3]'],
  ['signupImage', 'Picture beside the sign-up form', 'aspect-[3/4]'],
]

function SiteContent() {
  const { site } = useLoaderData()
  const [form, setForm] = useState(site)
  const [status, setStatus] = useState(null)
  const set = (patch) => setForm((f) => ({ ...f, ...patch }))

  async function save(e) {
    e.preventDefault()
    setStatus({ saving: true })
    try {
      setForm(await api.updateSite(form))
      setStatus({ saved: true })
    } catch (err) {
      setStatus({ error: err.message, field: err.field })
    }
  }

  return (
    <form onSubmit={save} className="max-w-4xl space-y-6">
      <title>Site content — Topline admin</title>
      <PageHeader title="Site content" actions={<Button href="/" target="_blank" variant="outline"><ExternalLink className="size-4" />View site</Button>}>
        Words and pictures on the main Topline website.
      </PageHeader>

      <section className="space-y-5 rounded-card bg-white p-5 ring-1 ring-line sm:p-6">
        <h2 className="font-bold">Home page</h2>
        <Field label="Headline" value={form.heroTitle} onChange={(e) => set({ heroTitle: e.target.value })} maxLength={120} error={status?.field === 'heroTitle' ? status.error : undefined} />
        <Field as="textarea" label="Text under the headline" value={form.heroText} onChange={(e) => set({ heroText: e.target.value })} maxLength={300} />
        <Field label="Announcement bar (optional)" value={form.announcement} onChange={(e) => set({ announcement: e.target.value })} maxLength={200}
               placeholder="e.g. First month free for shops in Nakuru until 30 November" hint="Shows across the top of the main site. Leave empty to hide it." />
      </section>

      <section className="rounded-card bg-white p-5 ring-1 ring-line sm:p-6">
        <h2 className="font-bold">Pictures</h2>
        <p className="mt-1 text-sm text-muted">Use clear photos of real hardware shops. Landscape photos at least 1200 px wide look best.</p>
        <div className="mt-5 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {IMAGES.map(([key, label, aspect]) => (
            <PhotoUpload key={key} label={label} value={form[key]} onChange={(url) => set({ [key]: url })} aspect={aspect} />
          ))}
        </div>
      </section>

      <section className="grid gap-5 rounded-card bg-white p-5 ring-1 ring-line sm:grid-cols-3 sm:p-6">
        <h2 className="font-bold sm:col-span-3">Support contacts (shown in the footer and on error pages)</h2>
        <Field label="Phone" value={form.supportPhone} onChange={(e) => set({ supportPhone: e.target.value })} />
        <Field label="WhatsApp (2547…)" value={form.supportWhatsapp} onChange={(e) => set({ supportWhatsapp: e.target.value })} />
        <Field label="Email" type="email" value={form.supportEmail} onChange={(e) => set({ supportEmail: e.target.value })} />
      </section>

      <div className="flex items-center justify-end gap-4">
        {status?.saved && <p className="flex items-center gap-1.5 text-sm text-good" role="status"><CircleCheck className="size-4" />Published</p>}
        {status?.error && !status.field && <p className="text-sm text-bad" role="alert">{status.error}</p>}
        <Button type="submit" variant="navy" pending={status?.saving}>Publish changes</Button>
      </div>
    </form>
  )
}

export { SiteContent as Component }
