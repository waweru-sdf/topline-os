// Mirrors Passwords::checkNew on the server, so people see what's missing while they type.
const COMMON = new Set(['password', 'password1', 'password123', 'passw0rd', 'qwerty123', 'qwertyuiop', 'abc12345', 'abcd1234',
  'asdf1234', 'admin123', 'admin1234', 'welcome1', 'welcome123', 'iloveyou1', 'kenya254', 'kenya123', 'nairobi1', 'nairobi123',
  'mpesa123', 'topline1', 'topline12', 'toplinehub', 'letmein1', 'changeme1', 'football1', 'monkey123', 'jesus123', 'god12345',
  'a1b2c3d4', '1q2w3e4r', '1qaz2wsx', 'zaq12wsx', 'q1w2e3r4', 'hardware1', 'mabati123', 'cement123'])

/** [{label, ok}] for the checklist under a new-password box. */
export function passwordChecks(password, phone = '') {
  const plain = password.replace(/\s+/g, '').toLowerCase()
  const local = phone.replace(/\D/g, '').slice(-9)
  const guessable = COMMON.has(plain) || new Set(plain).size < 4 || (local.length === 9 && password.replace(/\D/g, '').includes(local))
  return [
    { label: 'At least 8 characters', ok: password.length >= 8 },
    { label: 'Letters and numbers', ok: /\p{L}/u.test(password) && /\d/.test(password) },
    { label: 'Not easy to guess (not your phone number or a common password)', ok: password.length > 0 && !guessable },
  ]
}

/** First rule the password breaks, as a sentence, or null. */
export function passwordProblem(password, phone) {
  const miss = passwordChecks(password, phone).find((c) => !c.ok)
  if (!miss) return null
  if (miss.label.startsWith('At least')) return 'Use at least 8 characters.'
  if (miss.label.startsWith('Letters')) return 'Use both letters and numbers, e.g. duka-yangu-24.'
  return 'That password is too easy to guess. Mix words and numbers that aren’t your phone number.'
}
