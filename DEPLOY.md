# Putting Topline live on Hostinger

Topline needs a **Hostinger VPS**. Hostinger's shared and cloud web hosting can't run Flask (Python apps need a VPS there).

Everything runs on that one server:

```
Customer / shop owner
   │  https://toplinehub.co.ke   https://mjengo.toplinehub.co.ke   https://<any shop>.toplinehub.co.ke
   ▼
Caddy (web server, ports 80/443)
   ├─ /api/*   → Flask API (gunicorn, 127.0.0.1:8000) → PostgreSQL
   └─ the rest → the React website (dist/)
```

- **HTTPS for every shop is automatic.** The first time someone opens a new shop's link, Caddy gets a free certificate for it (Let's Encrypt). It only does this for shops that really exist. You never renew certificates by hand.
- **Background jobs** run as systemd timers:
  - a payments sweep every 5 minutes, which settles M-PESA prompts nobody is waiting on and releases their stock
  - the billing sweep daily at 06:15
  - backups daily at 02:30, kept for 14 days in `/var/backups/topline`

## 1. Get the VPS

1. In Hostinger, buy a **VPS** plan. **KVM 1** is enough to start; **KVM 2** gives room to grow.
2. When asked for the operating system, choose **Ubuntu 24.04** (plain OS, no control panel).
3. Set a strong root password. Note the server's **IP address** from hPanel → VPS → Overview.

## 2. Upload the code

From your laptop, in the folder that holds `topline-os.zip`:

```bash
scp topline-os.zip root@YOUR_SERVER_IP:/root/
ssh root@YOUR_SERVER_IP
```

(Or use **Browser terminal** in hPanel → VPS, and upload the zip with the file manager there.)

On the server:

```bash
apt-get update && apt-get install -y unzip
cd /root && unzip -o topline-os.zip
```

## 3. Run the setup (once)

```bash
cd /root/topline-os
sudo bash deploy/setup.sh toplinehub.co.ke you@youremail.com
```

It takes about 5–10 minutes. It installs everything, creates the database and random secret keys, builds the site, starts the services and turns on the firewall. At the end it prints your next steps.

## 4. Point the domain at the server

