"""Paying for a plan (sign-up and renewals).

POST /billing/subscription/pay   sends the M-PESA prompt for the exact plan price, returns at once
GET  /billing/payments/<ref>     the waiting screen polls this until "paid" or "failed"
"""
from flask import Blueprint

from ..auth.guards import current_user
from ..auth.permissions import MANAGE_BILLING, permissions_for
from ..errors import Forbidden, Invalid, NotFound
from ..extensions import db
from ..models import Payment, Store
from ..services import billing, payment_records
from ..utils.phone import normalize_phone
from ..utils.request import body
from ..utils.text import iso

bp = Blueprint("billing", __name__, url_prefix="/billing")


@bp.post("/subscription/pay")
def pay_subscription():
    """Two cases, chosen by the caller, never guessed from who is logged in:
    - {"signup": true}: paying for a NEW store (claimed afterwards by POST /stores)
    - otherwise: a logged-in owner renewing or changing their own store's plan
    """
    data = body()
    phone = normalize_phone(data.get("phone"))
    if not phone:
        raise Invalid("Enter the M-PESA number to charge.", "phone")

    store = None
    user = None if data.get("signup") else current_user(optional=True)
    if user is not None and not user.is_platform_admin:
        membership = user.primary_membership()
        if membership:
            if MANAGE_BILLING not in permissions_for(membership.role):
                raise Forbidden("Only the shop owner can pay for the plan.")
            store = membership.store

    period = data.get("period") or (store.billing_period if store else "monthly")
    plan_id = data.get("planId") or (store.plan_id if store else None)
    payment = billing.pay_subscription(plan_id=plan_id, period=period, phone=phone, store=store)
    return payment_records.describe(payment), 202


@bp.get("/payments/<reference>")
def payment_status(reference):
    payment = db.session.get(Payment, reference)
    if payment is None or payment.kind != "subscription":
        raise NotFound("Payment not found.")
    payment_records.refresh(payment)
    data = payment_records.describe(payment)
    if payment.store_id:
        store = db.session.get(Store, payment.store_id)
        data["nextBillingAt"] = iso(store.next_billing_at)
    return data
