import { useActionState } from 'react'
import { CircleCheck } from 'lucide-react'
import { api } from '@/services/api'
import { Dialog } from '@/components/ui/Dialog'
import { Field } from '@/components/ui/Field'
import { Button } from '@/components/ui/Button'

export function QuoteDialog({ open, onClose, store, initialItems = '' }) {
  const [state, submit, pending] = useActionState(async (_p, fd) => {
    try {
      return await api.requestQuote(store.slug, Object.fromEntries(fd))
    } catch (e) {
      return { error: e.message, field: e.field }
    }
  }, null)

  return (
    <Dialog open={open} onClose={onClose} title={state?.ok ? 'Quote request sent' : `Request a quote from ${store.name}`}>
      {state?.ok ? (
        <div>
          <CircleCheck className="size-10 text-good" aria-hidden />
          <p className="mt-3 text-muted">
            {store.name} will reply on WhatsApp or by phone. Your reference is <span className="font-medium text-ink">{state.reference}</span>.
          </p>
          <Button variant="navy" className="mt-6" onClick={onClose}>Done</Button>
        </div>
      ) : (
        <form action={submit} className="space-y-4">
          <p className="text-sm text-muted">For big or site orders. List what you need and the shop will send you a price.</p>
          <Field name="name" label="Your name" required autoComplete="name" />
          <Field name="phone" label="Phone or WhatsApp" type="tel" required autoComplete="tel" error={state?.field === 'phone' ? state.error : undefined} />
          <Field name="items" as="textarea" label="What do you need?" placeholder={'e.g. 60 bags cement 32.5\n40 lengths Y12\n120 m box profile'} defaultValue={initialItems} required />
          <Field name="site" label="Site location (optional)" placeholder="Kitengela, near EPZ" />
          {state?.error && state.field !== 'phone' && <p className="text-sm text-bad">{state.error}</p>}
          <Button type="submit" variant="navy" className="w-full" pending={pending}>Send quote request</Button>
        </form>
      )}
    </Dialog>
  )
}
