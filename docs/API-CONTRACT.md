# Topline API contract (for the Laravel backend)

The React website talks to the backend only through `src/services/api.js`. The backend is **Laravel + MySQL** in `laravel/`, for Hostinger Business hosting. `laravel/tests/Feature/ContractExamplesTest.php` replays every example in `api-examples.json` and checks the status codes, keys and value types, so the website and the API can't drift apart.

- **Every endpoint with a real request and response:** [`api-examples.json`](api-examples.json). When an endpoint changes on purpose, update its example here too.
- **Business rules:** this file.

## 1. Hosting shape (Hostinger Business plan)

```
toplinehub.co.ke
├── /api/*        Laravel (routes/api.php), PHP 8.3+, MySQL 8 / MariaDB
├── /             React build (dist/) served by Laravel's public/ with an index.html fallback
└── /s/<shop>     the same React app (shop links are paths: VITE_STORE_LINKS=path)
```

- **One domain, so no CORS:** the React build uses `VITE_API_URL=/api`.
- **Background jobs:** Hostinger **Cron Jobs** runs `php artisan schedule:run` every minute. The schedule runs:
  - `payments:sweep` every 5 minutes
  - `billing:sweep` daily at 06:15 Africa/Nairobi
- **Uploads:** stored under `storage/app/uploads`, which survives deploys. Served at `GET /api/uploads/{id}`.

## 2. Conventions

| Thing | Rule |
| --- | --- |
| JSON keys | camelCase (`sellPrice`, `storeId`, `mustChangePassword`) |
| Money | whole Kenyan shillings, integers (`2650` = KSh 2,650) |
| Dates | ISO 8601, UTC, with milliseconds and a `Z` (`2026-10-29T08:00:00.000Z`) |
| IDs | strings with a prefix: `st_…` stores, `u_…` users, `prd_…` products, `cu_…` customers, `q_…` quotes. Sales/orders are shown as `S-123` (integer number with prefix). |
| Phones | stored as `2547XXXXXXXX`; accepted in any Kenyan form (`0712 345 678`, `+254712345678`, `254712345678`); shown as `0712 345 678` |
| Errors | `{"message": "Human sentence.", "field": "phone" \| null}` with 401 not logged in, 402 payment needed or plan limit, 403 not allowed, 404 not found, 409 conflict (stock), 422 invalid input (`field` names the input), 429 too many attempts, 503 provider down |
| Lists | plain JSON arrays |

## 3. Accounts and login (no SMS codes)

- **Log in:** `POST /auth/login {login, password}`. `login` is a phone (any form) or an email (case-insensitive, trimmed). Returns `{user, token}`; the token is sent as `Authorization: Bearer <token>` (Laravel Sanctum personal access tokens are fine, since the frontend never reads inside the token).
- **Wrong password and unknown account** give the same `401 {"message": "Wrong phone/email or password.", "field": "password"}`.
- **Lockout:** 5 wrong passwords in a row lock that account for 15 minutes: `429 "Too many wrong attempts. Try again in N minutes."`, field `password`.
- **Switched-off account** (`is_active = false`): `403 "This account is switched off. Contact Topline."`
- **Passwords:** at least 8 characters, at most 128; refuse all-same-digit passwords like `11111111`. Hash with bcrypt (Laravel `Hash::make`).
- **Change password:** `POST /auth/password {currentPassword, newPassword}`. After an admin reset, `currentPassword` isn't required. The new password must differ from the old one. **Every other token for that user stops working** (delete them). Returns a fresh `{user, token}`.
- **Admin reset:** `POST /admin/stores/{id}/reset-password` returns `{temporaryPassword, owner}`.
  - Temporary password: 10 characters from `abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789`.
  - Sets `must_change_password`, signs the owner out everywhere, clears any lockout.
- **While `mustChangePassword` is true,** every endpoint except `GET /auth/me` and `POST /auth/password` answers `403 {"message": "Choose a new password first.", "field": "newPassword"}`. The website sends the user to `/change-password` when it sees this.
- **Sign-up** is `POST /stores` (§6). It creates the owner with `name, phone, email?, password`. Validate the password (field `ownerPassword`) and email (field `ownerEmail`, unique) **before** using the plan payment, so a mistake never wastes the payment.
- **User JSON:** `{id, name, phone, email, role: "vendor"|"platform_admin", mustChangePassword, storeId, storeRole: "owner"|"manager"|"cashier"|null}`.
- **Admin command** (no SMS needed): `php artisan topline:create-admin "Felix" 0712000000 --email=… --password=…`

