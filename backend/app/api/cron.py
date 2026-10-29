"""Background jobs over HTTP, for hosts without their own timers (Render free + Hostinger Cron Jobs).

    curl -s "https://api.toplinehub.co.ke/api/cron/run?key=CRON_SECRET"

Each call settles abandoned M-PESA prompts (releasing their stock); once a day, after 06:00
Kenyan time, it also runs the billing sweep. On a VPS the systemd timers do this instead.
"""
import hmac
from datetime import datetime
from zoneinfo import ZoneInfo

from flask import Blueprint, current_app, request

from ..errors import NotFound
from ..extensions import db
from ..models import SiteSetting
from ..services import billing
from ..services.payment_records import sweep_pending

bp = Blueprint("cron", __name__)
LAST_BILLING_RUN = "_cron.billingSweepDate"  # internal; never shown as site content


@bp.route("/cron/run", methods=["GET", "POST"])
def run():
    secret = current_app.config["CRON_SECRET"]
    given = request.args.get("key") or request.headers.get("X-Cron-Key", "")
    if not secret or not hmac.compare_digest(given.encode(), secret.encode()):
        raise NotFound("Not found.")  # look like nothing is here

    settled = sweep_pending()

    now = datetime.now(ZoneInfo(current_app.config["TIMEZONE"]))
    today = now.date().isoformat()
    row = db.session.get(SiteSetting, LAST_BILLING_RUN)
    billing_changed = None
    if now.hour >= 6 and (row is None or row.value != today):
        billing_changed = billing.sweep()
        if row is None:
            db.session.add(SiteSetting(key=LAST_BILLING_RUN, value=today))
        else:
            row.value = today
        db.session.commit()
    return {"paymentsSettled": settled, "billingSweep": billing_changed if billing_changed is not None else "already ran today"}
