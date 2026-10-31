import { useEffect, useEffectEvent, useState } from 'react'
import { CircleAlert, LoaderCircle, Smartphone } from 'lucide-react'
import { api } from '@/services/api'
import { displayPhone, formatKES } from '@/lib/format'
import { Button } from '@/components/ui/Button'

const POLL_MS = 2000

/**
 * "Check your phone" screen for any M-PESA prompt (plans and orders).
 * Shows the exact amount and who is being paid, polls until the payment
 * settles, and in simulation shows a pretend phone to answer the prompt.
 *
 * payment: { reference, amount, payee, phone, simulated }
 * poll:    () => Promise<{ status: 'pending'|'paid'|'failed', failureReason? }>
 */
export function PaymentPrompt({ payment, poll, onPaid, onRetry, retryLabel = 'Try again' }) {
  const [failure, setFailure] = useState(null)
  // Latest callbacks without restarting the polling loop when the parent re-renders.
  const pollNow = useEffectEvent(() => poll())
  const paid = useEffectEvent((result) => onPaid(result))

  useEffect(() => {
    let active = true
    let timer
    async function check() {
      try {
        const result = await pollNow()
        if (!active) return
        if (result.status === 'paid') return paid(result)
        if (result.status === 'failed') return setFailure(result.failureReason || 'The payment didn’t go through.')
      } catch (err) {
        if (!active) return
        if (err.status === 402) return setFailure(err.message)
      }
      timer = setTimeout(check, POLL_MS)
    }
    timer = setTimeout(check, 800)
    return () => {
      active = false
      clearTimeout(timer)
    }
  }, [payment.reference])

  if (failure) {
    return (
      <div className="rounded-card border border-bad/30 bg-bad-tint p-5" role="alert">
        <p className="flex items-center gap-2 font-bold text-bad"><CircleAlert className="size-5" /> Payment not completed</p>
        <p className="mt-1 text-sm text-ink">{failure}</p>
        {onRetry && <Button variant="navy" className="mt-4" onClick={onRetry}>{retryLabel}</Button>}
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex items-start gap-3 rounded-card bg-amber-tint p-5" role="status">
        <Smartphone className="mt-0.5 size-6 shrink-0 text-[#7a4f00]" aria-hidden />
        <div className="flex-1">
          <p className="font-bold text-[#5c3b00]">Check your phone {displayPhone(payment.phone)}</p>
          <p className="text-sm text-[#7a4f00]">
            M-PESA is asking you to pay <span className="font-bold">{formatKES(payment.amount)}</span> to{' '}
            <span className="font-bold">{payment.payee}</span>. Enter your M-PESA PIN to approve.
          </p>
        </div>
        <LoaderCircle className="size-5 shrink-0 animate-spin text-[#7a4f00]" aria-label="Waiting" />
      </div>
      {payment.simulated && <SimulatedPhone payment={payment} />}
    </div>
  )
}

/** Stands in for the customer's phone while PAYMENTS_MODE=simulation. */
function SimulatedPhone({ payment }) {
  const [pin, setPin] = useState('')
  const [sent, setSent] = useState(null)
  const [error, setError] = useState(null)

  async function answer(action) {
    setError(null)
    try {
      await api.answerSimulatedPrompt(payment.reference, action, pin)
      setSent(action)
    } catch (err) {
      setError(err.message)
    }
  }

  return (
    <div className="mx-auto w-full max-w-[17rem] rounded-[2rem] border-[6px] border-ink bg-ink p-1 shadow-lift">
      <div className="rounded-[1.5rem] bg-[#1d1d1f] px-4 pt-3 pb-5 text-white">
        <p className="text-center text-[0.65rem] tracking-wide text-white/50">Test phone (simulation)</p>
        {sent ? (
          <p className="mt-6 mb-4 text-center text-sm">{sent === 'approve' ? 'PIN sent. Waiting for M-PESA…' : 'Cancelled.'}</p>
        ) : (
          <div className="mt-3 rounded-xl bg-white p-4 text-ink">
            <p className="text-sm leading-snug">
              Do you want to pay <span className="font-bold">Ksh{payment.amount.toLocaleString('en-KE')}.00</span> to{' '}
              <span className="font-bold">{payment.payee}</span>?
            </p>
            <label className="mt-3 block text-xs text-muted" htmlFor={`pin-${payment.reference}`}>Enter M-PESA PIN (any 4 digits)</label>
            <input
              id={`pin-${payment.reference}`}
              type="password"
              inputMode="numeric"
              maxLength={4}
              value={pin}
              onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))}
              className="mt-1 h-10 w-full rounded-md border border-line px-3 text-center text-lg tracking-[0.5em] focus:border-navy focus:outline-none"
            />
            {error && <p className="mt-2 text-xs text-bad">{error}</p>}
            <div className="mt-3 grid grid-cols-2 gap-2">
              <button type="button" onClick={() => answer('decline')} className="h-9 rounded-md text-sm text-muted ring-1 ring-line hover:bg-concrete">Cancel</button>
              <button type="button" onClick={() => answer('approve')} disabled={pin.length !== 4} className="h-9 rounded-md bg-good text-sm font-medium text-white disabled:opacity-50">Send</button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