In hPanel → **Domains** → toplinehub.co.ke → **DNS / Nameservers** → DNS records (or wherever the domain's DNS is managed):

| Type | Name | Points to |
| --- | --- | --- |
| A | `@` | YOUR_SERVER_IP |
| A | `*` | YOUR_SERVER_IP |
| CNAME | `www` | toplinehub.co.ke |

- **Delete** the old `A` records for `@` and `www` that point at the current website. This step replaces today's toplinehub.co.ke, so save anything you need from it first.
- **Don't touch** `MX` and `TXT` records: those are your email.
- Changes usually take minutes, sometimes a few hours.

## 5. Make yourself the admin and log in

```bash
sudo -u topline -H bash -c "cd /opt/topline/app/backend && .venv/bin/flask --app wsgi create-admin 'Felix' 0712000000"
```

Open https://toplinehub.co.ke/login and enter your number. **Until SMS is set up (step 6), codes are not sent by SMS.** Read your code on the server:

```bash
sudo journalctl -u topline-api | grep "Topline code" | tail -3
```

The live site starts empty: no demo shops. Real shops sign up at https://toplinehub.co.ke/start.

## 6. Turn on real SMS (before shops sign up)

Shop owners log in with a code sent by SMS, so they can't log in until this is done.

1. Create an account at **Africa's Talking**, create an app, and top up SMS credit.
2. Optional: apply for a sender ID such as `TOPLINE`. Until it's approved, messages come from a shared number.
3. Edit the settings:

   ```bash
   sudo nano /opt/topline/app/backend/.env
   ```

   ```
   SMS_MODE=africastalking
   AT_USERNAME=your-app-username
   AT_API_KEY=your-api-key
   AT_SENDER_ID=TOPLINE        # leave empty until approved
   ```

4. Restart: `sudo systemctl restart topline-api`

To test first without sending real SMS, use `AT_USERNAME=sandbox` with your sandbox API key. Messages then appear in Africa's Talking's simulator instead.

## 7. Turn on real payments (Paystack)

**Until you do this, payments are simulated:** anyone can "pay" on the on-screen test phone, so sign-ups are effectively free. Keep the link to yourself until Paystack is on.

1. In `/opt/topline/app/backend/.env`:

   ```
   PAYMENTS_MODE=paystack
   PAYSTACK_SECRET_KEY=sk_live_...
   PAYSTACK_PUBLIC_KEY=pk_live_...
   PLATFORM_COMMISSION_PERCENT=0     # your cut of online orders, if any
   ```

2. In the Paystack dashboard:
   - Set the **webhook URL** to `https://toplinehub.co.ke/api/webhooks/paystack`.
   - Enable **Transfers** (this is how shops get paid) and keep the balance funded.
3. Ask Paystack to confirm the M-PESA till and paybill recipient codes. They're listed at the top of `backend/app/services/payments/paystack.py` (`PAYSTACK_RECIPIENT`).
4. Run `sudo systemctl restart topline-api`. Check with `curl -s https://toplinehub.co.ke/api/health`, which should show `"payments":"paystack"`.

Try `sk_test_` keys first.

## 8. Alerts when something breaks (optional)

Create an incoming webhook in a Slack, Discord or Microsoft Teams channel and add it to `.env`, then restart:

```
ALERT_WEBHOOK_URL=https://hooks.slack.com/services/...
ALERT_SMS_PHONES=0712000000      # also by SMS, once SMS is on
```

## Installing an update

Upload the new zip, unzip it, and run the update from inside it:

```bash
scp topline-os.zip root@YOUR_SERVER_IP:/root/
ssh root@YOUR_SERVER_IP
cd /root && rm -rf topline-os && unzip -oq topline-os.zip && cd topline-os
sudo bash deploy/update.sh
```

It backs up first, keeps your settings, photos and data, applies any database changes, and swaps in the new website with no downtime.

## Everyday commands

| What | Command |
| --- | --- |
| Is it running? | `systemctl status topline-api caddy` |
| API logs (live) | `sudo journalctl -u topline-api -f` |
| Web server logs | `sudo journalctl -u caddy -f` |
| Restart after editing `.env` | `sudo systemctl restart topline-api` |
| Background jobs and next run | `systemctl list-timers 'topline*'` |
| Back up now | `sudo -u topline /opt/topline/app/deploy/backup.sh` |
| Restore a backup | see the top of `deploy/backup.sh` |

Also turn on Hostinger's own VPS **snapshots/backups** in hPanel as a second safety net, and copy `/var/backups/topline` off the server now and then.

## Where things are on the server

| Path | What |
| --- | --- |
| `/opt/topline/app` | The code (replaced on update) |
| `/opt/topline/app/backend/.env` | Live settings and secrets (kept on update, readable only by the app) |
| `/var/lib/topline/uploads` | Photos uploaded by shops and admins |
| `/var/backups/topline` | Daily database and photo backups |
| `/etc/caddy/Caddyfile` | Web server and HTTPS (from `deploy/Caddyfile`) |
| `/etc/systemd/system/topline-*` | The API service and the background jobs (from `deploy/systemd/`) |

## If something goes wrong

- **The site won't load after the DNS change.** Wait a little longer, then check `dig +short toplinehub.co.ke` and `dig +short anyshop.toplinehub.co.ke`. Both should show your server IP.
- **A shop link shows a certificate error.** The shop's name must exist, and the `*` DNS record must be in place. Check `sudo journalctl -u caddy | tail -50`.
- **The API won't start.** Run `sudo journalctl -u topline-api | tail -30`. On a live server the API refuses to start with unsafe settings, such as weak secrets, codes shown on the page, or SQLite, and the log says which setting is wrong.
- **"We couldn't send the code".** The SMS provider rejected the message, often because of low credit or a wrong key. The details are on **System health** in the admin and in the API log.
