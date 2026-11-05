import { useParams, useRouteLoaderData } from 'react-router'

/**
 * Store data loaded by StoreLayout, plus `base`: the path prefix for in-store links
 * ("/s/mjengo" on the main site, "" on the shop's own address, plus "/kitsuru" on a branch's page),
 * and `home`: the shop's (main branch's) page, for switching branches.
 */
export function useStore() {
  const data = useRouteLoaderData('store')
  const { slug, branch } = useParams()
  const home = slug ? `/s/${slug}` : ''
  return { ...data, home, base: branch ? `${home}/${branch}` : home }
}
