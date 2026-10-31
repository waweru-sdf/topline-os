import { createBrowserRouter, Outlet, ScrollRestoration } from 'react-router'
import { getTenantSlugFromHost } from '@/lib/tenant'
import { RouteError } from '@/components/RouteError'
import { PageLoader } from '@/components/PageLoader'

/*
 * Route modules are lazy-loaded: each exports `Component` and, when it needs
 * data, `loader` / `action`. A storefront visitor never downloads admin code.
 */

function Root() {
  return (
    <>
      <ScrollRestoration />
      <Outlet />
    </>
  )
}

const storefrontRoutes = [
  { index: true, lazy: () => import('@/features/storefront/StoreHome') },
  { path: 'p/:productSlug', lazy: () => import('@/features/storefront/ProductPage') },
  { path: 'cart', lazy: () => import('@/features/storefront/CartPage') },
  { path: 'checkout', lazy: () => import('@/features/storefront/Checkout') },
]

const storefront = (path) => ({
  path,
  id: 'store',
  lazy: () => import('@/features/storefront/StoreLayout'),
  errorElement: <RouteError />,
  children: storefrontRoutes,
})

const mainSiteRoutes = [
  {
    id: 'marketing',
    lazy: () => import('@/features/marketing/MarketingLayout'),
    children: [{ index: true, lazy: () => import('@/features/marketing/Landing') }],
  },
  { path: 'start', lazy: () => import('@/features/onboarding/Onboarding') },
  { path: 'login', lazy: () => import('@/features/auth/Login') },
  { path: 'change-password', lazy: () => import('@/features/auth/ChangePassword') },
  storefront('s/:slug'),
  {
    path: 'dashboard',
    id: 'vendor',
    lazy: () => import('@/features/vendor/VendorLayout'),
    errorElement: <RouteError />,
    children: [
      { index: true, lazy: () => import('@/features/vendor/Dashboard') },
      { path: 'sell', lazy: () => import('@/features/vendor/Sell') },
      { path: 'products', lazy: () => import('@/features/vendor/Products') },
      { path: 'products/new', lazy: () => import('@/features/vendor/ProductEditor') },
      { path: 'products/:productId', lazy: () => import('@/features/vendor/ProductEditor') },
      { path: 'orders', lazy: () => import('@/features/vendor/Orders') },
      { path: 'customers', lazy: () => import('@/features/vendor/Customers') },
      { path: 'money', lazy: () => import('@/features/vendor/Money') },
      { path: 'settings', lazy: () => import('@/features/vendor/Settings') },
      { path: 'billing', lazy: () => import('@/features/vendor/Billing') },
      { path: 'activity', lazy: () => import('@/features/vendor/Activity') },
    ],
  },
  {
    path: 'admin',
    lazy: () => import('@/features/platform/AdminLayout'),
    errorElement: <RouteError />,
    children: [
      { index: true, lazy: () => import('@/features/platform/Overview') },
      { path: 'stores', lazy: () => import('@/features/platform/Stores') },
      { path: 'payments', lazy: () => import('@/features/platform/Payments') },
      { path: 'site', lazy: () => import('@/features/platform/SiteContent') },
      { path: 'health', lazy: () => import('@/features/platform/Health') },
      { path: 'activity', lazy: () => import('@/features/platform/Activity') },
    ],
  },
]

// On mjengo.toplinehub.co.ke the store *is* the site; everything else gets the main site.
const tenant = getTenantSlugFromHost()

export const router = createBrowserRouter([
  {
    element: <Root />,
    errorElement: <RouteError />,
    hydrateFallbackElement: <PageLoader />,
    children: [
      ...(tenant ? [storefront('/')] : mainSiteRoutes),
      { path: '*', element: <RouteError notFound /> },
    ],
  },
])
