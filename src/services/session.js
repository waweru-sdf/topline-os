/**
 * Tiny session store (token + user). Read synchronously by route loaders to
 * guard the admin areas. The real backend should use an httpOnly cookie; then
 * only `user` needs to live here.
 */
const KEY = 'topline-session'
let cache

function read() {
  if (cache !== undefined) return cache
  try {
    cache = JSON.parse(localStorage.getItem(KEY)) ?? null
  } catch {
    cache = null
  }
  return cache
}

export const getSession = () => read()
export const getToken = () => read()?.token ?? null
export const getUser = () => read()?.user ?? null

export function setSession(session) {
  cache = session
  try {
    if (session) localStorage.setItem(KEY, JSON.stringify(session))
    else localStorage.removeItem(KEY)
  } catch {
    /* private mode: keep in memory only */
  }
}

export const clearSession = () => setSession(null)

/** Topline admins can open a store's shop admin; this remembers which one. */
export const getActingStoreId = () => read()?.actingStoreId ?? null
export const setActingStore = (storeId) => setSession({ ...read(), actingStoreId: storeId })

/** The branch this browser works at, per store (shops with several branches). '' = the main branch. */
export const getBranchId = (storeId) => read()?.branches?.[storeId] ?? ''
export const setBranchId = (storeId, branchId) => setSession({ ...read(), branches: { ...read()?.branches, [storeId]: branchId } })
