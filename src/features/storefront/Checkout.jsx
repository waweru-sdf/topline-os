import { useState } from 'react'
import { Navigate } from 'react-router'
import { CircleCheck, Smartphone, Store as StoreIcon, Truck } from 'lucide-react'
import { api } from '@/services/api'
import { formatKES } from '@/lib/format'
import { directPaySteps, payoutMethod } from '@/lib/payments'
import { cn } from '@/lib/cn'
import { useCart } from '@/context/CartContext'
import { PaymentPrompt } from '@/components/PaymentPrompt'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { useStore } from './useStore'

/** Fee for the chosen delivery area, matching the server's rule (free over a set amount). */
function deliveryFee(delivery, type, zoneId, subtotal) {
  if (type !== 'delivery' || delivery.mode === 'quote') return 0
  const zone = delivery.zones.find((z) => z.id === zoneId)
  if (!zone) return 0
  return delivery.freeOver && subtotal >= delivery.freeOver ? 0 : zone.fee
}

function Checkout() {
  const { store, base } = useStore()
  const { items, total: subtotal, clear } = useCart()
  const delivery = store.delivery
  const canDeliver = delivery?.enabled && (delivery.mode === 'quote' || delivery.zones?.length)
  const [type, setType] = useState(canDeliver ? 'delivery' : 'pickup')
  const [zoneId, setZoneId] = useState(delivery?.zones?.[0]?.id ?? '')
  const [payMode, setPayMode] = useState('prompt')
  const [order, setOrder] = useState(null) // placed, waiting for the prompt
  const [done, setDone] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  const fee = deliveryFee(delivery ?? { zones: [] }, type, zoneId, subtotal)
  const total = subtotal + fee
  const direct = store.payments?.acceptsDirect

  async function submit(e) {
    e.preventDefault()
    const fd = new FormData(e.currentTarget)
    setBusy(true)
    setError(null)
    try {
      const placed = await api.placeOrder(store.slug, {
        customer: { name: fd.get('name'), phone: fd.get('phone') },
        items: items.map((i) => ({ productId: i.productId, qty: i.qty })),
        delivery: { type, zoneId, address: fd.get('address') || null, notes: fd.get('notes') || null },
        paymentMode: payMode,
        mpesaCode: fd.get('mpesaCode') || null,
      })
      if (placed.payment) {
        setOrder(placed)
      } else {
        clear()
        setDone({ order: placed, direct: true })
      }
    } catch (err) {
      setError(err)
    } finally {
      setBusy(false)
    }
  }

  if (done) return <OrderPlaced store={store} base={base} {...done} />
  if (!items.length && !order) return <Navigate to={`${base}/cart`} replace />

  return (
    <div className="mx-auto grid max-w-5xl gap-8 px-4 py-8 sm:px-5 md:grid-cols-[minmax(0,1fr)_20rem] md:py-12">
      <title>{`Checkout — ${store.name}`}</title>

      {order ? (
        <div className="space-y-6">
          <h1 className="text-3xl font-bold tracking-tight text-navy">Approve the payment</h1>
          <PaymentPrompt
            payment={order.payment}
            poll={() => api.getOrderPayment(store.slug, order.id)}
            onPaid={(result) => { clear(); setDone({ order: result.order }) }}
            onRetry={() => setOrder(null)}
            retryLabel="Back to checkout"
          />
        </div>
      ) : (
        <form onSubmit={submit} className="space-y-6">
          <h1 className="text-3xl font-bold tracking-tight text-navy">Checkout</h1>

          <fieldset className="space-y-4 rounded-card bg-white p-5 ring-1 ring-line">
            <legend className="sr-only">Your details</legend>
            <Field name="name" label="Your name" autoComplete="name" required />
            <Field name="phone" label="Your M-PESA number" type="tel" inputMode="tel" autoComplete="tel" placeholder="0712 345 678" required
                   error={error?.field === 'phone' ? error.message : undefined} />
          </fieldset>

          <fieldset className="rounded-card bg-white p-5 ring-1 ring-line">
            <legend className="float-left mb-3 font-bold">How do you want it?</legend>
            <div className="clear-both grid gap-3 sm:grid-cols-2">
              <Choice checked={type === 'pickup'} onChange={() => setType('pickup')} name="type" icon={StoreIcon} title="Pick up from the shop" note={`Free. ${store.address || store.town}`} />
              {canDeliver && (
                <Choice checked={type === 'delivery'} onChange={() => setType('delivery')} name="type" icon={Truck} title="Deliver to my site"
                        note={delivery.mode === 'quote' ? 'Cost agreed with the shop by phone' : `From ${formatKES(Math.min(...delivery.zones.map((z) => z.fee)))}`} />
              )}
            </div>
            {type === 'delivery' && (
              <div className="mt-4 space-y-4">
                {delivery.mode === 'zones' ? (
                  <Field as="select" label="Delivery area" value={zoneId} onChange={(e) => setZoneId(e.target.value)} error={error?.field === 'zoneId' ? error.message : undefined}
                         hint={delivery.freeOver ? `Free delivery on orders over ${formatKES(delivery.freeOver)}.` : undefined}>
                    {delivery.zones.map((z) => <option key={z.id} value={z.id}>{z.name}: {formatKES(z.fee)}</option>)}
                  </Field>
                ) : (
                  <p className="rounded-lg bg-concrete px-4 py-3 text-sm text-muted">
                    {store.name} will call you to agree the delivery cost for your order and location. You pay it on delivery.
                  </p>
                )}
                <Field name="address" label="Site location" placeholder="Estate, road and a landmark" required error={error?.field === 'address' ? error.message : undefined} />
                <Field name="notes" as="textarea" label="Notes for the driver (optional)" placeholder="Call when at the gate" />
              </div>
            )}
          </fieldset>

          <fieldset className="rounded-card bg-white p-5 ring-1 ring-line">
            <legend className="float-left mb-3 font-bold">How do you want to pay?</legend>
            <div className="clear-both grid gap-3 sm:grid-cols-2">
              <Choice checked={payMode === 'prompt'} onChange={() => setPayMode('prompt')} name="pay" icon={Smartphone} title="M-PESA prompt"
                      note={`We send a prompt for exactly ${formatKES(total)} to your phone.`} />
              {direct && (
                <Choice checked={payMode === 'direct'} onChange={() => setPayMode('direct')} name="pay" icon={StoreIcon}
                        title={`Pay the shop’s ${payoutMethod(store.payments.method).short}`} note="Pay from your M-PESA menu, then enter the code." />
              )}
            </div>
            {payMode === 'direct' && direct && (
              <div className="mt-4 rounded-lg bg-concrete p-4">
                <p className="text-sm font-medium">Pay {formatKES(total)} to {store.payments.name}:</p>
                <ol className="mt-2 list-decimal space-y-1 pl-5 text-sm">
                  {directPaySteps(store.payments, total).map((step) => <li key={step}>{step}</li>)}
                </ol>
                <Field className="mt-4" name="mpesaCode" label="M-PESA code from your confirmation SMS" placeholder="e.g. TJK4H2PQ9X" required autoCapitalize="characters"
                       error={error?.field === 'mpesaCode' ? error.message : undefined} hint="The shop checks the payment before preparing your order." />
              </div>
            )}
          </fieldset>

          {error && !['phone', 'zoneId', 'address', 'mpesaCode'].includes(error.field) && (
            <p className="rounded-lg bg-bad-tint px-4 py-3 text-sm text-bad" role="alert">{error.message}</p>
          )}
          <Button type="submit" size="lg" className="w-full" pending={busy}>
            {payMode === 'prompt' ? `Pay ${formatKES(total)} with M-PESA` : 'Place order'}
          </Button>
        </form>
      )}

      <aside className="h-fit rounded-card bg-white p-5 ring-1 ring-line md:sticky md:top-24">
        <h2 className="font-bold">Order summary</h2>
        <ul className="mt-4 space-y-3 text-sm">
          {(order?.items ?? items).map((i) => (
            <li key={i.productId} className="flex justify-between gap-3">
              <span>{i.qty} × {i.name}</span>
              <span className="tabular">{formatKES(i.qty * i.price)}</span>
            </li>
          ))}
        </ul>
        <dl className="mt-4 space-y-1.5 border-t border-line pt-4 text-sm">
          <div className="flex justify-between"><dt className="text-muted">Items</dt><dd className="tabular">{formatKES(order?.subtotal ?? subtotal)}</dd></div>
          <div className="flex justify-between">
            <dt className="text-muted">Delivery</dt>
            <dd className="tabular">{type === 'pickup' ? 'Free (pickup)' : delivery?.mode === 'quote' ? 'Agreed later' : formatKES(order?.deliveryFee ?? fee)}</dd>
          </div>
          <div className="flex justify-between pt-1.5 text-base font-bold"><dt>Total</dt><dd className="tabular">{formatKES(order?.total ?? total)}</dd></div>
        </dl>
      </aside>
    </div>
  )
}

