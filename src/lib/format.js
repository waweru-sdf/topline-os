const kes = new Intl.NumberFormat('en-KE', {
  style: 'currency',
  currency: 'KES',
  maximumFractionDigits: 0,
})

/** 1540 -> "KSh 1,540" */
export const formatKES = (amount) => kes.format(amount ?? 0).replace('Ksh', 'KSh')

const compact = new Intl.NumberFormat('en-KE', { notation: 'compact', maximumFractionDigits: 1 })
export const formatCompact = (n) => compact.format(n ?? 0)

const dateFmt = new Intl.DateTimeFormat('en-KE', { day: 'numeric', month: 'short', year: 'numeric' })
const timeFmt = new Intl.DateTimeFormat('en-KE', { hour: 'numeric', minute: '2-digit' })

export const formatDate = (iso) => dateFmt.format(new Date(iso))
export const formatTime = (iso) => timeFmt.format(new Date(iso))

/** "Mjengo Hardware & Supplies" -> "mjengo-hardware-supplies" */
export function slugify(text) {
  return text
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/&/g, ' ')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40)
}

/** Kenyan phone -> 2547XXXXXXXX (the format M-PESA STK push expects). */
export function normalizePhone(input) {
  const digits = String(input).replace(/\D/g, '')
  if (/^0[17]\d{8}$/.test(digits)) return `254${digits.slice(1)}`
  if (/^254[17]\d{8}$/.test(digits)) return digits
  if (/^[17]\d{8}$/.test(digits)) return `254${digits}`
  return null
}

export const pluralize = (n, one, many = `${one}s`) => `${n} ${n === 1 ? one : many}`

/** '254712345678' -> '0712 345 678' */
export function displayPhone(normalized) {
  const d = String(normalized ?? '').replace(/\D/g, '')
  if (d.length !== 12 || !d.startsWith('254')) return normalized ?? ''
  const local = `0${d.slice(3)}`
  return `${local.slice(0, 4)} ${local.slice(4, 7)} ${local.slice(7)}`
}
