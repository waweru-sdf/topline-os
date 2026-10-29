/**
 * Photos from Unsplash (free Unsplash License — no attribution required, but we
 * credit Unsplash in the footer). Served from Unsplash's imgix CDN so each use
 * can ask for the size it needs.
 */
const PHOTOS = {
  // Shops & people
  heroShelves: 'photo-1765744893064-dce3184289ef', // cluttered hardware store, shelves of boxes
  toolWall: 'photo-1759200165738-6366977a73c6', // wall display of tools in a store
  storefront: 'photo-1784847684790-48143d36566e', // hardware storefront, sunny day
  aisle: 'photo-1631856954655-966f97d809de', // store aisle
  ownerPhone: 'photo-1687422811062-a966b55cb217', // man smiling at his phone
  stallOwner: 'photo-1783309239591-9b63f3599744', // man arranging tools at a stall
  handshake: 'photo-1521791136064-7986c2920216',

  // Product categories
  cement: 'photo-1680357680725-f350480aee35', // pile of cement bags
  cementStack: 'photo-1773394089934-3e29f2a3d6a9',
  rebar: 'photo-1763771420303-0f11ccf613d1', // bundled steel rebar
  rebarStack: 'photo-1745909247906-123b53b70e06',
  roofing: 'photo-1673645652590-9d21295bf4ac', // metal roof close up
  roofingSky: 'photo-1673645652350-6a4c31c1c78f',
  timber: 'photo-1634672652995-ee7525bce595', // pile of wood
  planks: 'photo-1422246654994-34520d5a0340',
  pipes: 'photo-1545193329-4a052e14eb8f', // white pipe
  pipesWall: 'photo-1646009445351-b8192e095f3a',
  paint: 'photo-1542238060-646c7ed65622', // paint bucket lot
  paintShelf: 'photo-1773525912493-cdb088d19d64',
  rollers: 'photo-1525909002-1b05e0c869d8',
  wires: 'photo-1601462904263-f2fa0c851cb9', // coated wires
  cable: 'photo-1518181835702-6eef8b4b2113',
  hammer: 'photo-1581783898377-1c85bf937427',
  drill: 'photo-1518709414768-a88981a4515d',
  toolRack: 'photo-1426927308491-6380b6a9936f',
  toolsTable: 'photo-1567361808960-dec9cb578182',
}

/** Build a sized Unsplash URL. `name` is a key of PHOTOS or a raw photo id. */
export function photo(name, { w = 800, h, q = 70 } = {}) {
  const id = PHOTOS[name] ?? name
  const params = new URLSearchParams({ auto: 'format', fit: 'crop', w: String(w), q: String(q) })
  if (h) params.set('h', String(h))
  return `https://images.unsplash.com/${id}?${params}`
}

/** srcSet for responsive <img>. */
export const photoSrcSet = (name, widths = [400, 800, 1200], h) =>
  widths
    .map((w) => `${photo(name, { w, h: h ? Math.round((h / widths.at(-1)) * w) : undefined })} ${w}w`)
    .join(', ')

export { PHOTOS }
