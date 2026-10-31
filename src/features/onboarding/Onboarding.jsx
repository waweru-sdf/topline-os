import { useActionState, useEffect, useState } from 'react'
import { Link, useLoaderData, useNavigate } from 'react-router'
import { ArrowLeft, Check, CircleCheck, Link2, LoaderCircle, Smartphone, X } from 'lucide-react'
import { PaymentPrompt } from '@/components/PaymentPrompt'
import { PAYOUT_METHODS, payoutMethod } from '@/lib/payments'
import { api } from '@/services/api'
import { setSession } from '@/services/session'
import { COUNTIES } from '@/lib/counties'
import { formatKES, normalizePhone, slugify } from '@/lib/format'
import { storeHost, storeLinkParts } from '@/lib/tenant'
import { cn } from '@/lib/cn'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Img } from '@/components/ui/Img'
import { Logo } from '@/components/ui/Logo'
import { PasswordField } from '@/components/ui/PasswordField'

export async function loader({ request }) {
  const url = new URL(request.url)
  return {
    plans: await api.getPlans(),
    site: await api.getSite(),
    initial: {
      shopName: url.searchParams.get('name') ?? '',
      planId: url.searchParams.get('plan') ?? 'business',
      billing: url.searchParams.get('billing') === 'yearly' ? 'yearly' : 'monthly',
    },
  }
}

const STEPS = ['You', 'Your shop', 'Your link', 'Plan & payment']

function Onboarding() {
  const { plans, initial, site } = useLoaderData()
  const [step, setStep] = useState(0)
  const [form, setForm] = useState({
    ownerName: '',
    ownerPhone: '',
    ownerEmail: '',
    ownerPassword: '',
    ownerPassword2: '',
    shopName: initial.shopName,
    tagline: '',
    county: '',
    town: '',
    address: '',
    whatsapp: '',
    payoutMethod: 'till',
    payoutNumber: '',
    payoutAccount: '',
    slug: slugify(initial.shopName),
    planId: initial.planId,
    billing: initial.billing,
  })
  const update = (patch) => setForm((f) => ({ ...f, ...patch }))
  // A plan payment that went through, kept if opening the store then fails (e.g. email already used),
  // so the owner fixes the detail and tries again without paying twice.
  const [paid, setPaid] = useState(null)
  const [serverErrors, setServerErrors] = useState({})
  function detailsProblem(field, message) {
    setServerErrors({ [field]: message })
    setStep(field === 'slug' ? 2 : field.startsWith('owner') ? 0 : 1)
  }

  return (
    <div className="grid min-h-dvh lg:grid-cols-[minmax(0,1fr)_28rem] xl:grid-cols-[minmax(0,1fr)_34rem]">
      <title>Open your store — Topline</title>
      <div className="flex flex-col px-5 py-6 sm:px-10">
        <div className="flex items-center justify-between">
          <Logo />
          <Link to="/" className="text-sm text-muted hover:text-ink">Cancel</Link>
        </div>

        <div className="mx-auto w-full max-w-xl flex-1 py-10">
          <Progress step={step} />
          {step === 0 && <OwnerStep form={form} update={update} serverErrors={serverErrors} onNext={() => { setServerErrors({}); setStep(1) }} />}
          {step === 1 && <ShopStep form={form} update={update} onBack={() => setStep(0)} onNext={() => setStep(2)} />}
          {step === 2 && <LinkStep form={form} update={update} onBack={() => setStep(1)} onNext={() => setStep(3)} />}
          {step === 3 && (
            <PayStep form={form} update={update} plans={plans} onBack={() => setStep(2)} paid={paid} setPaid={setPaid} onDetailsProblem={detailsProblem} />
          )}
        </div>
      </div>

      <aside className="relative hidden bg-navy lg:block" aria-hidden>
        <Img name={site.signupImage} alt="" w={900} h={1400} className="absolute inset-0 size-full opacity-55" priority />
        <div className="absolute inset-x-8 bottom-10 text-white">
          <p className="text-2xl leading-snug font-bold">“My fundis order from the link at night and I deliver in the morning.”</p>
          <p className="mt-3 text-mist">Hardware owner, Kitengela (demo quote)</p>
        </div>
      </aside>
    </div>
  )
}

