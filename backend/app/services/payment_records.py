"""Creating, checking and settling Payment rows, whatever they pay for.

Flow for every M-PESA prompt (plans and orders alike):
  start_charge()  -> Payment "pending", prompt sent for the exact amount
  refresh()       -> polled by the screen the customer is looking at
  record_result() -> called by the Paystack webhook
Settling is idempotent: polling and the webhook may both report the same payment.
"""
import logging
import secrets
from datetime import timedelta

from flask import current_app

from ..errors import ApiError
from ..extensions import db
from ..models import Payment
from ..models.base import utcnow
from ..models.payment import FAILED, PAID, PENDING
from . import monitoring
from .payments import get_gateway
from .payments.base import ChargeRequest, ChargeResult
from .payments.paystack import PaystackError

log = logging.getLogger(__name__)


def new_reference(prefix):
    return f"{prefix}_{secrets.token_hex(8).upper()}"


def start_charge(*, kind, amount, phone, prefix, payee="TOPLINE", **links):
    """Create a pending Payment and send the M-PESA prompt for exactly `amount`."""
    gateway = get_gateway()
    payment = Payment(reference=new_reference(prefix), kind=kind, provider=gateway.name, amount=amount, phone=phone, payee=payee, **links)
    db.session.add(payment)
    db.session.flush()
    try:
        result = gateway.charge_mpesa(
            ChargeRequest(
                reference=payment.reference,
                amount=amount,
                phone=phone,
                email=f"{phone}@{current_app.config['ROOT_DOMAIN']}",
                metadata={"kind": kind, "payee": payee, **{k: v for k, v in links.items() if v is not None}},
            )
        )
    except PaystackError as err:
        db.session.rollback()
        monitoring.record_incident("payment", "M-PESA prompt could not be sent", str(err))
        raise ApiError("We couldn't send the M-PESA prompt. Try again in a minute.", 502, "phone") from err
    _apply(payment, result)
    db.session.commit()
    return payment


def refresh(payment):
    """Ask the provider about a pending payment and record the answer (or time it out)."""
    if payment.status != PENDING:
        return payment
    timeout = timedelta(minutes=current_app.config["PAYMENT_TIMEOUT_MINUTES"])
    try:
        result = get_gateway().verify(payment)
    except PaystackError as err:
        log.warning("verify %s failed: %s", payment.reference, err)
        result = ChargeResult(PENDING)
    if result.status == PENDING and utcnow() - payment.created_at > timeout:
        result = ChargeResult(FAILED, message="The M-PESA prompt wasn't answered in time. Try again.")
    _apply(payment, result)
    db.session.commit()
    return payment


def sweep_pending(older_than_minutes=2):
    """Re-check payments nobody is watching any more (customer closed the page, webhook lost).

    Paid ones complete their order; unanswered ones past PAYMENT_TIMEOUT_MINUTES fail and
    release their stock. Run every few minutes (`flask payments-sweep`).
    """
    cutoff = utcnow() - timedelta(minutes=older_than_minutes)
    stale = db.session.scalars(db.select(Payment).where(Payment.status == PENDING, Payment.created_at < cutoff)).all()
    changed = 0
    for payment in stale:
        try:
            if refresh(payment).status != PENDING:
                changed += 1
        except Exception:  # noqa: BLE001  one bad payment must not stop the rest
            db.session.rollback()
            log.exception("sweep: payment %s", payment.reference)
    return changed


def record_result(reference, result):
    """Webhook: apply a provider result to our payment by reference."""
    payment = db.session.get(Payment, reference)
    if payment is None:
        return None
    if payment.status == PENDING:
        _apply(payment, result)
        db.session.commit()
    return payment


def raise_if_failed(payment):
    if payment.status == FAILED:
        raise ApiError(payment.failure_reason or "The M-PESA payment didn't go through. Try again.", 402, "phone")


def describe(payment):
    """What the waiting screen needs: exact amount, who it's paid to, which phone."""
    return {
        "reference": payment.reference,
        "status": payment.status,
        "amount": payment.amount,
        "payee": payment.payee,
        "phone": payment.phone,
        "mpesaRef": payment.provider_ref,
        "failureReason": payment.failure_reason,
        "simulated": payment.provider == "simulation",
    }


def _apply(payment, result):
    if result.status == PAID and payment.status != PAID:
        payment.status = PAID
        payment.provider_ref = result.provider_ref
        payment.paid_at = utcnow()
        _on_paid(payment)
    elif result.status == FAILED and payment.status == PENDING:
        payment.status = FAILED
        payment.failure_reason = result.message
        _on_failed(payment)


def _on_paid(payment):
    # Imported here to avoid circular imports (these modules start charges themselves).
    from .billing import apply_subscription_payment
    from .orders import mark_order_paid

    if payment.kind == "subscription" and payment.store_id:
        apply_subscription_payment(payment)
    elif payment.kind == "order":
        mark_order_paid(payment)


def _on_failed(payment):
    from .orders import release_unpaid_order

    if payment.kind == "order":
        release_unpaid_order(payment)
