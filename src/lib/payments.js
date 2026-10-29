/** How a shop receives money, as shown to customers and owners. */
export const PAYOUT_METHODS = [
  { id: 'till', label: 'Till (Buy Goods)', short: 'Till', numberLabel: 'Till number', placeholder: '5123456' },
  { id: 'paybill', label: 'Paybill', short: 'Paybill', numberLabel: 'Paybill (business) number', placeholder: '247247' },
  { id: 'pochi', label: 'Pochi la Biashara', short: 'Pochi la Biashara', numberLabel: 'Pochi phone number', placeholder: '0712 345 678' },
  { id: 'send_money', label: 'Send Money (personal M-PESA)', short: 'Send Money', numberLabel: 'M-PESA phone number', placeholder: '0712 345 678' },
]

export const payoutMethod = (id) => PAYOUT_METHODS.find((m) => m.id === id) ?? PAYOUT_METHODS[0]

/** Step-by-step M-PESA menu instructions for paying a shop directly. */
export function directPaySteps(payments, amount) {
  const amt = `KSh ${amount.toLocaleString('en-KE')}`
  switch (payments.method) {
    case 'paybill':
      return ['Go to M-PESA, then Lipa na M-PESA, then Pay Bill', `Business number: ${payments.number}`, `Account number: ${payments.account}`, `Amount: ${amt}`]
    case 'pochi':
      return ['Go to M-PESA, then Lipa na M-PESA, then Pochi la Biashara', `Phone number: ${payments.number}`, `Amount: ${amt}`]
    case 'send_money':
      return ['Go to M-PESA, then Send Money', `Phone number: ${payments.number}`, `Amount: ${amt}`]
    default:
      return ['Go to M-PESA, then Lipa na M-PESA, then Buy Goods and Services', `Till number: ${payments.number}`, `Amount: ${amt}`]
  }
}

/** One line for receipts and reminders, e.g. "Paybill 247247, account 0733444555". */
export function payToLine(payments) {
  if (!payments?.number) return ''
  if (payments.method === 'paybill') return `Paybill ${payments.number}, account ${payments.account}`
  return `${payoutMethod(payments.method).short} ${payments.number}`
}
