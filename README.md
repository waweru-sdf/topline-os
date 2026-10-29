# Topline OS

Hardware shops sign up, pay, and get their own online store at `theirshop.toplinehub.co.ke` plus a shop admin (counter sales, stock, credit). Topline staff get an overall admin over every store.

```
topline-os/
  src/, package.json …   React frontend (this folder)
  backend/               Flask API (see backend/README.md)
  deploy/                server setup and update scripts (see DEPLOY.md)
```

## Set up (first time)

Needs Node 22.12+ and Python 3.12+. Use two terminals, both starting in `topline-os`.

**Terminal 1: backend**

```bash
cd backend
python -m venv .venv
.venv\Scripts\activate            # Mac/Linux: source .venv/bin/activate
pip install -r requirements-dev.txt
copy .env.example .env             # Mac/Linux: cp .env.example .env
flask --app wsgi seed
flask --app wsgi run --debug       # http://localhost:5000/api/health
```

**Terminal 2: frontend**

```bash
copy .env.example .env             # Mac/Linux: cp .env.example .env
npm install
npm run dev                        # http://localhost:5173
```

The frontend's `.env` points at the backend (`VITE_API_URL=http://localhost:5000/api`). The backend must be running: the old built-in mock data has been removed.

**Updating from an earlier version?** The database layout changed. In `backend`, with the backend stopped: `pip install -r requirements-dev.txt` then `flask --app wsgi seed --reset`.

## Every day after that

- **Terminal 1:** `cd backend`, activate (`.venv\Scripts\activate`), then `flask --app wsgi run --debug`
- **Terminal 2:** `npm run dev`

## Logins

The code is **123456**; while SMS is simulated, the login page shows it.

| Who | Phone | Store | Link (dev) | Status |
| --- | --- | --- | --- | --- |
| Topline admin | 0700 000 000 | every store | `/admin` | |
| Topline admin (Felix) | 0712 000 000 | every store | `/admin` | |
| Peter Kamau | 0712 345 678 | Mjengo Hardware, Kitengela | mjengo.localhost:5173 | active. Till 5123456, delivery zones |
| Juma Mwamba | 0733 444 555 | Mwamba Hardware | mwamba.localhost:5173 | active. Paybill 247247, acc 0733444555 |
| Grace Wanjiru | 0722 118 404 | Baraka Builders Centre | baraka-builders.localhost:5173 | active. Pochi, delivery by quote |
| Fatma Said | 0711 450 330 | Coast Steel Mart | coast-steel.localhost:5173 | active. Till 8899001 |
| Ali Hassan | 0733 902 117 | Kilimani Paints & Tools | kilimani-paints.localhost:5173 | past due (grace period). Send money |
| Kiprono Rotich | 0720 664 018 | Rift Timber Yard | rift-timber.localhost:5173 | suspended (store hidden) |
| Mary Muthoni | 0798 221 560 | Nyeri Plumb Centre | nyeri-plumb.localhost:5173 | draft (signed up, not paid) |

Every store also works at `localhost:5173/s/<slug>`. Each shop has its own products, stock, sales history and page visits; Mjengo also has credit customers.

Reset the data with `flask --app wsgi seed --reset` (in `backend`, with the backend stopped).

## Other commands

```bash
npm run build      # production frontend in dist/
npm run lint
cd backend && pytest
```

## What's in it

| Area | URL | What it does |
| --- | --- | --- |
| Landing page | `/` | Explains Topline. Type a shop name to see the store link, pricing, FAQ |
| Sign-up | `/start` | Owner, shop, link (with availability check), plan, then M-PESA payment |
| Log in | `/login` | Phone + one-time code |
| Storefront | `/s/:slug` or `slug.toplinehub.co.ke` | Catalogue with search and categories, product page, cart, M-PESA checkout, quote request |
| Shop admin | `/dashboard` | Overview, counter till, online orders, products & stock, customers & credit, store settings, plan & billing |
| Topline admin | `/admin` | Overview (income, customers, visits per day, top shops), stores (suspend, change plan, open any shop admin), payments in and payouts to shops, site content (headline, announcement, pictures, contacts), system health (errors, speed, alerts) |

## Payments, delivery and photos

**Paying Topline for a plan (sign-up and renewals).** The owner enters an M-PESA number and gets a prompt on that phone for the exact plan price, payable to TOPLINE. The page waits and shows the result. In simulation a test phone appears on screen: enter any 4-digit PIN and press Send, or Cancel to test a decline. Numbers ending in 999 always fail.

**How customers pay a shop.** Each shop picks how it receives money in Settings → Getting paid:

| Method | Customer can pay by |
| --- | --- |
| Till (Buy Goods) | M-PESA prompt for the exact total, or paying the till themselves |
| Paybill + account | M-PESA prompt, or paying the paybill themselves |
| Pochi la Biashara | Paying the Pochi number themselves |
| Send money | Sending to the phone number themselves |

