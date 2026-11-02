import { AccountingPage } from '@/components/ComingSoon'
import { requirePlatformAdmin } from '@/features/vendor/guard'

export function loader() {
  requirePlatformAdmin()
  return null
}

function Accounting() {
  return <AccountingPage variant="platform" />
}

export { Accounting as Component }
