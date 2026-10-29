# Going live now: website on Hostinger, backend on Render

This is the **free starting setup**. When Topline grows, move everything to one VPS with [DEPLOY.md](DEPLOY.md).

```
Customers / shop owners
   │
   ▼
https://toplinehub.co.ke              ──►  Hostinger (your Business plan)
   the website: landing page, shops at /s/<shop>, shop admin, Topline admin
   │  the website calls
   ▼
https://api.toplinehub.co.ke          ──►  Render (free)
   the Flask backend: logins, orders, payments, photos
   │
   ▼
Neon (free, permanent PostgreSQL)          all the data, including photos

Hostinger Cron Jobs ── every 10 min: keeps Render awake
                    └─ every 30 min: settles unanswered M-PESA prompts, daily billing check
```

Everything deploys from **one GitHub repository**. After setup, updating the live site is just `git push`.

**Limits of the free setup:**

- **Shop links** look like `toplinehub.co.ke/s/mjengo`. `mjengo.toplinehub.co.ke` comes with the VPS.
- **Render sleeps** after 15 minutes without visitors. The cron job below keeps it awake, but if it ever does sleep, the first visit takes about a minute. The site shows "Starting up…" while it wakes.
- **Neon** gives 0.5 GB of data on the free plan. That's plenty to start, because photos are shrunk on the phone before upload.

Allow about an hour for the first setup.

---

## Step 1: Put the code on GitHub

1. Create a free account at **github.com** if you don't have one.
2. Click **New repository**:
   - Name it `topline-os`.
   - Make it **Private**.
   - Don't tick any of the "add README / .gitignore" boxes.
   - Click **Create**.
3. On your laptop, unzip the new `topline-os.zip` over your folder, then push it:

   ```bash
   cd ~/topline-os
   git init
   git add .
   git commit -m "Topline OS"
   git branch -M main
   git remote add origin https://github.com/YOUR-USERNAME/topline-os.git
   git push -u origin main
   ```

   When it asks for a password, GitHub needs a **token**, not your account password. Create one at GitHub → Settings → Developer settings → Personal access tokens → **Fine-grained token**, with access to this repository and **Contents: Read and write**. Paste the token as the password.

   Your `.env` files, `node_modules`, `.venv` and local database are **not** uploaded; `.gitignore` excludes them.

## Step 2: Create the database (Neon)

1. Go to **neon.com** and sign up (you can use "Continue with GitHub").
2. Create a project:
   - **Name:** `topline`
   - **Region:** **AWS Europe Central 1 (Frankfurt)**, the same region as Render, so they talk fast.
3. Open **Connect** (or Connection details):
   - Turn **Connection pooling off**, so you get the direct connection.
   - Copy the connection string. It looks like `postgresql://neondb_owner:xxxx@ep-xxxx.eu-central-1.aws.neon.tech/neondb?sslmode=require`.

Keep it somewhere private: it is the key to all your data.

## Step 3: Start the backend (Render)

1. Go to **render.com** and sign up with **GitHub**. Allow it to see the `topline-os` repository.
2. Click **New** → **Blueprint**, pick `topline-os`. Render reads `render.yaml` from the project.
3. It asks for two values:
   - **DATABASE_URL:** paste the Neon connection string.
   - **ADMIN_PHONES:** your admin login, for example `Felix:0712000000`. Separate several with commas.
4. Click **Apply** or **Deploy**. The first build takes about 5 minutes.
5. When it shows **Live**, open `https://topline-api.onrender.com/api/health` (Render shows the exact address on the service page). You should see `{"ok":true,...}`.

## Step 4: Give the backend your address: api.toplinehub.co.ke

1. In Render, open the **topline-api** service → **Settings** → **Custom Domains** → **Add**, and enter `api.toplinehub.co.ke`. Render shows a target such as `topline-api.onrender.com`.
2. In **Hostinger hPanel** → **Domains** → toplinehub.co.ke → **DNS / Nameservers** → **DNS records**, add:

   | Type | Name | Target |
   | --- | --- | --- |
   | CNAME | `api` | `topline-api.onrender.com` (exactly what Render showed) |

3. Back in Render, click **Verify**. HTTPS is set up automatically, usually within minutes.