## 4. Who can do what

Topline admins (`platform_admin`) can do everything in every store. Inside a store:

| Permission | Cashier | Manager | Owner |
| --- | :-: | :-: | :-: |
| view products, sell at counter, handle online orders, view customers | ✓ | ✓ | ✓ |
| dashboard, buying prices & margins, add/edit products & stock, manage customers & credit | | ✓ | ✓ |
| store settings, payouts, activity, plan & billing | | | ✓ |

- A user asking for **another store's** data gets **404**, not 403, so store IDs can't be probed.
- **Buying prices** (`buyPrice`) are never sent to customers or cashiers.

## 5. Two customers, not enough stock (overselling)

Stock is held **the moment an order is placed**, inside one database transaction, with a conditional update. This is the core rule:

```sql
UPDATE products SET stock = stock - :qty WHERE id = :id AND stock >= :qty
```

1. **One update per item in the cart.** If **any** update changes 0 rows, roll back the whole order. Return `409`, field `qty`:
   - `"Only N left of NAME. Lower the quantity and try again."`
   - `"NAME just sold out."` if none are left
2. **The database row lock makes this safe.** Two customers buying the last 5 bags at the same instant: exactly one succeeds and the other gets the 409. Never check `stock >= qty` in PHP and then save, because that is the race.
3. **Prompt payments:** the order waits as `awaiting_payment`.
   - If M-PESA says **failed/declined**, or nobody answers within `PAYMENT_TIMEOUT_MINUTES` (10), the order becomes `cancelled` and every item's stock is **put back** (`stock = stock + qty`).
   - The **payments sweep** (every 5 minutes) re-checks prompts nobody is watching any more (customer closed the page): paid ones complete, stale ones are released.