- **Prompt:** Topline collects through Paystack, the order is confirmed automatically, and the money is sent on to the shop's till, paybill or phone (minus `PLATFORM_COMMISSION_PERCENT`, default 0). The shop sees each transfer under **Money received**.
- **Paying directly:** the checkout shows step-by-step M-PESA instructions with the shop's number and the exact amount. The customer types the M-PESA code from their SMS. The order waits as **Check payment** until the shop taps **Payment received** (or cancels it, which puts the stock back).
- Stock is held the moment an order is placed, so two people can't buy the last bag. If a prompt isn't approved within `PAYMENT_TIMEOUT_MINUTES` (10), the order is cancelled and the stock released.

**Delivery fees.** Each shop chooses in Settings → Delivery:

- **Areas with fixed fees** (e.g. Kitengela town KSh 300, EPZ KSh 600, Isinya KSh 900). The customer picks an area at checkout and the fee is added to the total and the M-PESA prompt.
- **Free delivery over** an amount (optional).
- **Quote after ordering** for heavy loads (lorry, distance-based). The customer orders and pays for the goods, and the shop calls with the delivery price.
- Pick-up from the shop is always free.

**Photos.** Shop owners upload their own product photos, logo and cover from their phone or computer (JPG, PNG or WebP, up to 5 MB). Topline admins upload the main site pictures in Site content. Files are checked and saved to `backend/instance/uploads` (change with `UPLOAD_FOLDER`; use S3 or similar storage in production).

### Going live with Paystack

The Paystack integration is fully written (`backend/app/services/payments/paystack.py`): M-PESA charges, payment checks, transfers to shops and webhooks. To switch it on:

1. In `backend/.env`: `PAYMENTS_MODE=paystack`, `PAYSTACK_SECRET_KEY=sk_live_…`, `PAYSTACK_PUBLIC_KEY=pk_live_…`
2. In the Paystack dashboard, set the webhook URL to `https://<your-api-host>/api/webhooks/paystack`
3. Enable Transfers on the Paystack account (needed to pay shops) and keep the balance funded.
4. Check the M-PESA recipient codes in `PAYSTACK_RECIPIENT` (top of `paystack.py`) against Paystack's current Kenya transfer docs. Till and paybill transfers use business-type recipients.
5. Restart the backend. `GET /api/health` shows `"payments": "paystack"`.

Test with `sk_test_` keys first.

### Monitoring and alerts

Every page visit (main site and each shop) and every API request is counted. Server errors, failed payments, failed payouts and errors in customers' browsers are grouped into **problems** on System health. Set `ALERT_WEBHOOK_URL` (a Slack, Discord or Teams channel webhook) to get a message the 1st, 10th, 100th and 1,000th time a problem happens. `ALERT_SMS_PHONES` sends SMS alerts once SMS is live.

### How store links work

`src/lib/tenant.js` reads the hostname. On `mjengo.toplinehub.co.ke` (or `mjengo.localhost:5173` in dev), the whole app *is* Mjengo's store. On the main domain, stores live at `/s/mjengo`. Each store's accent colour re-themes its storefront by overriding the `--color-amber` CSS variable.

## Tech choices (as of Oct 2026)

