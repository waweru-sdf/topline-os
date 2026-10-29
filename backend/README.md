# Topline API (Flask)

Backend for Topline OS. Serves the React frontend (`topline-os`) with stores, products, sales, customers, subscriptions and the two admin areas.

- **Shop admin** (hardware owner): `/api/vendor/stores/<id>/...`. Owners and their staff reach **only their own store**.
- **Topline admin** (overall): `/api/admin/...` for every store, plus full access to any store's shop admin.

Payments (Paystack) and SMS are **simulated** for now: no money moves and no SMS is sent. Switching to the real services is a config change once the adapters are filled in (see [Payments](#payments)).

## Run it

Needs Python 3.12+.

```bash
python -m venv .venv
# Windows: .venv\Scripts\activate     Mac/Linux: source .venv/bin/activate
pip install -r requirements-dev.txt
copy .env.example .env               # Mac/Linux: cp .env.example .env
flask --app wsgi seed                # creates the database + demo data
flask --app wsgi run --debug         # http://localhost:5000/api/health
```

Then connect the frontend: in `topline-os/.env` set

```
VITE_API_URL=http://localhost:5000/api
```

and restart `npm run dev`. The frontend needs this API running.

### Demo logins

While `SMS_MODE=simulation`, the login page shows the code. The demo accounts always use **123456**.

Admins: 0700 000 000 and 0712 000 000. Shop owners: Mjengo 0712 345 678, Mwamba 0733 444 555, Baraka 0722 118 404, Coast Steel 0711 450 330, Kilimani (past due) 0733 902 117, Rift Timber (suspended) 0720 664 018, Nyeri Plumb (draft) 0798 221 560. `flask --app wsgi seed` prints the full list with links.

Add a real admin with `flask --app wsgi create-admin "Jane Doe" 0711000000`.

### Tests

```bash
pytest
```

103 tests cover login, live SMS and production safety checks, store isolation, admin access, hidden buying prices, orders, the simulated M-PESA prompt (approve, decline, timeout), stock holding, delivery fees, direct payments, payouts, photo uploads, site content, monitoring, the Paystack adapter (with mocked HTTP), counter and credit sales, sign-up billing, the billing sweep and CORS.

## Project layout

```
wsgi.py                    entry point (flask / gunicorn)
app/
  __init__.py              create_app(): config, extensions, CORS, blueprints
  config.py                all settings, from environment / .env
  extensions.py            db, migrate, cors
  errors.py                ApiError -> {"message", "field"} JSON
  catalog.py               product categories
  seed.py, cli.py          `flask seed`, `flask billing-sweep`, `flask create-admin`
  models/                  one file per table group
    store.py user.py plan.py product.py sale.py customer.py payment.py quote.py otp.py
  serializers/             model -> camelCase JSON the frontend expects
  auth/
    tokens.py              JWT issue/read
    permissions.py         what owner / manager / cashier may do
    guards.py              login, platform-admin and per-store access checks
  services/                business logic (routes stay thin)
    stores.py billing.py orders.py sales.py products.py customers.py
    dashboard.py otp.py sms.py stock.py payment_records.py
    payments/              gateway interface + simulation + Paystack stub
  api/                     routes, one blueprint per area
    public.py auth.py signup.py billing.py storefront.py admin.py webhooks.py
    vendor/                store.py products.py sales.py customers.py quotes.py
tests/
```

## Who can do what

| | Customer | Cashier | Manager | Owner | Topline admin |
| --- | --- | --- | --- | --- | --- |
| Browse store, order, request quote | ✓ | ✓ | ✓ | ✓ | ✓ |
| Sell at counter, handle orders | | ✓ | ✓ | ✓ | ✓ |
| See buying prices, profit, dashboard | | | ✓ | ✓ | ✓ |
| Edit products, customers' credit limits | | | ✓ | ✓ | ✓ |
| Store settings, pay for plan | | | | ✓ | ✓ |
| Every store, suspend/reactivate, plans | | | | | ✓ |

A user from one store asking for another store's data gets "not found", so store ids can't be probed. Staff accounts (manager/cashier) exist in the data model; inviting them from the UI comes next.

## API

All routes are under `/api`. Errors look like `{"message": "...", "field": "phone"}`.

| Area | Routes |
| --- | --- |
| Public | `GET /health`, `GET /plans`, `GET /stores/check-slug?slug=` |
| Login | `POST /auth/otp`, `POST /auth/otp/verify`, `GET /auth/me` |
| Sign-up | `POST /billing/subscription/pay` then `POST /stores` |
| Billing | `POST /billing/subscription/pay` (logged-in owner renews or changes plan), `GET /billing/payments/<ref>` |
| Storefront | `GET /storefront/<slug>`, `GET …/products/<slug>`, `POST …/orders`, `GET …/orders/<id>/payment`, `POST …/quotes` |
| Shop admin | `GET /vendor/stores` (stores I can open), and under `/vendor/stores/<id>`: `GET`/`PATCH` store, `GET dashboard`, `products` (CRUD + `/stock`), `sales` (list, counter sale, order status), `customers` (+ `/payments`), `quotes`, `payouts` |
| Topline admin | `GET /admin/overview`, `GET /admin/stores`, `PATCH /admin/stores/<id>` (`status`, `planId`, `extendDays`), `GET /admin/plans`, `GET /admin/payments`, `GET /admin/payouts`, `POST /admin/payouts/<id>/retry`, `PUT /admin/site`, `GET /admin/incidents`, `PATCH /admin/incidents/<id>` |
| Media & site | `POST /uploads`, `GET /uploads/<file>`, `GET /site`, `POST /telemetry/error` |
| Simulation | `POST /simulator/payments/<ref>` (the on-screen test phone) |
| Webhooks | `POST /webhooks/paystack` |

## Payments

Everything goes through `app/services/payments/`:

- `base.py`: the interface (`charge_mpesa`, `verify`, `transfer`, webhook signature and parsing).
- `simulation.py`: **in use now**. With `SIMULATION_APPROVAL=prompt` the customer approves on an on-screen test phone; with `auto` every charge is paid after `SIMULATED_PAYMENT_DELAY_SECONDS`. Numbers ending in **999** always fail.
- `paystack.py`: **complete and ready**. M-PESA charge (`POST /charge`), check (`GET /transaction/verify`), transfers to shops (`/transferrecipient` + `/transfer`) and webhooks (`charge.*`, `transfer.*`, HMAC-SHA512 checked). Tested against mocked responses.

To go live: set `PAYMENTS_MODE=paystack`, `PAYSTACK_SECRET_KEY` and `PAYSTACK_PUBLIC_KEY`, point the Paystack webhook to `https://<api-host>/api/webhooks/paystack`, enable Transfers, and confirm the codes in `PAYSTACK_RECIPIENT` with Paystack.

**Orders:** `services/orders.py` holds stock atomically (`services/stock.py`), adds the delivery fee (`services/delivery.py`) and either sends a prompt or waits for the shop to confirm a direct payment. A paid order triggers a payout to the shop (`services/payouts.py`) and an SMS/WhatsApp alert. Unpaid prompts expire after `PAYMENT_TIMEOUT_MINUTES` and release the stock.

**Subscriptions:** sign-up pays first, then `POST /stores` claims that payment, so a store can't open without paying. A paid plan sets `nextBillingAt`. Run `flask --app wsgi billing-sweep` daily (cron): overdue stores become **past due** (store still open), then **suspended** after `GRACE_DAYS` (default 7). Paying reactivates the store. M-PESA can't auto-debit, so renewals are a new prompt each period; card auto-renew arrives with Paystack.

## Production notes

The server setup is in `../deploy` and `../DEPLOY.md`. In short:

- `APP_ENV=production` makes the API refuse to start with unsafe settings: weak `SECRET_KEY`/`JWT_SECRET`, `EXPOSE_DEV_OTP=true`, SQLite, or missing SMS/Paystack keys for the chosen mode.
- PostgreSQL via `DATABASE_URL=postgresql+psycopg://…`; runs on gunicorn behind Caddy with `TRUST_PROXY=true`.
- A fresh live database: `flask --app wsgi init-db` (tables + plans, no demo data), then `create-admin`.
- Schema changes: edit the models, then `flask --app wsgi db migrate -m "what changed"`, check the file in `migrations/versions/`, and ship it. The server runs `db upgrade` on update.
- Jobs: `payments-sweep` every 5 minutes, `billing-sweep` daily. Both run as systemd timers.
- `GET /api/tls/check?domain=` tells Caddy which hosts may get an HTTPS certificate (the main domain and existing stores only).
- Real SMS: `SMS_MODE=africastalking` with `AT_USERNAME`, `AT_API_KEY` and optional `AT_SENDER_ID` (`services/sms.py`).
- On Render free + Neon (see `../HOSTING.md` and `../render.yaml`): `UPLOAD_STORAGE=database` keeps photos in PostgreSQL, `DATABASE_POOL=none` lets Neon sleep when idle, `GET /api/ping` keeps Render awake without touching the database, `GET /api/cron/run?key=CRON_SECRET` runs the background jobs, and `ADMIN_PHONES` + `flask ensure-admins` create admins without a shell.
- Tests run on SQLite by default, or on PostgreSQL with `TEST_DATABASE_URL=postgresql+psycopg://…`.

## Not built yet

- Inviting staff, quotations-to-sale, suppliers, expenses
- KRA eTIMS
