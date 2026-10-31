import { useState } from 'react'
import { ImageOff } from 'lucide-react'
import { photo, photoSrcSet } from '@/lib/images'
import { cn } from '@/lib/cn'

/**
 * Image from an upload URL or an Unsplash stock-photo key, with lazy loading
 * and a calm fallback if it can't load (common on patchy shop connections).
 */
export function Img({ name, alt, w = 800, h, sizes = '100vw', className, priority = false, ...props }) {
  const [failed, setFailed] = useState(false)
  const isUrl = typeof name === 'string' && /^(https?:)?\/\//.test(name)
  if (failed || !name) {
    return (
      <div role="img" aria-label={alt} className={cn('flex items-center justify-center bg-concrete text-steel', className)}>
        <ImageOff aria-hidden className="size-6" />
      </div>
    )
  }
  if (isUrl) {
    // An uploaded photo: served as-is.
    return (
      <img
        src={name}
        alt={alt}
        loading={priority ? 'eager' : 'lazy'}
        decoding="async"
        onError={() => setFailed(true)}
        className={cn('bg-concrete object-cover', className)}
        {...props}
      />
    )
  }
  return (
    <img
      src={photo(name, { w, h })}
      srcSet={photoSrcSet(name, [Math.round(w / 2), w, Math.round(w * 1.5)], h ? Math.round(h * 1.5) : undefined)}
      sizes={sizes}
      alt={alt}
      loading={priority ? 'eager' : 'lazy'}
      fetchPriority={priority ? 'high' : undefined}
      decoding="async"
      onError={() => setFailed(true)}
      className={cn('bg-concrete object-cover', className)}
      {...props}
    />
  )
}