- **React 19.3** with the **React Compiler** (stable Babel route via `@rolldown/plugin-babel`), so there's no hand-written `useMemo`/`useCallback`. Forms use React 19 Actions (`useActionState`, `<form action>`), and pages set their own `<title>`.
- **Vite 8** (Rolldown) + `@vitejs/plugin-react` 6.
- **React Router 8** in data mode: `createBrowserRouter`, lazy route modules, loaders/actions, `useFetcher` for optimistic updates. Each page is its own chunk.
- **Tailwind CSS 4**, CSS-first config. Brand tokens live in `src/styles/index.css` under `@theme`.
- **ESLint 10** flat config with `eslint-plugin-react-hooks` 7 (includes the Compiler's purity/refs rules).
- Plain JavaScript (JSX), as requested. `lucide-react` for icons. No chart library: the sales chart is hand-drawn SVG to keep phones fast.

## Frontend folder structure

```
src/
  main.jsx, router.jsx         entry + all routes (tenant-aware)
  styles/index.css             Tailwind + Topline design tokens
  lib/                         formatting (KES, phone), tenant/subdomain, Unsplash images, counties, payout methods
  services/
    api.js                     the ONLY data entry point (HTTP to the Flask API)
    session.js                 logged-in user/token
  context/CartContext.jsx      per-store cart
  components/                  ui/ (Button, Field, Badge, Img, Dialog…), layout/AppShell, SalesChart, BarChart,
                               PaymentPrompt (M-PESA prompt + test phone), PhotoUpload
  features/
    marketing/                 landing page
    onboarding/                sign-up wizard
    auth/                      login
    storefront/                store layout, home, product, cart, checkout
    vendor/                    shop admin pages
    platform/                  Topline admin (overview, stores, payments, site content, health)
```

## Brand

| Token | Hex | Use |
| --- | --- | --- |
| `navy` | #0B3A6E | Primary, headers, buttons |
| `navy-deep` | #07284F | Footer, hover |
| `amber` | #F5A623 | Accent, main calls to action (navy text on top) |
| `mist` | #D7E2EC | Text on navy |
| `steel` | #6D89A8 | Muted lines, icons |
| `concrete` | #EEF1F4 | App background |

Font: Ubuntu (Google Fonts). The logo in `components/ui/Logo.jsx` is drawn in code as a placeholder. **Replace it with the official SVG.**

Default pictures come from Unsplash (free Unsplash License), listed in `src/lib/images.js`. Admins replace the main site's pictures in Site content, and shops upload their own.

## API contract

`src/services/api.js` lists every call. With `VITE_API_URL` set, these REST endpoints are used. Money is whole KES, dates ISO 8601, and errors are `{ message, field? }` with a 4xx status.

| Call | Method & path |
| --- | --- |
| getPlans | `GET /plans` |
| checkSlug | `GET /stores/check-slug?slug=` → `{ slug, available, reason }` |
| getStorefront | `GET /storefront/:slug` → `{ store, categories, products }` (no buying prices) |
| getStoreProduct | `GET /storefront/:slug/products/:productSlug` |
| placeOrder | `POST /storefront/:slug/orders`. Creates the order and sends the M-PESA STK push |
| getOrderPayment | `GET /storefront/:slug/orders/:id/payment` → `{ status: pending\|paid\|failed, mpesaRef, failureReason }` |
| requestQuote | `POST /storefront/:slug/quotes` |
| requestOtp / verifyOtp | `POST /auth/otp`, `POST /auth/otp/verify` → `{ user, token }` |
| createStore | `POST /stores` |
| paySubscription / getSubscriptionPayment | `POST /billing/subscription/pay` → pending payment, then `GET /billing/payments/:ref` |
| answerSimulatedPrompt | `POST /simulator/payments/:ref` `{ action: approve\|decline, pin }` (simulation only) |
| uploadPhoto | `POST /uploads` (multipart `file`, `storeId`) → `{ id, url }` |
| getSite | `GET /site` |
| listPayouts | `GET /vendor/stores/:id/payouts` |
| getVendorStore / updateVendorStore | `GET/PATCH /vendor/stores/:id` |
| getDashboard | `GET /vendor/stores/:id/dashboard` |
| listProducts / saveProduct / deleteProduct | `GET/POST /vendor/stores/:id/products`, `PUT/DELETE …/products/:pid` |
| adjustStock | `POST /vendor/stores/:id/products/:pid/stock` `{ delta }` |
| listOrders / updateOrderStatus / createSale | `GET/POST /vendor/stores/:id/sales`, `PATCH …/sales/:sid` |
| listCustomers / saveCustomer / recordCustomerPayment | `GET/POST /vendor/stores/:id/customers`, `POST …/customers/:cid/payments` |
| listAllStores / setStoreStatus | `GET /admin/stores`, `PATCH /admin/stores/:id` |
| getOverview, listPayments, listAllPayouts, retryPayout | `GET /admin/overview`, `GET /admin/payments`, `GET /admin/payouts`, `POST /admin/payouts/:id/retry` |
| updateSite, listIncidents, resolveIncident | `PUT /admin/site`, `GET /admin/incidents`, `PATCH /admin/incidents/:id` |

Backend rules the UI relies on:

- Storefront endpoints never return `buyPrice`.
- Vendor endpoints only work on the caller's own store; the backend enforces this, not the UI.
- Sales and payments update stock and customer balances.

## Deploying

Two setups are ready:

- **Now (free):** website on Hostinger, backend on Render, database on Neon, all deployed from GitHub. See **[HOSTING.md](HOSTING.md)**. Shop links are `toplinehub.co.ke/s/<shop>`.
- **Later (one VPS):** everything on one Ubuntu server with automatic HTTPS for `<shop>.toplinehub.co.ke`. See **[DEPLOY.md](DEPLOY.md)**.

Frontend build settings live in `.env.production`: `VITE_API_URL`, `VITE_ROOT_DOMAIN`, and `VITE_STORE_LINKS` (`path` or `subdomain`).

## Known gaps

- Receipt printing setup
- Paystack recipient codes for till/paybill transfers to confirm with Paystack
- Staff accounts and roles, quotations list, suppliers, expenses, eTIMS
- Pricing on the landing page is a placeholder until Topline confirms final prices
