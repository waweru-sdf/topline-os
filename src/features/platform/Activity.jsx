import { useLoaderData } from 'react-router'
import { api } from '@/services/api'
import { PageHeader } from '@/components/layout/AppShell'
import { ActivityFeed } from '@/components/ActivityFeed'
import { requirePlatformAdmin } from '@/features/vendor/guard'

export async function loader({ request }) {
  requirePlatformAdmin()
  const url = new URL(request.url)
  const filters = { storeId: url.searchParams.get('storeId'), kind: url.searchParams.get('kind') }
  const [entries, stores] = await Promise.all([api.listActivity(filters), api.listAllStores()])
  return { entries, stores: stores.map((s) => ({ id: s.id, name: s.name })).sort((a, b) => a.name.localeCompare(b.name)) }
}

function Activity() {
  const { entries, stores } = useLoaderData()
  return (
    <>
      <title>Activity — Topline admin</title>
      <PageHeader title="Activity">What’s happening in every shop: orders, sales, stock, payments, logins and changes.</PageHeader>
      <ActivityFeed entries={entries} stores={stores} loadMore={api.listActivity} />
    </>
  )
}

export { Activity as Component }
