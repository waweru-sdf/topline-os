import { createContext, use, useEffect, useState } from 'react'

/**
 * Cart for one storefront. Keyed by store slug so carts at different stores
 * never mix, and persisted so a refresh doesn't empty it.
 * Items: { productId, slug, name, unit, price, image, qty }
 */
const CartContext = createContext(null)

const keyFor = (slug) => `topline-cart-${slug}`
function readCart(slug) {
  try {
    return JSON.parse(localStorage.getItem(keyFor(slug))) ?? []
  } catch {
    return []
  }
}

export function CartProvider({ slug, children }) {
  const [items, setItems] = useState(() => readCart(slug))

  useEffect(() => {
    try {
      localStorage.setItem(keyFor(slug), JSON.stringify(items))
    } catch {
      /* ignore */
    }
  }, [slug, items])

  const add = (product, qty = 1) =>
    setItems((list) => {
      const existing = list.find((i) => i.productId === product.id)
      if (existing) return list.map((i) => (i.productId === product.id ? { ...i, qty: i.qty + qty } : i))
      return [...list, { productId: product.id, slug: product.slug, name: product.name, unit: product.unit, price: product.sellPrice, image: product.image, qty }]
    })
  const setQty = (productId, qty) =>
    setItems((list) => (qty <= 0 ? list.filter((i) => i.productId !== productId) : list.map((i) => (i.productId === productId ? { ...i, qty } : i))))
  const remove = (productId) => setItems((list) => list.filter((i) => i.productId !== productId))
  const clear = () => setItems([])

  const count = items.reduce((s, i) => s + i.qty, 0)
  const total = items.reduce((s, i) => s + i.qty * i.price, 0)

  return <CartContext value={{ items, count, total, add, setQty, remove, clear }}>{children}</CartContext>
}

export function useCart() {
  const ctx = use(CartContext)
  if (!ctx) throw new Error('useCart must be used inside <CartProvider>')
  return ctx
}
