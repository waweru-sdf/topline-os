/**
 * The only module UI code talks to for data: every call to the Flask API
 * (backend/) lives here. Set VITE_API_URL in .env; defaults to the local backend.
 */
import { clearSession, getToken } from './session'

const BASE = (import.meta.env.VITE_API_URL || 'http://localhost:5000/api').replace(/\/$/, '')

export class ApiError extends Error {
  constructor(message, status = 400, field) {
    super(message)
    this.status = status
    this.field = field
  }
}

async function http(method, path, body, { form } = {}) {
  let res
  const token = getToken()
  try {
    res = await fetch(`${BASE}${path}`, {
      method,
      headers: {
        ...(form ? {} : { 'Content-Type': 'application/json' }),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: form ?? (body ? JSON.stringify(body) : undefined),
    })
  } catch {
    throw new ApiError('Can’t reach Topline right now. Check your internet connection and try again.', 0)
  }
  const data = res.status === 204 ? null : await res.json().catch(() => null)
  // A login that's expired or no longer exists (e.g. after a data reset): drop it and log in again.
  if (res.status === 401 && token) {
    clearSession()
    if (!window.location.pathname.startsWith('/login')) {
      window.location.assign('/login')
    }
  }
  if (!res.ok) throw new ApiError(data?.message ?? `Request failed (${res.status})`, res.status, data?.field)
  return data
}

const q = (params) => {
  const s = new URLSearchParams(Object.entries(params ?? {}).filter(([, v]) => v != null && v !== '')).toString()
  return s ? `?${s}` : ''
}
const v = (id) => `/vendor/stores/${id}`

export const api = {
  // public
  health: () => http('GET', '/health'),
  getPlans: () => http('GET', '/plans'),
  getSite: (params) => http('GET', `/site${q(params)}`),
  checkSlug: (slug) => http('GET', `/stores/check-slug${q({ slug })}`),
  reportError: (error) => http('POST', '/telemetry/error', error).catch(() => {}),

  // storefront (public, per store)
  getStorefront: (slug) => http('GET', `/storefront/${slug}`),
  getStoreProduct: (slug, p) => http('GET', `/storefront/${slug}/products/${p}`),
  placeOrder: (slug, body) => http('POST', `/storefront/${slug}/orders`, body),
  getOrderPayment: (slug, id) => http('GET', `/storefront/${slug}/orders/${id}/payment`),
  requestQuote: (slug, body) => http('POST', `/storefront/${slug}/quotes`, body),

  // payments
  paySubscription: (body) => http('POST', '/billing/subscription/pay', body),
  getSubscriptionPayment: (ref) => http('GET', `/billing/payments/${ref}`),
  answerSimulatedPrompt: (ref, action, pin) => http('POST', `/simulator/payments/${ref}`, { action, pin }),

  // auth & onboarding
  requestOtp: (phone) => http('POST', '/auth/otp', { phone }),
  verifyOtp: (phone, code) => http('POST', '/auth/otp/verify', { phone, code }),
  createStore: (body) => http('POST', '/stores', body),

  // photos
  uploadPhoto: (file, storeId) => {
    const form = new FormData()
    form.append('file', file)
    if (storeId) form.append('storeId', storeId)
    return http('POST', '/uploads', null, { form })
  },

  // shop admin (owner/staff of the store, or a Topline admin)
  getVendorStore: (id) => http('GET', v(id)),
  updateVendorStore: (id, patch) => http('PATCH', v(id), patch),
  getDashboard: (id) => http('GET', `${v(id)}/dashboard`),
  listProducts: (id) => http('GET', `${v(id)}/products`),
  saveProduct: (id, p) => (p.id ? http('PUT', `${v(id)}/products/${p.id}`, p) : http('POST', `${v(id)}/products`, p)),
  deleteProduct: (id, pid) => http('DELETE', `${v(id)}/products/${pid}`),
  adjustStock: (id, pid, delta) => http('POST', `${v(id)}/products/${pid}/stock`, { delta }),
  listOrders: (id, params) => http('GET', `${v(id)}/sales${q(params)}`),
  updateOrderStatus: (id, oid, status) => http('PATCH', `${v(id)}/sales/${oid}`, { status }),
  createSale: (id, body) => http('POST', `${v(id)}/sales`, body),
  listCustomers: (id) => http('GET', `${v(id)}/customers`),
  saveCustomer: (id, body) => http('POST', `${v(id)}/customers`, body),
  recordCustomerPayment: (id, cid, amount) => http('POST', `${v(id)}/customers/${cid}/payments`, { amount }),
  listPayouts: (id) => http('GET', `${v(id)}/payouts`),

  // Topline admin
  getOverview: () => http('GET', '/admin/overview'),
  listAllStores: () => http('GET', '/admin/stores'),
  setStoreStatus: (id, status) => http('PATCH', `/admin/stores/${id}`, { status }),
  listPayments: () => http('GET', '/admin/payments'),
  listAllPayouts: (params) => http('GET', `/admin/payouts${q(params)}`),
  retryPayout: (id) => http('POST', `/admin/payouts/${id}/retry`),
  updateSite: (data) => http('PUT', '/admin/site', data),
  listIncidents: (show) => http('GET', `/admin/incidents${q({ show })}`),
  resolveIncident: (id, resolved = true) => http('PATCH', `/admin/incidents/${id}`, { resolved }),
}