function Progress({ step }) {
  return (
    <ol className="mb-10 flex gap-2" aria-label="Sign-up progress">
      {STEPS.map((label, i) => (
        <li key={label} className="flex-1" aria-current={i === step ? 'step' : undefined}>
          <div className={cn('h-1.5 rounded-full', i <= step ? 'bg-navy' : 'bg-line')} />
          <span className={cn('mt-2 hidden text-xs sm:block', i === step ? 'font-medium text-navy' : 'text-muted')}>
            {i + 1}. {label}
          </span>
        </li>
      ))}
    </ol>
  )
}

function StepHeader({ title, children }) {
  return (
    <div className="mb-8">
      <h1 className="text-3xl font-bold tracking-tight text-navy">{title}</h1>
      {children && <p className="mt-2 text-muted">{children}</p>}
    </div>
  )
}

function StepNav({ onBack, nextLabel = 'Continue', disabled, pending }) {
  return (
    <div className="mt-10 flex items-center justify-between gap-3">
      {onBack ? (
        <Button variant="ghost" onClick={onBack}>
          <ArrowLeft className="size-4" /> Back
        </Button>
      ) : <span />}
      <Button type="submit" variant="navy" disabled={disabled} pending={pending}>{nextLabel}</Button>
    </div>
  )
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function OwnerStep({ form, update, onNext, serverErrors }) {
  const [errors, setErrors] = useState(serverErrors)
  function submit(e) {
    e.preventDefault()
    const next = {}
    if (!form.ownerName.trim()) next.ownerName = 'Enter your name.'
    if (!normalizePhone(form.ownerPhone)) next.ownerPhone = 'Enter a mobile number like 0712 345 678.'
    if (form.ownerEmail.trim() && !EMAIL.test(form.ownerEmail.trim())) next.ownerEmail = 'Enter a valid email address, or leave it empty.'
    if (form.ownerPassword.length < 8) next.ownerPassword = 'Use at least 8 characters.'
    else if (form.ownerPassword !== form.ownerPassword2) next.ownerPassword2 = 'The two passwords don’t match.'
    setErrors(next)
    if (!Object.keys(next).length) onNext()
  }
  return (
    <form onSubmit={submit} noValidate>
      <StepHeader title="Let’s open your store">First, who owns the shop? You’ll log in with your phone number (or email) and this password.</StepHeader>
      <div className="space-y-5">
        <Field label="Your full name" value={form.ownerName} onChange={(e) => update({ ownerName: e.target.value })} autoComplete="name" error={errors.ownerName} />
        <Field label="Mobile number" type="tel" inputMode="tel" placeholder="0712 345 678" value={form.ownerPhone} onChange={(e) => update({ ownerPhone: e.target.value })} autoComplete="tel" error={errors.ownerPhone} />
        <Field label="Email (optional)" type="email" placeholder="you@example.com" value={form.ownerEmail} onChange={(e) => update({ ownerEmail: e.target.value })} autoComplete="email" error={errors.ownerEmail} hint="You can log in with it too." />
        <PasswordField label="Choose a password" value={form.ownerPassword} onChange={(e) => update({ ownerPassword: e.target.value })} autoComplete="new-password" error={errors.ownerPassword} hint="At least 8 characters. Don’t use your M-PESA PIN." />
        <PasswordField label="Type the password again" value={form.ownerPassword2} onChange={(e) => update({ ownerPassword2: e.target.value })} autoComplete="new-password" error={errors.ownerPassword2} />
      </div>
      <StepNav />
    </form>
  )
}

function ShopStep({ form, update, onBack, onNext }) {
  const [errors, setErrors] = useState({})
  function submit(e) {
    e.preventDefault()
    const next = {}
    if (!form.shopName.trim()) next.shopName = 'Enter the name customers know your shop by.'
    if (!form.county) next.county = 'Pick your county.'
    if (!form.town.trim()) next.town = 'Enter your town or area.'
    if (form.whatsapp && !normalizePhone(form.whatsapp)) next.whatsapp = 'Enter a number like 0712 345 678, or leave it empty.'
    if (['pochi', 'send_money'].includes(form.payoutMethod) && form.payoutNumber && !normalizePhone(form.payoutNumber)) next.payoutNumber = 'Enter the M-PESA phone number like 0712 345 678.'
    if (form.payoutMethod === 'paybill' && form.payoutNumber && !form.payoutAccount.trim()) next.payoutAccount = 'Enter the account number for your paybill.'
    setErrors(next)
    if (Object.keys(next).length) return
    if (!form.slug) update({ slug: slugify(form.shopName) })
    onNext()
  }
  return (
    <form onSubmit={submit} noValidate>
      <StepHeader title="About your shop">This is what customers will see at the top of your store.</StepHeader>
      <div className="grid gap-5 sm:grid-cols-2">
        <Field className="sm:col-span-2" label="Shop name" value={form.shopName} onChange={(e) => update({ shopName: e.target.value })} autoComplete="organization" error={errors.shopName} />
        <Field className="sm:col-span-2" label="Short description (optional)" placeholder="Building materials for Kitengela and beyond" value={form.tagline} onChange={(e) => update({ tagline: e.target.value })} />
        <Field as="select" label="County" value={form.county} onChange={(e) => update({ county: e.target.value })} error={errors.county}>
          <option value="">Choose county</option>
          {COUNTIES.map((c) => <option key={c}>{c}</option>)}
        </Field>
        <Field label="Town or area" placeholder="Kitengela" value={form.town} onChange={(e) => update({ town: e.target.value })} error={errors.town} />
        <Field className="sm:col-span-2" label="Street or landmark (optional)" placeholder="Namanga Road, opposite Total" value={form.address} onChange={(e) => update({ address: e.target.value })} />
        <Field label="WhatsApp for orders" type="tel" placeholder="Same as your mobile" hint="Leave empty to use your mobile number." value={form.whatsapp} onChange={(e) => update({ whatsapp: e.target.value })} error={errors.whatsapp} />
        <Field as="select" label="How you receive money" value={form.payoutMethod} onChange={(e) => update({ payoutMethod: e.target.value })} hint="Online orders are paid out here. You can change it later.">
          {PAYOUT_METHODS.map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
        </Field>
        <Field label={payoutMethod(form.payoutMethod).numberLabel} inputMode={['till', 'paybill'].includes(form.payoutMethod) ? 'numeric' : 'tel'} placeholder={payoutMethod(form.payoutMethod).placeholder} value={form.payoutNumber} onChange={(e) => update({ payoutNumber: e.target.value })} error={errors.payoutNumber} />
        {form.payoutMethod === 'paybill' && (
          <Field label="Paybill account number" value={form.payoutAccount} onChange={(e) => update({ payoutAccount: e.target.value })} error={errors.payoutAccount} />
        )}
      </div>
      <StepNav onBack={onBack} />
    </form>
  )
}

function LinkStep({ form, update, onBack, onNext }) {
  const [check, setCheck] = useState(null)
  const [checking, setChecking] = useState(false)

  // Debounced availability check; the cleanup flag drops stale responses.
  useEffect(() => {
    let current = true
    if (!form.slug) return
    const t = setTimeout(async () => {
      setChecking(true)
      const res = await api.checkSlug(form.slug)
      if (current) {
        setCheck(res)
        setChecking(false)
      }
    }, 300)
    return () => {
      current = false
      clearTimeout(t)
    }
  }, [form.slug])

  const ok = check?.available && check.slug === form.slug && !checking

  return (
    <form onSubmit={(e) => { e.preventDefault(); if (ok) onNext() }} noValidate>
      <StepHeader title="Choose your store link">Short and easy to say over the phone works best. You can add your own domain later on Pro.</StepHeader>
      <Field
        label="Store link"
        value={form.slug}
        onChange={(e) => update({ slug: slugify(e.target.value) })}
        autoCapitalize="none"
        spellCheck={false}
        error={check && !check.available && check.slug === form.slug ? check.reason : undefined}
      />
      <div className="mt-4 flex items-center gap-3 rounded-lg bg-concrete px-4 py-3" aria-live="polite">
        <Link2 aria-hidden className="size-5 shrink-0 text-navy" />
        <span className="min-w-0 flex-1 truncate font-medium">
          <span className="text-muted">{storeLinkParts().before}</span>
          <span className="text-navy">{form.slug || 'yourshop'}</span>
          <span className="text-muted">{storeLinkParts().after}</span>
        </span>
        {checking ? (
          <LoaderCircle className="size-5 animate-spin text-steel" aria-label="Checking" />
        ) : ok ? (
          <span className="flex items-center gap-1 text-sm font-medium text-good"><Check className="size-4" /> Available</span>
        ) : check && check.slug === form.slug ? (
          <span className="flex items-center gap-1 text-sm font-medium text-bad"><X className="size-4" /> Taken</span>
        ) : null}
      </div>
      <StepNav onBack={onBack} disabled={!ok} />
    </form>
  )
}

function PayStep({ form, update, plans, onBack, paid, setPaid, onDetailsProblem }) {
  const navigate = useNavigate()
  const plan = plans.find((p) => p.id === form.planId) ?? plans[0]
  const amount = form.billing === 'yearly' ? plan.priceYearly : plan.priceMonthly
  const [payPhone, setPayPhone] = useState(form.ownerPhone)
  const [payment, setPayment] = useState(null)
  const [done, setDone] = useState(null)
  const [error, setError] = useState(null)

  // Step 1: send the M-PESA prompt for exactly the plan price.
  const [, sendPrompt, sending] = useActionState(async () => {
    setError(null)
    try {
      setPayment(await api.paySubscription({ phone: payPhone, planId: plan.id, period: form.billing, signup: true }))
    } catch (err) {
      setError(err.message)
    }
    return null
  }, null)

  // Step 2: once M-PESA confirms, open the store with that payment.
  const [opening, setOpening] = useState(false)
  async function openStore(confirmed) {
    const receipt = { reference: confirmed.reference ?? payment?.reference, mpesaRef: confirmed.mpesaRef }
    setPaid(receipt)
    setOpening(true)
    try {
      const result = await api.createStore({
        owner: { name: form.ownerName, phone: form.ownerPhone, email: form.ownerEmail.trim(), password: form.ownerPassword },
        business: {
          name: form.shopName, tagline: form.tagline, county: form.county, town: form.town, address: form.address, whatsapp: form.whatsapp,
          payments: { method: form.payoutMethod, number: form.payoutNumber, account: form.payoutAccount },
        },
        planId: plan.id,
        slug: form.slug,
        billing: { period: form.billing, paymentReference: receipt.reference, lastRef: receipt.mpesaRef },
      })
      setSession({ user: result.user, token: result.token })
      setPaid(null)
      setDone({ store: result.store, ref: receipt.mpesaRef })
    } catch (err) {
      setPayment(null)
      setOpening(false)
      if (err.field && (err.field.startsWith('owner') || err.field === 'slug')) return onDetailsProblem(err.field, err.message)
      setError(err.message)
    }
  }

  if (done) {
    return (
      <div className="animate-rise">
        <CircleCheck className="size-12 text-good" aria-hidden />
        <h1 className="mt-4 text-3xl font-bold tracking-tight text-navy">Your store is live</h1>
        <p className="mt-2 text-muted">Payment received (M-PESA ref {done.ref}). Customers can now visit:</p>
        <p className="mt-4 rounded-lg bg-concrete px-4 py-3 font-medium break-all text-navy">{storeHost(done.store.slug)}</p>
        <div className="mt-8 flex flex-wrap gap-3">
          <Button variant="navy" onClick={() => navigate('/dashboard/products/new')}>Add your first product</Button>
          <Button variant="outline" to={`/s/${done.store.slug}`}>View your store</Button>
        </div>
      </div>
    )
  }

  if (paid && !payment) {
    return (
      <div>
        <StepHeader title="Payment received">Your plan payment went through{paid.mpesaRef ? ` (M-PESA ref ${paid.mpesaRef})` : ''}. You won’t be charged again.</StepHeader>
        {error && <p className="mb-4 rounded-lg bg-bad-tint px-4 py-3 text-sm text-bad" role="alert">{error}</p>}
        <div className="flex items-center justify-between gap-3">
          <Button variant="ghost" onClick={onBack} disabled={opening}><ArrowLeft className="size-4" /> Back</Button>
          <Button variant="navy" pending={opening} onClick={() => openStore(paid)}>Open my store</Button>
        </div>
      </div>
    )
  }

  if (payment) {
    return (
      <div>
        <StepHeader title="Approve the payment">Your store opens as soon as M-PESA confirms.</StepHeader>
        <PaymentPrompt
          payment={payment}
          poll={() => api.getSubscriptionPayment(payment.reference)}
          onPaid={openStore}
          onRetry={() => setPayment(null)}
        />
      </div>
    )
  }

  return (
    <form action={sendPrompt}>
      <StepHeader title="Pick a plan">You can switch plans at any time. Your store goes live when the payment comes through.</StepHeader>

      <fieldset>
        <legend className="sr-only">Plan</legend>
        <div className="space-y-3">
          {plans.map((p) => (
            <label
              key={p.id}
              className={cn(
                'flex cursor-pointer items-start gap-4 rounded-card border p-4 transition-colors',
                form.planId === p.id ? 'border-navy bg-mist/40 ring-1 ring-navy' : 'border-line hover:border-steel',
              )}
            >
              <input type="radio" name="plan" value={p.id} checked={form.planId === p.id} onChange={() => update({ planId: p.id })} className="mt-1 size-4 accent-navy" />
              <span className="flex-1">
                <span className="flex flex-wrap items-baseline justify-between gap-2">
                  <span className="font-bold">{p.name}</span>
                  <span className="font-bold tabular">
                    {formatKES(form.billing === 'yearly' ? p.priceYearly : p.priceMonthly)}
                    <span className="text-sm font-normal text-muted"> / {form.billing === 'yearly' ? 'year' : 'month'}</span>
                  </span>
                </span>
                <span className="mt-1 block text-sm text-muted">{p.blurb}</span>
              </span>
            </label>
          ))}
        </div>
      </fieldset>

      <label className="mt-4 flex items-center gap-2 text-sm">
        <input type="checkbox" checked={form.billing === 'yearly'} onChange={(e) => update({ billing: e.target.checked ? 'yearly' : 'monthly' })} className="size-4 accent-navy" />
        Pay yearly and get 2 months free
      </label>

      <div className="mt-8 rounded-card border border-line p-5">
        <div className="flex items-center gap-3">
          <Smartphone aria-hidden className="size-5 text-good" />
          <h2 className="font-bold">Pay {formatKES(amount)} with M-PESA</h2>
        </div>
        <Field className="mt-4" label="M-PESA number to pay from" type="tel" value={payPhone} onChange={(e) => setPayPhone(e.target.value)} error={error} />
        <p className="mt-3 text-sm text-muted">
          You’ll get an M-PESA prompt on <span className="font-medium text-ink">{payPhone || 'this number'}</span> to pay exactly{' '}
          <span className="font-medium text-ink">{formatKES(amount)}</span> to <span className="font-medium text-ink">TOPLINE</span> for the {plan.name} plan ({form.billing}).
        </p>
      </div>

      <div className="mt-10 flex items-center justify-between gap-3">
        <Button variant="ghost" onClick={onBack} disabled={sending}><ArrowLeft className="size-4" /> Back</Button>
        <Button type="submit" pending={sending}>Send M-PESA prompt for {formatKES(amount)}</Button>
      </div>
    </form>
  )
}

export { Onboarding as Component }
