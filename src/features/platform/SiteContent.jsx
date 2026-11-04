import { useState } from 'react'
import { useLoaderData, useRevalidator } from 'react-router'
import { CircleCheck, ExternalLink, Plus, Trash2 } from 'lucide-react'
import { api } from '@/services/api'
import { PageHeader } from '@/components/layout/AppShell'
import { PhotoUpload } from '@/components/PhotoUpload'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { requirePlatformAdmin } from '@/features/vendor/guard'

export async function loader() {
  requirePlatformAdmin()
  const [site, plans] = await Promise.all([api.getSite(), api.getPlans()])
  return { site, plans }
}

/* Every editable text on the main site, in page order. [key, label, kind]: kind "long" = paragraph, "lines" = one point per line. */
const SECTIONS = [
  ['Top of every page', [['announcement', 'Announcement bar (optional; leave empty to hide)']]],
  ['Home page: top', [['heroTitle', 'Headline'], ['heroText', 'Text under the headline', 'long']]],
  ['Home page: what you get', [
    ['featuresTitle', 'Section heading'],
    ['feature1Title', 'Block 1 heading'], ['feature1Text', 'Block 1 text', 'long'], ['feature1Points', 'Block 1 points (one per line)', 'lines'],
    ['feature2Title', 'Block 2 heading'], ['feature2Text', 'Block 2 text', 'long'], ['feature2Points', 'Block 2 points (one per line)', 'lines'],
    ['feature3Title', 'Block 3 heading'], ['feature3Text', 'Block 3 text', 'long'], ['feature3Points', 'Block 3 points (one per line)', 'lines'],
  ]],
  ['Home page: suppliers and stock credit', [
    ['suppliersKicker', 'Small heading'], ['suppliersTitle', 'Heading'], ['suppliersText', 'Text', 'long'],
    ['suppliersEmpty', 'Shown when no supplier is listed yet', 'long'], ['suppliersJoinText', 'Invitation to suppliers', 'long'],
    ['creditTitle', 'Stock credit heading'], ['creditText', 'Stock credit text', 'long'],
  ]],
  ['Home page: how to start', [
    ['stepsTitle', 'Section heading'],
    ['step1Title', 'Step 1 heading'], ['step1Text', 'Step 1 text', 'long'],
    ['step2Title', 'Step 2 heading'], ['step2Text', 'Step 2 text', 'long'],
    ['step3Title', 'Step 3 heading'], ['step3Text', 'Step 3 text', 'long'],
  ]],
  ['Home page: pricing', [['pricingTitle', 'Section heading'], ['pricingText', 'Text', 'long'], ['pricingNote', 'Small note under the plans (optional)']]],
  ['Home page: closing banner', [['closingTitle', 'Heading'], ['closingText', 'Text']]],
  ['Reviews page', [['reviewsTitle', 'Heading'], ['reviewsText', 'Text', 'long']]],
  ['Footer and contacts', [
    ['footerText', 'Footer text', 'long'], ['supportPhone', 'Phone'], ['supportWhatsapp', 'WhatsApp (2547…)'],
    ['supportEmail', 'Email'], ['supportLocation', 'Location'],
  ]],
]

const IMAGES = [
  ['heroImage', 'Main picture (top of the home page)', 'aspect-[9/10]'],
  ['featureImageStore', 'Picture: block 1', 'aspect-[4/3]'],
  ['featureImageTill', 'Picture: block 2', 'aspect-[4/3]'],
  ['featureImageStock', 'Picture: block 3', 'aspect-[4/3]'],
  ['signupImage', 'Picture beside the sign-up form', 'aspect-[3/4]'],
]

const parseFaqs = (json) => {
  try {
    const list = JSON.parse(json || '[]')
    return Array.isArray(list) ? list : []
  } catch {
    return []
  }
}

