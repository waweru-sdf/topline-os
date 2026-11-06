import { Smartphone, Store } from 'lucide-react'
import { formatKES } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Field } from '@/components/ui/Field'

/**
 * How to pay for a plan: an M-PESA prompt (when Topline can send one) or paying Topline's Till
 * from the M-PESA menu and typing the confirmation code. Parent owns the phone, code and submit button.
 */
export function TillPay({ options, amount, how, setHow, phone, setPhone, code, setCode, error }) {
  const ways = [
    options.prompt && ['prompt', 'Send me a prompt', Smartphone],
    ['till', `Pay Till ${options.till}`, Store],
  ].filter(Boolean)
  const err = (f) => (error?.field === f ? error.message : undefined)
  const amt = formatKES(amount)

  return (
    <div className="rounded-card border border-line p-5">
      {ways.length > 1 && (
        <div role="radiogroup" aria-label="How to pay" className="mb-4 grid grid-cols-2 gap-1 rounded-lg bg-concrete p-1">
          {ways.map(([id, label, Icon]) => (
            <button key={id} type="button" role="radio" aria-checked={how === id} onClick={() => setHow(id)}
              className={cn('flex items-center justify-center gap-1.5 rounded-md px-3 py-2 text-sm', how === id ? 'bg-white font-medium text-navy shadow-sm' : 'text-muted')}>
              <Icon className="size-4" />{label}
            </button>
          ))}
        </div>
      )}

      {how === 'prompt' ? (
        <>
          <Field label="M-PESA number to pay from" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} error={err('phone')} />
          <p className="mt-3 text-sm text-muted">
            You’ll get an M-PESA prompt to pay exactly <span className="font-medium text-ink">{amt}</span> to{' '}
            <span className="font-medium text-ink">{options.tillName}</span> (Till {options.till}). Enter your PIN to approve.
          </p>
        </>
      ) : (
        <>
          <ol className="space-y-1.5 rounded-lg bg-concrete px-4 py-3 text-sm">
            {['Open M-PESA, then Lipa na M-PESA', 'Buy Goods and Services', `Till number: ${options.till} (${options.tillName})`, `Amount: ${amt}`, 'Enter your PIN and send'].map((s, i) => (
              <li key={s} className="flex gap-2"><span className="font-bold text-navy tabular">{i + 1}.</span><span className={i > 1 && i < 4 ? 'font-medium' : ''}>{s}</span></li>
            ))}
          </ol>
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <Field label="M-PESA code from the SMS" value={code} onChange={(e) => setCode(e.target.value.toUpperCase().replace(/\s/g, ''))}
              placeholder="e.g. SKL4ABC12Q" maxLength={10} autoCapitalize="characters" spellCheck={false} error={err('mpesaCode')} />
            <Field label="Number you paid from" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} error={err('phone')} />
          </div>
          <p className="mt-3 text-sm text-muted">Topline checks the code against the till, usually within a few hours, and you get an SMS once it’s confirmed.</p>
        </>
      )}
      {error && !['phone', 'mpesaCode'].includes(error.field) && <p className="mt-3 text-sm text-bad" role="alert">{error.message}</p>}
    </div>
  )
}

/** The latest code sent to Topline: being checked, or turned down with a reason. */
export function TillCodeStatus({ tillCode, className }) {
  if (!tillCode) return null
  const checking = tillCode.status === 'checking'
  return (
    <div role="status" className={cn('rounded-lg px-4 py-3 text-sm', checking ? 'bg-amber-tint text-[#7a4f00]' : 'bg-bad-tint text-bad', className)}>
      {checking
        ? <>Topline is checking your M-PESA code <span className="font-bold">{tillCode.code}</span> ({formatKES(tillCode.amount)}). You’ll get an SMS once it’s confirmed.</>
        : <>Your M-PESA code <span className="font-bold">{tillCode.code}</span> wasn’t confirmed. {tillCode.reason} Check the code and send it again, or call Topline.</>}
    </div>
  )
}
