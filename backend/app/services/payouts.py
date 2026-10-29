"""Sending a shop its money after a paid online order.

Customers pay Topline's Paystack account (one M-PESA prompt). Topline keeps
PLATFORM_COMMISSION_PERCENT and transfers the rest to the shop's chosen
destination: till, paybill (+ account), Pochi la Biashara or Send Money.
"""
import math

from flask import current_app

from ..extensions import db
from ..models import Payout
from ..models.base import utcnow
from ..models.payment import FAILED, PAID, PENDING
from ..models.store import PAYBILL, POCHI, SEND_MONEY, TILL
from ..utils.phone import display_phone
from . import monitoring
from .payment_records import new_reference
from .payments import get_gateway
from .payments.base import TransferRequest
from .payments.paystack import PaystackError

METHOD_LABEL = {TILL: "Till", PAYBILL: "Paybill", POCHI: "Pochi la Biashara", SEND_MONEY: "M-PESA"}


def destination_label(store):
    if store.payout_method == PAYBILL:
        return f"Paybill {store.payout_number}, account {store.payout_account}"
    if store.payout_method in (POCHI, SEND_MONEY):
        return f"{METHOD_LABEL[store.payout_method]} {display_phone(store.payout_number)}"
    return f"Till {store.payout_number}"


def pay_shop_for_order(store, sale):
    """Create and send the payout for one paid order. Never raises: failures are recorded for admins."""
    commission = math.floor(sale.total * current_app.config["PLATFORM_COMMISSION_PERCENT"] / 100)
    payout = Payout(
        reference=new_reference("PO"),
        store_id=store.id,
        sale_number=sale.number,
        gross=sale.total,
        commission=commission,
        amount=sale.total - commission,
        method=store.payout_method,
        destination=destination_label(store),
    )
    db.session.add(payout)
    if not store.can_receive_payouts:
        payout.status = FAILED
        payout.failure_reason = "The shop hasn't set where to receive money (Store settings > Getting paid)."
        return payout
    try:
        result = get_gateway().transfer(
            TransferRequest(
                reference=payout.reference,
                amount=payout.amount,
                method=store.payout_method,
                number=store.payout_number,
                account=store.payout_account,
                name=store.payout_name or store.name,
                reason=f"{store.name} order {sale.code}",
            )
        )
    except PaystackError as err:
        payout.status = FAILED
        payout.failure_reason = str(err)[:200]
        monitoring.record_incident("payout", f"Payout to {store.name} failed", str(err))
        return payout
    apply_result(payout, result)
    return payout


def apply_result(payout, result):
    payout.provider_ref = result.provider_ref or payout.provider_ref
    if result.status == PAID:
        payout.status, payout.paid_at = PAID, utcnow()
    elif result.status == FAILED:
        payout.status, payout.failure_reason = FAILED, result.message
        monitoring.record_incident("payout", f"Payout {payout.reference} failed", result.message or "")
    else:
        payout.status = PENDING


def retry(payout, store):
    """Admin "retry" for a failed payout, e.g. after the shop fixes its till number."""
    payout.method = store.payout_method
    payout.destination = destination_label(store)
    try:
        result = get_gateway().transfer(
            TransferRequest(reference=new_reference("PO"), amount=payout.amount, method=store.payout_method, number=store.payout_number,
                            account=store.payout_account, name=store.payout_name or store.name, reason=f"{store.name} payout retry")
        )
    except PaystackError as err:
        payout.failure_reason = str(err)[:200]
        db.session.commit()
        return payout
    apply_result(payout, result)
    db.session.commit()
    return payout
