import { useParams, useRouteLoaderData } from 'react-router'

/**
 * Store data loaded by StoreLayout, plus `base`: the path prefix for in-store
 * links ("/s/mjengo" on the main site, "" on mjengo.toplinehub.co.ke).
 */
export function useStore() {
  const data = useRouteLoaderData('store')
  const { slug } = useParams()
  return { ...data, base: slug ? `/s/${slug}` : '' }
}