function SiteContent() {
  const { site, plans } = useLoaderData()
  const [form, setForm] = useState(site)
  const [faqs, setFaqs] = useState(() => parseFaqs(site.faqs))
  const [status, setStatus] = useState(null)
  const set = (patch) => { setStatus(null); setForm((f) => ({ ...f, ...patch })) }
  const setFaq = (i, patch) => { setStatus(null); setFaqs((list) => list.map((f, j) => (j === i ? { ...f, ...patch } : f))) }
  const err = (key) => (status?.field === key ? status.error : undefined)

  async function save(e) {
    e.preventDefault()
    setStatus({ saving: true })
    try {
      const saved = await api.updateSite({ ...form, faqs })
      setForm(saved)
      setFaqs(parseFaqs(saved.faqs))
      setStatus({ saved: true })
    } catch (e2) {
      setStatus({ error: e2.message, field: e2.field })
      if (e2.field) document.getElementById(`site-${e2.field}`)?.scrollIntoView({ block: 'center' })
    }
  }

  return (
    <div className="max-w-4xl space-y-6">
      <title>Site content — Topline admin</title>
      <PageHeader title="Site content" actions={<Button href="/" target="_blank" variant="outline"><ExternalLink className="size-4" />View site</Button>}>
        Every word and picture on the main Topline website, and the price plans. Changes show on the site as soon as you publish.
      </PageHeader>

      <form onSubmit={save} noValidate className="space-y-6">
        {SECTIONS.map(([title, fields]) => (
          <section key={title} className="space-y-4 rounded-card bg-white p-5 ring-1 ring-line sm:p-6">
            <h2 className="font-bold">{title}</h2>
            {fields.map(([key, label, kind]) => (
              <div key={key} id={`site-${key}`}>
                <Field as={kind ? 'textarea' : 'input'} label={label} value={form[key] ?? ''} rows={kind === 'lines' ? 3 : undefined}
                  onChange={(e) => set({ [key]: e.target.value })} error={err(key)} />
              </div>
            ))}
          </section>
        ))}

        <section id="site-faqs" className="rounded-card bg-white p-5 ring-1 ring-line sm:p-6">
          <h2 className="font-bold">Home page: questions</h2>
          <div className="mt-4 space-y-4">
            <Field label="Section heading" value={form.faqTitle ?? ''} onChange={(e) => set({ faqTitle: e.target.value })} error={err('faqTitle')} />
            {faqs.map((f, i) => (
              <div key={i} className="rounded-lg border border-line p-4">
                <div className="flex items-start gap-2">
                  <Field className="flex-1" label={`Question ${i + 1}`} value={f.q} onChange={(e) => setFaq(i, { q: e.target.value })} maxLength={200} />
                  <button type="button" onClick={() => setFaqs((list) => list.filter((_, j) => j !== i))} className="mt-7 rounded-lg p-2.5 text-bad hover:bg-bad-tint" aria-label={`Remove question ${i + 1}`}>
                    <Trash2 className="size-4" />
                  </button>
                </div>
                <Field as="textarea" className="mt-3" label="Answer" value={f.a} onChange={(e) => setFaq(i, { a: e.target.value })} maxLength={1000} />
              </div>
            ))}
            {err('faqs') && <p className="text-sm text-bad">{err('faqs')}</p>}
            {faqs.length < 15 && (
              <Button type="button" variant="outline" onClick={() => setFaqs((list) => [...list, { q: '', a: '' }])}><Plus className="size-4" />Add a question</Button>
            )}
          </div>
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

        <div className="sticky bottom-0 z-10 -mx-1 flex items-center justify-end gap-4 border-t border-line bg-concrete/95 px-1 py-3 backdrop-blur">
          {status?.saved && <p className="flex items-center gap-1.5 text-sm text-good" role="status"><CircleCheck className="size-4" />Published</p>}
          {status?.error && <p className="text-sm text-bad" role="alert">{status.error}</p>}
          <Button type="submit" variant="navy" pending={status?.saving}>Publish changes</Button>
        </div>
      </form>

      <section aria-labelledby="plans-h" className="pt-4">
        <h2 id="plans-h" className="text-xl font-bold">Price plans</h2>
        <p className="mt-1 text-sm text-muted">What shops pay and what each plan lists on the pricing section. A new price applies from each shop’s next payment.</p>
        <div className="mt-4 grid gap-5 lg:grid-cols-3">
          {plans.map((p) => <PlanEditor key={p.id} plan={p} />)}
        </div>
      </section>
    </div>
  )
}

