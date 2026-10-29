"""Payment provider callbacks. Always answer 200 quickly so Paystack doesn't retry forever."""
import logging

from flask import Blueprint, request

from ..errors import Unauthorized
from ..extensions import db
from ..models import Payout
from ..services import payment_records, payouts
from ..services.payments import get_gateway

bp = Blueprint("webhooks", __name__, url_prefix="/webhooks")
log = logging.getLogger(__name__)


@bp.post("/paystack")
def paystack():
    gateway = get_gateway()
    if not gateway.verify_webhook_signature(request.get_data(), request.headers.get("x-paystack-signature", "")):
        raise Unauthorized("Bad signature.")
    parsed = gateway.parse_webhook(request.get_json(silent=True) or {})
    if not parsed:
        return {"received": True}
    kind, reference, result = parsed
    if kind == "charge":
        payment = payment_records.record_result(reference, result)
        log.info("Charge webhook %s -> %s", reference, payment.status if payment else "unknown reference")
    elif kind == "transfer":
        payout = db.session.scalar(db.select(Payout).filter_by(reference=reference))
        if payout and payout.status != "paid":
            payouts.apply_result(payout, result)
            db.session.commit()
    return {"received": True}
