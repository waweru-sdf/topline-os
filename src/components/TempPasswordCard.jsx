import { useState } from 'react'
import { Copy } from 'lucide-react'

/** A one-time temporary password, with copy and "send on WhatsApp". */
export function TempPasswordCard({ name, login, password, intro }) {
  const [copied, setCopied] = useState(false)
  async function copy() {
    try {
      await navigator.clipboard.writeText(password)
      setCopied(true)
    } catch {
      setCopied(false)
    }
  }
  const digits = String(login ?? '').replace(/\D/g, '').replace(/^0/, '254')
  const message = `Hi ${name}, your Topline login is ${login} and your temporary password is ${password}. Log in at ${window.location.origin}/login and you'll be asked to choose your own password.`
  return (
    <div className="text-left">
      <p className="text-sm text-muted">{intro ?? `Give this to ${name}. It’s shown only once.`}</p>
      <div className="mt-4 flex items-center justify-between gap-3 rounded-lg bg-concrete px-4 py-3">
        <code className="text-xl font-bold tracking-wider text-navy">{password}</code>
        <button type="button" onClick={copy} className="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium text-navy hover:bg-white">
          <Copy className="size-4" /> {copied ? 'Copied' : 'Copy'}
        </button>
      </div>
      <p className="mt-3 text-sm text-muted">They log in with <strong>{login}</strong> and must choose their own password first.</p>
      {digits.length === 12 && (
        <a href={`https://wa.me/${digits}?text=${encodeURIComponent(message)}`} target="_blank" rel="noreferrer" className="mt-4 inline-flex items-center gap-2 rounded-lg bg-good px-4 py-2.5 text-sm font-medium text-white hover:opacity-90">
          Send it on WhatsApp
        </a>
      )}
    </div>
  )
}
