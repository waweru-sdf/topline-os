/**
 * The only module UI code talks to for data: every call to the API
 * (Laravel in laravel/) lives here. Set VITE_API_URL in .env; defaults to the local backend.
 */
import { clearSession, getToken } from './session'

const BASE = (import.meta.env.VITE_API_URL || '/api').replace(/\/$/, '')

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
  // After an admin reset the server refuses everything until a new password is chosen.
  if (res.status === 403 && data?.field === 'newPassword' && path !== '/auth/password' && !window.location.pathname.startsWith('/change-password')) {
    window.location.assign('/change-password')
  }
  if (!res.ok) throw new ApiError(data?.message ?? `Request failed (${res.status})`, res.status, data?.field)
  return data
}

/** Download a file (CSV) that needs the login token, as a normal browser download. */
async function download(path) {
  let res
  try {
    res = await fetch(`${BASE}${path}`, { headers: getToken() ? { Authorization: `Bearer ${getToken()}` } : {} })
  } catch {
    throw new ApiError('Can’t reach Topline right now. Check your internet connection and try again.', 0)
  }
  if (!res.ok) {
    const data = await res.json().catch(() => null)
    throw new ApiError(data?.message ?? `Download failed (${res.status})`, res.status, data?.field)
  }
  const name = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') ?? '')?.[1] ?? 'topline.csv'
  const url = URL.createObjectURL(await res.blob())
  const a = Object.assign(document.createElement('a'), { href: url, download: name })
  document.body.append(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
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
  sendReview: (body) => http('POST', '/reviews', body),
  checkSlug: (slug) => http('GET', `/stores/check-slug${q({ slug })}`),
  reportError: (error) => http('POST', '/telemetry/error', error).catch(() => {}),
  listPublicSuppliers: () => http('GET', '/suppliers'),

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

  // auth & onboarding (log in with phone or email + password)
  login: (login, password) => http('POST', '/auth/login', { login, password }),
  changePassword: (currentPassword, newPassword) => http('POST', '/auth/password', { currentPassword, newPassword }),
  me: () => http('GET', '/auth/me'),
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
  listStoreActivity: (id, params) => http('GET', `${v(id)}/activity${q(params)}`),

  // shop admin: suppliers, accounting, stock credit
  listSuppliers: (id) => http('GET', `${v(id)}/suppliers`),
  getSupplier: (id, slug) => http('GET', `${v(id)}/suppliers/${encodeURIComponent(slug)}`),
  orderFromSupplier: (id, slug, body) => http('POST', `${v(id)}/suppliers/${encodeURIComponent(slug)}/orders`, body),
  listSupplierOrders: (id) => http('GET', `${v(id)}/supplier-orders`),
  cancelSupplierOrder: (id, orderId) => http('PATCH', `${v(id)}/supplier-orders/${orderId}`, { status: 'cancelled' }),
  getAccounting: (id, month) => http('GET', `${v(id)}/accounting${q({ month })}`),
  listExpenses: (id, month) => http('GET', `${v(id)}/expenses${q({ month })}`),
  addExpense: (id, body) => http('POST', `${v(id)}/expenses`, body),
  deleteExpense: (id, expenseId) => http('DELETE', `${v(id)}/expenses/${expenseId}`),
  downloadAccounting: (id, type, month) => download(`${v(id)}/accounting/export${q({ type, month })}`),
  listCreditRequests: (id) => http('GET', `${v(id)}/credit-requests`),
  requestCredit: (id, body) => http('POST', `${v(id)}/credit-requests`, body),

  // supplier accounts
  supplierSignUp: (body) => http('POST', '/supplier-signups', body),
  supplierMe: () => http('GET', '/supplier/me'),
  updateSupplierProfile: (body) => http('PUT', '/supplier/profile', body),
  listOwnProducts: () => http('GET', '/supplier/products'),
  saveOwnProduct: (p) => (p.id ? http('PUT', `/supplier/products/${p.id}`, p) : http('POST', '/supplier/products', p)),
  deleteOwnProduct: (pid) => http('DELETE', `/supplier/products/${pid}`),
  listIncomingOrders: () => http('GET', '/supplier/orders'),
  updateIncomingOrder: (orderId, status) => http('PATCH', `/supplier/orders/${orderId}`, { status }),

  // Topline admin
  getOverview: () => http('GET', '/admin/overview'),
  listAllStores: () => http('GET', '/admin/stores'),
  setStoreStatus: (id, status) => http('PATCH', `/admin/stores/${id}`, { status }),
  resetOwnerPassword: (id) => http('POST', `/admin/stores/${id}/reset-password`),
  listActivity: (params) => http('GET', `/admin/activity${q(params)}`),
  listPayments: () => http('GET', '/admin/payments'),
  listAllPayouts: (params) => http('GET', `/admin/payouts${q(params)}`),
  retryPayout: (id) => http('POST', `/admin/payouts/${id}/retry`),
  updateSite: (data) => http('PUT', '/admin/site', data),
  adminBadges: () => http('GET', '/admin/badges'),
  adminUpdatePlan: (id, body) => http('PUT', `/admin/plans/${id}`, body),
  adminReviews: (params) => http('GET', `/admin/reviews${q(params)}`),
  adminSetReviewStatus: (id, status) => http('PATCH', `/admin/reviews/${id}`, { status }),
  adminDeleteReview: (id) => http('DELETE', `/admin/reviews/${id}`),
  listIncidents: (show) => http('GET', `/admin/incidents${q({ show })}`),
  resolveIncident: (id, resolved = true) => http('PATCH', `/admin/incidents/${id}`, { resolved }),
  adminSuppliers: () => http('GET', '/admin/suppliers'),
  adminSupplier: (id) => http('GET', `/admin/suppliers/${id}`),
  adminUpdateSupplier: (id, body) => http('PATCH', `/admin/suppliers/${id}`, body),
  adminReviewSupplier: (id, action, note) => http('POST', `/admin/suppliers/${id}/review`, { action, note }),
  adminResetSupplierPassword: (id) => http('POST', `/admin/suppliers/${id}/reset-password`),
  adminAccounting: (month) => http('GET', `/admin/accounting${q({ month })}`),
  downloadPlatformAccounting: (type, month) => download(`/admin/accounting/export${q({ type, month })}`),
  adminCreditRequests: (status) => http('GET', `/admin/credit-requests${q({ status })}`),
  adminUpdateCreditRequest: (id, body) => http('PATCH', `/admin/credit-requests/${id}`, body),
}
