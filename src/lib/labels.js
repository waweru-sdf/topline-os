export const METHOD_LABEL = { mpesa: 'M-PESA', cash: 'Cash', bank: 'Bank', credit: 'On credit' }

export const SUPPLIER_ORDER_STATUS = {
  new: ['Sent', 'amber'],
  contacted: ['Supplier in touch', 'navy'],
  completed: ['Completed', 'good'],
  declined: ['Declined', 'bad'],
  cancelled: ['Cancelled', 'neutral'],
}

export const CREDIT_STATUS = {
  new: ['Received', 'amber'],
  reviewing: ['Being reviewed', 'navy'],
  approved: ['Approved', 'good'],
  declined: ['Not approved', 'bad'],
  closed: ['Closed', 'neutral'],
}

export const CREDIT_PURPOSE = {
  stock: 'Buy stock',
  supplier_order: 'Pay a supplier order',
  expansion: 'Grow the shop (space, transport, equipment)',
  other: 'Something else',
}

export const EXPENSE_CATEGORY = {
  rent: 'Rent',
  salaries: 'Salaries & wages',
  transport: 'Transport & fuel',
  utilities: 'Electricity, water & internet',
  supplies: 'Shop supplies',
  marketing: 'Marketing',
  licences: 'Licences & permits',
  repairs: 'Repairs',
  taxes: 'Taxes & fees',
  other: 'Other',
}

/** The last `count` months as [{ value: 'YYYY-MM', label: 'November 2026' }], newest first. */
export function recentMonths(count = 12) {
  const now = new Date()
  return Array.from({ length: count }, (_, i) => {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1)
    const value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
    return { value, label: d.toLocaleDateString('en-KE', { month: 'long', year: 'numeric' }) }
  })
}

export const monthLabel = (value, style = 'long') => {
  const [y, m] = value.split('-').map(Number)
  return new Date(y, m - 1, 1).toLocaleDateString('en-KE', { month: style, year: style === 'long' ? 'numeric' : undefined })
}

export const SUPPLIER_STATUS = {
  pending: ['Not approved yet', 'neutral'],
  approved: ['Listed', 'good'],
  hidden: ['Hidden', 'bad'],
}
