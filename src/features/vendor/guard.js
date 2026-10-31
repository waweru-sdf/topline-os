import { redirect } from 'react-router'
import { getActingStoreId, getUser } from '@/services/session'

/**
 * Child loaders run in parallel with VendorLayout's, so each one checks the
 * session itself rather than assuming the layout already did.
 */
export function requireStoreId() {
  const user = getUser()
  if (user?.mustChangePassword) throw redirect('/change-password')
  if (user?.role === 'vendor') return user.storeId
  // Topline admins work inside whichever store they opened from the admin.
  if (user?.role === 'platform_admin' && getActingStoreId()) return getActingStoreId()
  throw redirect(user?.role === 'platform_admin' ? '/admin' : '/login')
}

export function requirePlatformAdmin() {
  const user = getUser()
  if (!user || user.role !== 'platform_admin') throw redirect('/login')
  if (user.mustChangePassword) throw redirect('/change-password')
  return user
}
