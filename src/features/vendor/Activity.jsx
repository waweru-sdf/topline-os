import { redirect, useLoaderData } from 'react-router'
import { api } from '@/services/api'
import { PageHeader } from '@/components/layout/AppShell'
import { ActivityFeed } from '@/components/ActivityFeed'
import { requireStoreId } from './guard'

export async function loader({ request }) {
  const storeId = requireStoreId()
  const url = new URL(request.url)
  try {
    return { storeId, entries: await api.listStoreActivity(storeId, { kind: url.searchParams.get('kind') }) }
  } catch (e) {
    if (e.status === 403) throw redirect('/dashboard')  // cashiers don't see the shop's activity
    throw e
  }
}

function Activity() {
  const { storeId, entries } = useLoaderData()
  return (
    <>
      <title>Activity — Topline</title>
      <PageHeader title="Activity">Everything that happened in your shop: orders, counter sales, stock changes, money received and logins.</PageHeader>
      <ActivityFeed entries={entries} loadMore={(params) => api.listStoreActivity(storeId, params)} />
    </>
  )
}

export { Activity as Component }