If toplinehub.co.ke's DNS isn't managed at Hostinger (for example it's at Truehost), add the record there instead.

## Step 5: Publish the website (Hostinger)

The website is already set up for this: it calls `https://api.toplinehub.co.ke` and shows shop links as `/s/<shop>`. Those settings are in `.env.production`.

### Option A: from GitHub (automatic updates; recommended)

1. In hPanel go to **Websites** → **Add website** and look for **Node.js / Web app**, or **Import from GitHub**. Connect your GitHub account and pick `topline-os`.
2. Check the build settings:
   - **Framework:** Vite (or React)
   - **Node version:** 22
   - **Build command:** `npm run build`
   - **Output directory:** `dist`
3. **Domain:** deploying to `toplinehub.co.ke` **replaces the current website** there, so check with your team first. To test safely, use a subdomain such as `new.toplinehub.co.ke` first, then switch.

Every `git push` then rebuilds and republishes the site.

### Option B: upload the files yourself

```bash
cd ~/topline-os
npm run build
```

Then, in hPanel → **Files** → **File manager**, open `public_html` (back up anything already there) and upload **everything inside** the `dist` folder. Include the hidden `.htaccess` file, which makes links like `/admin` work when refreshed.

### If you test on a temporary Hostinger address

If the website is reached at something like `xyz.hostingersite.com`, the backend must allow that address. In Render → **Environment**, add it to `FRONTEND_ORIGINS` (comma-separated) and save; Render restarts by itself. Addresses ending in `.toplinehub.co.ke` are already allowed.

## Step 6: Background jobs (Hostinger Cron Jobs)

In Render → **Environment**, copy the value of **CRON_SECRET**.

In hPanel → **Websites** → toplinehub.co.ke → **Advanced** → **Cron Jobs**, add two **custom commands**:

| Every | Command | Why |
| --- | --- | --- |
| 10 minutes | `curl -s https://api.toplinehub.co.ke/api/ping > /dev/null` | Keeps Render awake. It doesn't touch the database, so Neon can sleep. |
| 30 minutes | `curl -s "https://api.toplinehub.co.ke/api/cron/run?key=PASTE_CRON_SECRET" > /dev/null` | Cancels M-PESA prompts nobody answered (stock goes back on sale), and runs the daily billing check after 06:00. |

If Hostinger's cron doesn't accept `curl`, use the free **cron-job.org** with the same two addresses.

## Step 7: Log in

1. Open `https://toplinehub.co.ke/login` (or your test address) and enter your number from `ADMIN_PHONES`.
2. SMS isn't switched on yet, so the code is **in the Render logs**. Open Render → topline-api → **Logs** and search for `Topline code`.
3. You're in the Topline admin. The site starts empty; real shops sign up at `/start`.

## Before shops use it

All of these are set in **Render → topline-api → Environment**. Render restarts after you save.

- **SMS (needed for shop owners to log in):**

  ```
  SMS_MODE=africastalking
  AT_USERNAME=...
  AT_API_KEY=...
  AT_SENDER_ID=TOPLINE    # optional, once approved
  ```

- **Paystack (real payments).** Until this is on, payments are simulated and anyone can sign up for free.

  ```
  PAYMENTS_MODE=paystack
  PAYSTACK_SECRET_KEY=sk_live_...
  PAYSTACK_PUBLIC_KEY=pk_live_...
  ```

  In the Paystack dashboard, set the webhook URL to `https://api.toplinehub.co.ke/api/webhooks/paystack` and enable Transfers.

- **Alerts:** `ALERT_WEBHOOK_URL=` a Slack, Discord or Teams webhook.

## Updating later

```bash
cd ~/topline-os
git add .
git commit -m "What changed"
git push
```

Render redeploys the backend and applies database changes by itself. Hostinger rebuilds the website if you used Option A; with Option B, upload `dist` again.

## Moving to a VPS later

1. Copy the data out of Neon on your laptop:

   ```bash
   pg_dump "NEON_CONNECTION_STRING" --format=custom --file=topline.dump
   ```

2. Follow [DEPLOY.md](DEPLOY.md) to set up the VPS.
3. On the VPS, restore the data:

   ```bash
   sudo -u topline pg_restore --clean --if-exists -d topline topline.dump
   ```

4. On the VPS, set `UPLOAD_STORAGE=database` in its `.env` so photos already in the data keep working.
5. Point the DNS `@` and `*` records at the VPS. Shop links then also work as `shop.toplinehub.co.ke`.
