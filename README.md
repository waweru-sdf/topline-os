# Topline OS

Hardware shops sign up, pay, and get their own online store at `toplinehub.co.ke/s/theirshop` plus a shop admin (counter sales, stock, credit, activity). Topline staff get an overall admin over every store.

**Where the project is:** Topline runs on **Laravel + MySQL on Hostinger** (Business plan), with the website and the API on one domain.

| Phase | What | Status |
| --- | --- | --- |
| 1 | Website (React): password login and sign-up, admin password reset, Activity pages, product measurements and photos | ✅ done |
| 2 | Backend in Laravel + MySQL ([`laravel/`](laravel)), matching [docs/API-CONTRACT.md](docs/API-CONTRACT.md) | ✅ done: 97 tests, on SQLite and MySQL/MariaDB |
| 3 | Go live: GitHub → Hostinger, deployed automatically on every push | see [Deploying](#deploying) |

```
topline-os/
  src/, package.json …   React website
  laravel/               the backend (Laravel 13, PHP 8.3+). The built website is copied into laravel/public.
  docs/                  API-CONTRACT.md + api-examples.json: what the API does, with a real example per endpoint
  .github/workflows/     tests every push, then publishes the ready-to-run site to the "hostinger" branch
```

**Changing the website:** edit `src/`, then run `npm run build:laravel`. That builds it for the Hostinger setup (API at `/api`, shop links at `/s/<shop>`) and copies it into `laravel/public`.

## Run it on your computer (Laravel, same as the live site)

Needs PHP 8.3+, Composer and Node 22.12+.

```bash
cd laravel
composer install
cp .env.example .env               # Windows: copy .env.example .env
```

In `laravel/.env` set `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost:8000` and, for a quick start without MySQL, `DB_CONNECTION=sqlite` (delete the other `DB_` lines). Then:

```bash
php artisan key:generate
php artisan topline:install --demo   # tables, plans and the 7 demo shops
php artisan serve                    # http://localhost:8000 (website + API)
php artisan test                     # or: vendor/bin/phpunit
```

To work on the website with instant reload, also run `npm run dev` in `topline-os` with `VITE_API_URL=http://localhost:8000/api` in its `.env`.

## Logins

Log in with the **phone number or email** and the password **topline123** (every demo account). Demo suppliers: Rift Valley Cement Co. (0700 111 222), Pwani Steel Mills (0700 111 333), and BuildPro Trading (0700 111 444, a sign-up waiting for approval, so it can't log in). Owners' emails are `<first name>@<shop>.example.com`, e.g. `peter@mjengo.example.com`; admins are `admin@toplinehub.co.ke` and `felix@toplinehub.co.ke`.

| Who | Phone | Store | Link (dev) | Status |
| --- | --- | --- | --- | --- |
| Topline admin | 0700 000 000 | every store | `/admin` | |
| Topline admin (Felix) | 0712 000 000 | every store | `/admin` | |
| Peter Kamau | 0712 345 678 | Mjengo Hardware, Kitengela | /s/mjengo | active. Till 5123456, delivery zones |
| Juma Mwamba | 0733 444 555 | Mwamba Hardware | /s/mwamba | active. Paybill 247247, acc 0733444555 |
| Grace Wanjiru | 0722 118 404 | Baraka Builders Centre | /s/baraka-builders | active. Pochi, delivery by quote |
| Fatma Said | 0711 450 330 | Coast Steel Mart | /s/coast-steel | active. Till 8899001 |
| Ali Hassan | 0733 902 117 | Kilimani Paints & Tools | /s/kilimani-paints | past due (grace period). Send money |
| Kiprono Rotich | 0720 664 018 | Rift Timber Yard | /s/rift-timber | suspended (store hidden) |
| Mary Muthoni | 0798 221 560 | Nyeri Plumb Centre | /s/nyeri-plumb | draft (signed up, not paid) |

Links are relative to `http://localhost:8000` (or `:5173` with `npm run dev`). Each shop has its own products, stock, sales history and page visits; Mjengo also has credit customers.

Reset the demo data with `php artisan migrate:fresh && php artisan topline:install --demo` (in `laravel`).

## Other commands

```bash
npm run build:laravel   # build the website into laravel/public (for Hostinger)
npm run lint
cd laravel && vendor/bin/phpunit   # 97 backend tests
```

## What's in it

| Area | URL | What it does |
| --- | --- | --- |
| Landing page | `/` | Explains Topline. Type a shop name to see the store link, pricing, FAQ |
| Sign-up | `/start` | Owner, shop, link (with availability check), plan, then M-PESA payment |
| Log in | `/login` | Phone or email + password. Forgot it? A Topline admin gives a temporary one, which must be changed at next login |
| Change password | `/change-password` | From the key icon in either admin |
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

**Photos.** Shop owners upload their own product photos, logo and cover from their phone or computer (JPG, PNG or WebP, up to 5 MB). Topline admins upload the main site pictures in Site content. Files are checked and saved in `laravel/storage/app/uploads` on the server (kept across deploys), or in MySQL with `UPLOAD_STORAGE=database`.

### Going live with Paystack

The Paystack integration is fully written (`laravel/app/Services/Payments/PaystackGateway.php`): M-PESA charges, payment checks, transfers to shops and webhooks. To switch it on:

1. In the site's `.env` (hPanel → File Manager → public_html): `PAYMENTS_MODE=paystack`, `PAYSTACK_SECRET_KEY=sk_live_…`, `PAYSTACK_PUBLIC_KEY=pk_live_…`
2. In the Paystack dashboard, set the webhook URL to `https://toplinehub.co.ke/api/webhooks/paystack`
3. Enable Transfers on the Paystack account (needed to pay shops) and keep the balance funded.
4. Check the M-PESA recipient codes in `RECIPIENT` (top of `PaystackGateway.php`) against Paystack's current Kenya transfer docs. Till and paybill transfers use business-type recipients.
5. Nothing to restart: `.env` is read on every request. `GET /api/health` shows `"payments": "paystack"`.

Test with `sk_test_` keys first.

### Connect to suppliers

Manufacturers and distributors ("suppliers") get their own login and portal at `/supplier`.

- **Signing up:** suppliers sign up themselves at `/suppliers/join` (linked from the home page, the footer and the login page): company, contact person, mobile (their login), what they supply, how shops can pay them, and a password. At most 3 sign-ups per device per day. Topline admins can't add suppliers.
- **Approval:** the sign-up waits under Topline admin → **Suppliers** (the menu shows a count). **Approve** lists them on the home page and to every shop at once; **Reject** asks for a reason. Either way they get an SMS. They can't log in until approved; a rejected supplier sees the reason when they try, and can still be approved later.
- **After approval:** the supplier logs in and lands on **Profile & products** to add a logo, more details and products with prices. Their portal has two pages: **Orders** and **Profile & products**. Changes go live straight away, with no further approval. A Topline admin can **Hide** a supplier at any time.
- **Home page:** listed suppliers appear with logo, description, categories and how many hardware shops have ordered from them. No phone numbers, prices or payment details are shown there. The list is cached for up to 10 minutes and refreshed whenever a supplier changes, so landing visits don't hit the database.
- **Ordering:** in the shop admin (owners and managers) → **Suppliers**, a shop opens a supplier, enters quantities (or describes items not on the list), the contact phone, delivery place and how they'll pay. The supplier gets an SMS and sees the order with Call and WhatsApp buttons. They take it from there, outside Topline, and mark it *contacted*, *delivered* or *declined*. The shop can cancel while it's still new.
- **Payment options** a supplier can accept: M-PESA (Paybill/Till), bank transfer (PesaLink/RTGS/EFT), pay on delivery, deposit then balance, supplier credit/LPO (30–90 days), and Topline stock credit. Money moves between shop and supplier directly; Topline doesn't hold it.

### Accounting

- **Shop admin → Accounting** (owners and managers): monthly profit and loss built from sales (takings minus what the stock cost) and the expenses the shop records (rent, wages, transport…). Also shows payment methods, credit collected, online payouts, what customers owe, a 6-month chart, and CSV downloads of sales and expenses for an accountant.
- **Topline admin → Accounting:** plan income, commission, money sent to shops (sent, waiting, failed), monthly recurring revenue, plans past due, and CSV downloads of payments and payouts.

### Stock credit

Shop owners request credit under **Stock credit** (amount, purpose, repayment period, monthly sales, contact, and consent to share a sales summary with the lending partner). They see "Information received, we'll reach out after processing". Each shop can have one open request at a time. Topline admins see every request under **Credit requests** (with a count in the menu), call or WhatsApp the shop, and set the status (received, being reviewed, approved, not approved, closed) with a private note.

### Reviews of shops

Anyone can review a hardware shop at `/reviews` (in the main menu, the footer, and a link at the bottom of every store, which fills in that shop for them). Shop names are never listed publicly: the reviewer types the name, and it's matched to a Topline shop when it's exactly one shop's name (or came from the store's link). The form asks for the shop name, 1–5 stars, the review, when they dealt with the shop, their name and an optional phone. At most 5 per device per day. Reviews are **only** visible to Topline admins (admin → **Reviews**, with a count of new ones): see the typed name and which Topline shop it matched, filter by shop to see its average, call the reviewer, mark reviews done, or delete spam. Shops never see them.

### Site content and plans

Topline admin → **Site content** edits every word and picture on the main site: the announcement bar, headline, the three "what you get" blocks (heading, text, points, picture), the suppliers and stock-credit text, the three steps, pricing text and note, the questions (add, edit, remove), the closing banner, the Reviews page text, and the footer and contacts. **Price plans** (bottom of the same page) edits each plan's name, monthly and yearly price, description, what it includes, product limit, and which plan is highlighted. Deploys never overwrite these edits. The site text is cached and refreshed whenever you publish.

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
    api.js                     the ONLY data entry point (HTTP to the API: Laravel in laravel/)
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
| login / changePassword / me | `POST /auth/login`, `POST /auth/password`, `GET /auth/me` → `{ user, token }` |
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

Everything runs on the Hostinger Business plan: the website, the API (Laravel) and MySQL, on one domain. Shop links are `toplinehub.co.ke/s/<shop>`.

**How it works:** you push to `main` on GitHub. GitHub Actions ([`.github/workflows/deploy.yml`](.github/workflows/deploy.yml)) runs the tests, builds the website into Laravel, installs the PHP packages and publishes the result to the **`hostinger`** branch (and, with Route B, uploads it over SSH). The site lands in `public_html`, and a Cron Job applies any database changes within a minute. Nothing is built or installed on the server: shared hosting can't run Composer (`proc_open` is disabled), so the server copy has no `composer.json`.

### One-time setup

1. **Push to GitHub.** Under the repository's **Actions** tab, wait for "Test and deploy" to go green. It creates or updates the `hostinger` branch. (Repo Settings → Actions → General → Workflow permissions must be **Read and write**.)
2. **PHP.** hPanel → Websites → toplinehub.co.ke → Advanced → **PHP Configuration**: version **8.3**. Under PHP options, set `upload_max_filesize` and `post_max_size` to **16M**.
3. **Database.** hPanel → Databases → **MySQL Databases**: create database `topline` and user `topline` with a generated password. Note the full names (`u324163218_topline`) and the password.
4. **Connect Hostinger to the `hostinger` branch** (Route A below). If Hostinger's deploy log shows "Installing Composer dependencies … proc_open" even though the branch has no `composer.json`, use Route B instead.
5. **Cron Job.** hPanel → Advanced → **Cron Jobs** → Custom, every minute (`* * * * *`):

   ```
   /usr/bin/php /home/u324163218/domains/toplinehub.co.ke/public_html/artisan schedule:run
   ```

   If PHP CLI on your account is older than 8.3, use `/opt/alt/php83/usr/bin/php`.
6. **Settings.** Within a minute the cron creates `public_html/.env` (File Manager → turn on "Show hidden files"). Edit it:
   - `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` from step 3
   - `FIRST_ADMIN_PHONE`, `FIRST_ADMIN_EMAIL`, `FIRST_ADMIN_PASSWORD` (at least 8 characters) for your admin login

   Save. Within a minute the cron creates the tables, the three plans and your admin.
7. **HTTPS.** hPanel → Security → **SSL**: make sure it's active, and turn on **Force HTTPS**.
8. **Check.** `https://toplinehub.co.ke/api/health` shows `{"ok":true,...}`. Log in at `/login`, then **clear `FIRST_ADMIN_PASSWORD`** in `.env`.
9. **Retire the old setup.** Delete the Render service and the Neon project. In hPanel DNS, delete the `api` CNAME that pointed at Render.

#### Route A: Hostinger's GitHub deploy

On the website's dashboard ("Connected with GitHub"), set **Branch: `hostinger`** and **Root directory: `public_html`**, with auto-deployment on, then **Redeploy**. The branch already contains the PHP packages, so nothing should be installed on the server.

#### Route B: GitHub uploads over SSH (doesn't depend on Hostinger's deployer)

1. On your computer: `ssh-keygen -t ed25519 -f ~/.ssh/topline_deploy -N ""`
2. hPanel → Advanced → **SSH Access** → SSH keys → **Add SSH key**: paste the contents of `~/.ssh/topline_deploy.pub`.
3. GitHub → repo → Settings → **Secrets and variables → Actions** → New repository secret, five times:

   | Name | Value |
   | --- | --- |
   | `HOSTINGER_SSH_KEY` | contents of `~/.ssh/topline_deploy` (the file without `.pub`) |
   | `HOSTINGER_SSH_HOST` | `153.92.8.243` |
   | `HOSTINGER_SSH_PORT` | `65002` |
   | `HOSTINGER_SSH_USER` | `u324163218` |
   | `HOSTINGER_SSH_PATH` | `domains/toplinehub.co.ke/public_html` |

4. Turn **off** Hostinger's GitHub auto-deployment, so the two routes don't overwrite each other.
5. GitHub → Actions → "Test and deploy" → **Run workflow**. The last step uploads the site and updates the database. `.env`, photos and logs on the server are never touched.

### After that

- **Updating:** push to `main`. About 3 minutes later it's live, and database changes apply within a minute after that.
- **`.env` and photos** stay on the server across deploys; they're never in Git.
- **Forgotten passwords:** Topline admin → Stores → ⋯ → Reset owner's password. Add more admins from the terminal (`php artisan topline:create-admin "Name" 07XXXXXXXX`) or with the `FIRST_ADMIN_*` lines while no admin exists.
- **Errors:** System health in the admin, and `public_html/storage/logs/` in File Manager.
- **Start over with an empty platform** (deletes every shop, owner, product, sale, payment, supplier order, credit request, expense and shop review; keeps Topline admins, plans, site content and supplier partners): on the server, in `public_html`, run `php artisan topline:clear-data` and type `CLEAR`.
- The `hostinger` branch is generated; don't edit it by hand.

## Known gaps

- Receipt printing setup
- Paystack recipient codes for till/paybill transfers to confirm with Paystack
- Plans only differ by price and **product limit** (enforced). Not built yet: adding staff logins (so the staff limit isn't used), branches, using a shop's own domain, and switching features on or off per plan. Edit the plan wording under Site content so it only promises what exists.
- Quotations list, eTIMS
- Pricing on the landing page is a placeholder until Topline confirms final prices