4. **Direct payments** (customer paid the shop's Pochi or phone themselves): the order waits as `awaiting_confirmation` with the customer's M-PESA code (`^[A-Z0-9]{8,12}$`, upper-cased). Stock is held. The shop taps "Payment received" (→ `new`, sets `paidAt`) or "cancel", which puts the stock back.
5. **Counter sales** take stock immediately and may go below zero (goods physically sold).
6. **Every stock change writes a `stock_movements` row:** `delta`, `reason` = sale | online_order | adjust | released | cancelled | payment_failed, `reference`, `user`.

**Order status flow:**

| From | To |
| --- | --- |
| `awaiting_payment` | `new` (paid) or `cancelled` |
| `awaiting_confirmation` | `new` or `cancelled` |
| `new` | `confirmed`, `cancelled` |
| `confirmed` | `dispatched`, `completed` (picked up at the shop), `cancelled` |
| `dispatched` | `completed` |

Anything else is `422` field `status`.

## 6. Money

### How shops get paid

Set by the owner in Settings → Getting paid. `payments` JSON: `{method, number, account, name, acceptsDirect}`.

| method | number rule | account | Prompt possible | Direct possible |
| --- | --- | --- | :-: | :-: |
| `till` | 5–8 digits | – | ✓ | ✓ |
| `paybill` | 5–8 digits | required | ✓ | ✓ |
| `pochi` | Kenyan phone | – | ✓ | ✓ |
| `send_money` | Kenyan phone | – | ✓ | ✓ |

**Prompt payments** go through Topline's Paystack account. After the payment is confirmed, the backend **transfers** the money (minus `PLATFORM_COMMISSION_PERCENT`, default 0) to the shop's till, paybill or phone. Each transfer is a `payouts` row with status pending, paid or failed, and admins can retry failed ones.

### Payment gateway (Paystack, simulated until keys are added)

There is one interface with two implementations, picked by `PAYMENTS_MODE=simulation|paystack`. The code is in `laravel/app/Services/Payments/`.

**Charge:** `POST https://api.paystack.co/charge`

```json
{"email": "2547XXXXXXXX@toplinehub.co.ke", "amount": KES*100, "currency": "KES", "reference": "...",
 "mobile_money": {"phone": "+2547...", "provider": "mpesa"}}
```

**Verify:** `GET /transaction/verify/{ref}`. `success` = paid; `pending`, `pay_offline`, `send_otp`, `processing`, `ongoing` or empty = still waiting; anything else = failed (use `gateway_response` as the reason). The M-PESA receipt comes from `receipt_number`.

**Transfers:** `POST /transferrecipient`, then `POST /transfer`. Recipient types:

| Method | Type | Bank code | Notes |
| --- | --- | --- | --- |
| send_money / pochi | `mobile_money` | `MPESA` | |
| till | `mobile_money_business` | `MPTILL` | |
| paybill | `mobile_money_business` | `MPPAYBILL` | plus `account_reference` |

Confirm these codes with Paystack before going live.

**Webhook:** `POST /api/webhooks/paystack`. Check the `x-paystack-signature` header (HMAC-SHA512 of the raw body with the secret key). Handle `charge.success`, `charge.failed`, `transfer.success`, `transfer.failed` and `transfer.reversed`.

**Simulation:**
- `SIMULATION_APPROVAL=prompt`: the website shows a test phone that calls `POST /simulator/payments/{ref} {action: approve|decline, pin}` (4-digit PIN required to approve).
- `SIMULATION_APPROVAL=auto`: payments are approved after `SIMULATED_PAYMENT_DELAY_SECONDS`.
- Numbers ending in **999** always fail.
- Transfers succeed at once.
- The simulator endpoint returns 404 when not simulating.

### Plans (paid to Topline)

Starter 1,500 / 15,000, Business 3,500 / 35,000, Pro 7,500 / 75,000 (monthly / yearly KSh).

**Sign-up order of steps:**
1. `POST /billing/subscription/pay {phone, planId, period, signup: true}` → `202` pending payment.
2. Poll `GET /billing/payments/{ref}` until paid.
3. `POST /stores` with `billing.paymentReference`. The payment must be paid, unclaimed, for that plan and not linked to a store; otherwise `402`. The period comes from the payment, not from the client.

**Billing over time:**
- Paying early adds the time on top of what's left.
- **Daily billing sweep:** active stores past their due date become `past_due` (still open). After `GRACE_DAYS` (7) they become `suspended` (storefront shows closed).
- Paying again reactivates the store.
- Admin `PATCH /admin/stores/{id} {status | planId | extendDays}`.

### Delivery fees

Store settings `delivery`: `{enabled, mode: zones|quote, zones: [{id, name, fee}], freeOver, note}`.

- **Pickup** is free.
- **`zones`:** the customer picks a zone; fee = zone fee, or 0 if the subtotal ≥ `freeOver`. Missing zone → `422 zoneId`.
- **`quote`:** fee 0, zone "Agreed with the shop" (the shop phones with the lorry price).
- **Missing address** for delivery → `422 address`.
- The prompt amount is subtotal + fee.

## 7. Products

```json
{ "id", "slug", "name", "categoryId", "unit", "sellPrice", "buyPrice (staff with costs only)", "stock", "reorderAt",
  "image", "gallery": [], "size": "20 mm × 12 m", "specs": [{"label", "value"}], "description", "altUnits", "vatClass", "published" }
```

| Field | Rule |
| --- | --- |
| `image` | a **ready-made picture key** (e.g. `rebar`, `cement`, from `src/lib/images.js`) or an uploaded photo URL |
| `gallery` | up to 3 more |
| `size` | the main measurement, up to 80 characters |
| `specs` | up to 8 label/value pairs. Blank rows are dropped; half-filled rows → `422 specs` |
| `description` | up to 500 characters |
| `categoryId` | one of the catalogue ids in `laravel/app/Services/Catalog.php` |

- **Plan limits:** Starter 150 products, Business 1,500, Pro unlimited. Over the limit → `402`.
- **Slugs** are unique per store (`name`, `name-2`, …).
- **Typing a new stock number** in the editor records an `adjust` movement for the difference.

**Uploads:** `POST /uploads` (multipart `file`, `storeId`) → `201 {id, url}`.
- Check the **actual bytes**: JPEG `FF D8 FF`, PNG `89 50 4E 47 0D 0A 1A 0A`, WebP `RIFF....WEBP`. Max 5 MB.
- Random name `<24 hex>.<ext>`. Served with `Cache-Control: public, max-age=31536000, immutable`.
- Shop staff need "add/edit products"; without `storeId`, Topline admins only.
- The website already shrinks photos to 1600 px before uploading.

## 8. Activity log (admin "check all activity", owner "Activity" page)

Table `activity_log(id, store_id?, actor_id?, actor_name, kind, summary, data JSON, created_at)`. Write the row **in the same transaction** as the change.

| kind | When | Example summary |
| --- | --- | --- |
| `store_created` | sign-up | "Grace Akinyi opened Baraka Hardware on the Business plan" |
| `store_status` | admin suspends/reactivates/changes plan/extends; billing sweep | "Topline Admin suspended Rift Timber Yard" |
| `plan_paid` | renewal confirmed | "Paid KSh 3,500 for the Business plan (monthly), ref …" |
| `settings_updated` | store settings saved | "Updated how they get paid" |
| `login` | successful login | "Peter Kamau logged in" |
| `password_changed` / `password_reset` | | "Topline Admin reset the password for Peter Kamau" |
| `product_added` / `product_updated` / `product_deleted` | | "Added Deformed bar Y20 (20 mm × 12 m), 80 length (12 m) in stock" |
| `stock_adjusted` | stock number changed | "Stock of Deformed bar Y20 changed 120 → 100" |
| `order_placed` | online order | "Daniel K ordered 2 × Claw hammer, 16 oz — KSh 1,900 (waiting for M-PESA)" |
| `order_paid` | prompt confirmed | "S-123 paid by M-PESA (KSh 1,900, ref QJK…)" |
| `order_status` | status change, auto-cancel | "S-123: new → confirmed" |
| `counter_sale` | till sale | "Counter sale S-124: 3 × Tile adhesive — KSh 2,850 (cash)" |
| `payout_sent` / `payout_failed` | transfer to shop | "Sent KSh 1,900 for S-123 to Till 5123456" |

Actors:
- the logged-in user, or
- `"Customer <name>"`, or
- `"System"` (sweeps, payouts).

**Endpoints:**
- `GET /admin/activity?storeId=&kind=&before=<id>&limit=50`
- `GET /vendor/stores/{id}/activity?kind=&before=` (owners and admins only)

Newest first, ordered by **created_at then id**. `before` = the id of the last entry already shown; return entries older than it.

Item JSON: `{id, kind, summary, actor, storeId, storeName, data, createdAt}`.

## 9. Monitoring

- **Page views:** `GET /site?view=landing` counts a main-site visit; each storefront load counts a shop visit (`page_views(day, store_id, page, views)`).
- **Requests:** every `/api` request except `/api/ping` and `/api/uploads/*` adds to an hourly row with count, server errors, client errors, total and slowest ms.
- **Incidents:** these are grouped by `sha256(kind|title|path)` with a count, first/last seen and resolved flag:
  - server errors (500)
  - failed payments
  - failed payouts
  - browser errors (`POST /telemetry/error`)
  - SMS failures
- **Alerts:** sent at the 1st, 10th, 100th, 1,000th and 10,000th occurrence, and when a resolved incident comes back. They go to `ALERT_WEBHOOK_URL` (Slack/Discord/Teams; send both `text` and `content`) and by SMS to `ALERT_SMS_PHONES`.
- `GET /admin/overview`: stores, money, customers, traffic and health. The exact shape is in the examples.

## 10. Small endpoints

| Endpoint | Behaviour |
| --- | --- |
| `GET /ping` | `{"ok": true}`, no database |
| `GET /health` | `{ok, payments, sms}` |
| `GET /cron/run?key=CRON_SECRET` | runs the payments sweep, plus the billing sweep once a day after 06:00 Nairobi. 404 without the right key. Only needed if `schedule:run` isn't available. |
| `GET /stores/check-slug?slug=` | `{slug, available, reason}`. Reserved: www, app, admin, api, mail, static, dashboard, login, start, s, help, support. At least 3 characters. |

## 11. Demo data (`php artisan topline:install --demo`)

- **Content** (`laravel/database/seeders/`): 3 plans, 2 admins and 7 shops, each with products, measurements, payout method, delivery zones and status. Mjengo also gets credit customers. Every shop gets 2 weeks of sales with matching activity and a month of page views.
- **Password for every demo account:** `topline123`.

**Admins:**

| Phone | Email |
| --- | --- |
| 0700 000 000 | admin@toplinehub.co.ke |
| 0712 000 000 | felix@toplinehub.co.ke |

**Owners:** log in with their phone, or with an email of the form `<first name>@<shop>.example.com`.

| Owner | Phone | Email |
| --- | --- | --- |
| Peter Kamau | 0712 345 678 | peter@mjengo.example.com |
| Juma Mwamba | 0733 444 555 | juma@mwamba.example.com |
| Grace Wanjiru | 0722 118 404 | grace@baraka-builders.example.com |
| Fatma Said | 0711 450 330 | fatma@coast-steel.example.com |
| Ali Hassan | 0733 902 117 | ali@kilimani-paints.example.com |
| Kiprono Rotich | 0720 664 018 | kiprono@rift-timber.example.com |
| Mary Muthoni | 0798 221 560 | mary@nyeri-plumb.example.com |
