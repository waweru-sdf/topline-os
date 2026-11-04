import { formatKES } from '@/lib/format'

/** A priced quotation as WhatsApp text. */
export function quotationText(store, q) {
  const lines = q.lines.map((l) => `${l.qty} × ${l.name} @ ${formatKES(l.price)} = ${formatKES(l.qty * l.price)}`).join('\n')
  return `${store.name}\nQuotation ${q.reference} for ${q.name}\n\n${lines}\n\nTotal: ${formatKES(q.total)}\nPrices valid while stock lasts.`
}

/** wa.me wants 2547…; quotes store 2547… or 07…. */
export const waNumber = (phone) => (phone ? phone.replace(/\D/g, '').replace(/^0/, '254') : '')
