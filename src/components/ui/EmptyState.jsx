export function EmptyState({ icon: Icon, title, children, action }) {
  return (
    <div className="flex flex-col items-center rounded-card border border-dashed border-line px-6 py-12 text-center">
      {Icon && <Icon aria-hidden className="mb-3 size-8 text-steel" />}
      <h3 className="font-bold">{title}</h3>
      {children && <p className="mt-1 max-w-sm text-sm text-muted">{children}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  )
}
