"""Store subscriptions: paying for a plan, renewals, grace period and suspension.

Paying is two steps, like any M-PESA prompt:
  1. pay_subscription()  -> prompt for the exact plan price, returns a pending Payment
  2. the screen polls /billing/payments/<ref> until it's paid or failed
For sign-up, the paid Payment is then claimed by POST /stores.
"""
from datetime import timedelta

from flask import current_app

from ..errors import ApiError, Invalid
from ..extensions import db
from ..models import Payment, Plan, Store
from ..models.base import utcnow
from ..models.payment import PAID
from ..models.store import ACTIVE, PAST_DUE, SUSPENDED
from . import payment_records

PERIOD_DAYS = {"monthly": 30, "yearly": 365}


def get_plan(plan_id):
    plan = db.session.get(Plan, plan_id)
    if plan is None or not plan.active:
        raise Invalid("Pick one of the plans.", "planId")
    return plan


def pay_subscription(*, plan_id, period, phone, store=None):
    """Send the M-PESA prompt for a plan. With `store`, paying renews/changes that store's plan."""
    if period not in PERIOD_DAYS:
        raise Invalid("Choose monthly or yearly billing.", "period")
    plan = get_plan(plan_id)
    return payment_records.start_charge(
        kind="subscription",
        prefix="SUB",
        amount=plan.price_for(period),
        phone=phone,
        payee="TOPLINE",
        plan_id=plan.id,
        period=period,
        store_id=store.id if store else None,
    )


def claim_signup_payment(reference, plan_id, mpesa_receipt=None):
    """Sign-up pays first, then creates the store with that payment's reference
    (or, failing that, the M-PESA receipt number the customer was shown)."""
    payment = db.session.get(Payment, reference) if reference else None
    if payment is None and mpesa_receipt:
        payment = db.session.scalar(db.select(Payment).filter_by(provider_ref=mpesa_receipt, kind="subscription"))
    if payment is not None and payment.status != PAID:
        payment_records.refresh(payment)
    if (
        payment is None
        or payment.kind != "subscription"
        or payment.status != PAID
        or payment.claimed
        or payment.store_id is not None
        or payment.plan_id != plan_id
    ):
        raise ApiError("We couldn't match your payment to this sign-up. Pay again or contact support.", 402, "planId")
    payment.claimed = True
    return payment


def apply_subscription_payment(payment):
    """Called once a renewal payment is confirmed."""
    store = db.session.get(Store, payment.store_id)
    activate(store, payment.plan_id, payment.period)
    payment.claimed = True


def activate(store, plan_id, period):
    """Start or extend a paid period. Paying early adds time on top of what's left."""
    now = utcnow()
    start = store.next_billing_at if store.next_billing_at and store.next_billing_at > now else now
    store.plan_id = plan_id
    store.billing_period = period
    store.next_billing_at = start + timedelta(days=PERIOD_DAYS[period])
    store.status = ACTIVE


def sweep(now=None):
    """Run daily (flask billing-sweep): overdue stores go past_due, then suspended after the grace period."""
    now = now or utcnow()
    grace = timedelta(days=current_app.config["GRACE_DAYS"])
    changed = 0
    for store in db.session.scalars(db.select(Store).where(Store.status.in_([ACTIVE, PAST_DUE]))):
        if not store.next_billing_at:
            continue
        if store.status == ACTIVE and store.next_billing_at <= now:
            store.status = PAST_DUE
            changed += 1
        elif store.status == PAST_DUE and store.next_billing_at + grace <= now:
            store.status = SUSPENDED
            changed += 1
    db.session.commit()
    return changed
