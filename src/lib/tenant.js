/**
 * Works out which store (tenant) a request is for.
 *
 * Production: mjengo.toplinehub.co.ke  -> "mjengo"
 * Local dev:  mjengo.localhost:5173     -> "mjengo"
 * Fallback:   toplinehub.co.ke/s/mjengo (handled by the router, not here)
 *
 * The backend will do the same lookup server-side (and for custom domains);
 * the frontend only needs it to pick which route tree to mount.
 */
const ROOT_DOMAIN = import.meta.env.VITE_ROOT_DOMAIN || 'toplinehub.co.ke'
const RESERVED = new Set(['www', 'app', 'admin', 'api', 'mail', 'static'])

/**
 * How shop links look to people:
 *  "subdomain" (default, needs a wildcard-capable server): mjengo.toplinehub.co.ke
 *  "path" (shared hosting): toplinehub.co.ke/s/mjengo
 */
const STORE_LINKS = import.meta.env.VITE_STORE_LINKS === 'path' ? 'path' : 'subdomain'

export function getTenantSlugFromHost(hostname = window.location.hostname) {
  const host = hostname.toLowerCase()
  let sub = null

  if (host.endsWith('.localhost')) sub = host.slice(0, -'.localhost'.length)
  // With path links, any subdomain (a test site such as new.toplinehub.co.ke) is the main site.
  else if (STORE_LINKS === 'subdomain' && host.endsWith(`.${ROOT_DOMAIN}`)) sub = host.slice(0, -(ROOT_DOMAIN.length + 1))

  if (!sub || sub.includes('.') || RESERVED.has(sub)) return null
  return sub
}

/** Public link for a store without https://, as shown to vendors and their customers. */
export const storeHost = (slug) => (STORE_LINKS === 'path' ? `${ROOT_DOMAIN}/s/${slug}` : `${slug}.${ROOT_DOMAIN}`)

/** Full link to share (WhatsApp, posters, copy button). */
export const storeUrl = (slug) => `https://${storeHost(slug)}`

/** The fixed text around the shop name when a link is being typed: before + name + after. */
export const storeLinkParts = () =>
  STORE_LINKS === 'path' ? { before: `${ROOT_DOMAIN}/s/`, after: '' } : { before: '', after: `.${ROOT_DOMAIN}` }

/**
 * In-app path to a store. When we're already on the store's subdomain the
 * store lives at "/", otherwise at "/s/<slug>".
 */
export function storePath(slug, path = '') {
  const onSubdomain = getTenantSlugFromHost() === slug
  const base = onSubdomain ? '' : `/s/${slug}`
  return `${base}${path}` || '/'
}

export { ROOT_DOMAIN, STORE_LINKS }
