import { useState } from 'react'
import { Eye, EyeOff } from 'lucide-react'
import { Field } from './Field'

/** A password box with a Show/Hide button (handy on phones, where typos are common). */
export function PasswordField({ className, ...props }) {
  const [shown, setShown] = useState(false)
  return (
    <div className={`relative ${className ?? ''}`}>
      <Field type={shown ? 'text' : 'password'} autoCapitalize="none" autoCorrect="off" spellCheck={false} {...props} />
      <button
        type="button"
        onClick={() => setShown((s) => !s)}
        className="absolute top-[1.85rem] right-1 flex h-9 items-center gap-1 rounded-md px-2.5 text-sm text-muted hover:text-ink"
        aria-label={shown ? 'Hide password' : 'Show password'}
      >
        {shown ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
        <span className="hidden sm:inline">{shown ? 'Hide' : 'Show'}</span>
      </button>
    </div>
  )
}