function PlanEditor({ plan }) {
  const revalidator = useRevalidator()
  const [form, setForm] = useState(() => ({
    name: plan.name, priceMonthly: plan.priceMonthly, priceYearly: plan.priceYearly, blurb: plan.blurb,
    features: plan.features.join('\n'), productLimit: plan.limits.products ?? '', staffLimit: plan.limits.staff ?? '',
    branchLimit: plan.limits.branches ?? '', customDomain: Boolean(plan.limits.customDomain), popular: plan.popular,
  }))
  const [status, setStatus] = useState(null)
  const set = (patch) => { setStatus(null); setForm((f) => ({ ...f, ...patch })) }
  const err = (key) => (status?.field === key ? status.error : undefined)

  async function save(e) {
    e.preventDefault()
    setStatus({ saving: true })
    try {
      await api.adminUpdatePlan(plan.id, { ...form, features: form.features.split('\n') })
      setStatus({ saved: true })
      revalidator.revalidate() // "most shops pick this" may have moved
    } catch (e2) {
      setStatus({ error: e2.message, field: e2.field })
    }
  }

  return (
    <form onSubmit={save} noValidate className="space-y-3 rounded-card bg-white p-5 ring-1 ring-line">
      <Field label="Plan name" value={form.name} onChange={(e) => set({ name: e.target.value })} error={err('name')} maxLength={40} />
      <div className="grid grid-cols-2 gap-3">
        <Field label="KSh / month" type="number" min="0" inputMode="numeric" value={form.priceMonthly} onChange={(e) => set({ priceMonthly: e.target.value })} error={err('priceMonthly')} />
        <Field label="KSh / year" type="number" min="0" inputMode="numeric" value={form.priceYearly} onChange={(e) => set({ priceYearly: e.target.value })} error={err('priceYearly')} />
      </div>
      <Field label="Short description" value={form.blurb} onChange={(e) => set({ blurb: e.target.value })} error={err('blurb')} maxLength={200} />
      <Field as="textarea" rows={6} label="What it includes (one per line)" value={form.features} onChange={(e) => set({ features: e.target.value })} error={err('features')} />
      <p className="text-sm font-medium">Limits <span className="font-normal text-muted">(empty = unlimited; every plan has every feature)</span></p>
      <div className="grid grid-cols-3 gap-3">
        <Field label="Products" type="number" min="1" inputMode="numeric" value={form.productLimit} onChange={(e) => set({ productLimit: e.target.value })} error={err('productLimit')} />
        <Field label="Staff logins" type="number" min="0" inputMode="numeric" value={form.staffLimit} onChange={(e) => set({ staffLimit: e.target.value })} error={err('staffLimit')} />
        <Field label="Branches" type="number" min="1" inputMode="numeric" value={form.branchLimit} onChange={(e) => set({ branchLimit: e.target.value })} error={err('branchLimit')} />
      </div>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={form.customDomain} onChange={(e) => set({ customDomain: e.target.checked })} className="size-4 accent-navy" />
        Shops can use their own domain
      </label>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={form.popular} onChange={(e) => set({ popular: e.target.checked })} className="size-4 accent-navy" />
        Highlight as “Most shops pick this”
      </label>
      {status?.error && !status.field && <p className="text-sm text-bad" role="alert">{status.error}</p>}
      <div className="flex items-center gap-3">
        <Button type="submit" variant="navy" size="sm" pending={status?.saving}>Save {plan.name}</Button>
        {status?.saved && <span className="flex items-center gap-1 text-sm text-good" role="status"><CircleCheck className="size-4" />Saved</span>}
      </div>
    </form>
  )
}

export { SiteContent as Component }
