import { useId, useRef, useState } from 'react'
import { ImagePlus, LoaderCircle, Trash2 } from 'lucide-react'
import { api } from '@/services/api'
import { cn } from '@/lib/cn'
import { shrinkImage } from '@/lib/shrinkImage'
import { Img } from '@/components/ui/Img'

/**
 * Upload a photo and get back its URL. `value` is a URL or a stock-photo key.
 * storeId: upload into a shop (shop staff); omit for Topline site images (admins).
 */
export function PhotoUpload({ value, onChange, storeId, label = 'Photo', aspect = 'aspect-[4/3]', className }) {
  const id = useId()
  const input = useRef(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function pick(e) {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    if (file.size > 25 * 1024 * 1024) return setError('That file is too big. Use a photo under 25 MB.')
    setBusy(true)
    setError(null)
    try {
      const small = await shrinkImage(file)
      if (small.size > 5 * 1024 * 1024) throw new Error('That photo is too big. Use one under 5 MB.')
      const { url } = await api.uploadPhoto(small, storeId)
      onChange(url)
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className={className}>
      <span className="text-sm font-medium" id={`${id}-label`}>{label}</span>
      <div className={cn('relative mt-1.5 overflow-hidden rounded-lg border border-dashed border-steel/60 bg-concrete', aspect)}>
        {value ? (
          <Img key={value} name={value} alt="" w={600} className="size-full" />
        ) : (
          <div className="flex size-full flex-col items-center justify-center gap-1 text-sm text-muted">
            <ImagePlus className="size-7 text-steel" aria-hidden /> No photo yet
          </div>
        )}
        {busy && (
          <div className="absolute inset-0 flex items-center justify-center bg-white/70">
            <LoaderCircle className="size-6 animate-spin text-navy" aria-label="Uploading" />
          </div>
        )}
      </div>
      <div className="mt-2 flex gap-2">
        <button type="button" onClick={() => input.current?.click()} className="inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-navy ring-1 ring-line hover:bg-concrete" aria-describedby={`${id}-label`}>
          <ImagePlus className="size-4" /> {value ? 'Change photo' : 'Upload photo'}
        </button>
        {value && (
          <button type="button" onClick={() => onChange('')} className="inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-sm text-muted hover:text-bad">
            <Trash2 className="size-4" /> Remove
          </button>
        )}
      </div>
      <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" capture="environment" className="hidden" onChange={pick} />
      <p className="mt-1 text-xs text-muted">JPG, PNG or WebP. On a phone you can take a picture directly.</p>
      {error && <p className="mt-1 text-sm text-bad" role="alert">{error}</p>}
    </div>
  )
}