function Choice({ checked, onChange, name, icon: Icon, title, note }) {
  return (
    <label className={cn('flex cursor-pointer gap-3 rounded-lg border p-3', checked ? 'border-navy ring-1 ring-navy' : 'border-line hover:border-steel')}>
      <input type="radio" name={name} checked={checked} onChange={onChange} className="mt-1 accent-navy" />
      <span>
        <span className="flex items-center gap-1.5 font-medium"><Icon className="size-4 text-steel" aria-hidden />{title}</span>
        <span className="block text-sm text-muted">{note}</span>
      </span>
    </label>
  )
}

function OrderPlaced({ store, base, order, direct }) {
  return (
    <div className="mx-auto max-w-xl px-5 py-16">
      <title>{`Order placed — ${store.name}`}</title>
      <CircleCheck className="size-12 text-good" aria-hidden />
      <h1 className="mt-4 text-3xl font-bold tracking-tight text-navy">{direct ? 'Order sent to the shop' : 'Order placed'}</h1>
      <p className="mt-2 text-muted">
        {direct
          ? `${store.name} will check your M-PESA payment and call you to confirm order ${order.id}.`
          : `${store.name} has your order ${order.id} and will call you to confirm ${order.delivery?.type === 'delivery' ? 'delivery' : 'when it’s ready to pick up'}.`}
      </p>
      <dl className="mt-6 grid grid-cols-2 gap-y-2 rounded-card bg-white p-5 text-sm ring-1 ring-line">
        <dt className="text-muted">Items</dt><dd className="text-right tabular">{formatKES(order.subtotal)}</dd>
        <dt className="text-muted">Delivery</dt><dd className="text-right tabular">{order.delivery?.type === 'delivery' ? (order.delivery.zone === 'Agreed with the shop' ? 'Agreed by phone' : formatKES(order.deliveryFee)) : 'Pickup'}</dd>
        <dt className="font-bold">{direct ? 'To pay' : 'Paid'}</dt><dd className="text-right font-bold tabular">{formatKES(order.total)}</dd>
        <dt className="text-muted">M-PESA ref</dt><dd className="text-right font-medium">{order.mpesaRef}</dd>
      </dl>
      <Button to={base || '/'} variant="navy" className="mt-8">Back to the store</Button>
    </div>
  )
}

export { Checkout as Component }
