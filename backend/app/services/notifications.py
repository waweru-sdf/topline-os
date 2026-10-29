"""Messages to shop owners and customers (SMS; simulated until SMS is live).

WhatsApp Business messages can be added here later with the same functions.
"""
import logging

from ..utils.phone import normalize_phone
from .sms import get_sms

log = logging.getLogger(__name__)


def _send(phone, text):
    number = normalize_phone(phone)
    if not number:
        return
    try:
        get_sms().send(number, text)
    except Exception:  # noqa: BLE001  a failed SMS must never fail an order
        log.exception("SMS to %s failed", number)


def new_order_for_shop(store, order):
    owner = store.owner
    lines = ", ".join(f"{i.qty} {i.name}" for i in order.items[:3])
    how = "pay on delivery check needed" if order.status == "awaiting_confirmation" else "paid by M-PESA"
    _send(store.whatsapp or (owner.phone if owner else ""),
          f"New order {order.code} on Topline: {lines}. KSh {order.total:,} ({how}). Open your admin to confirm.")


def order_update_for_customer(store, order):
    messages = {
        "new": f"{store.name}: we've received order {order.code} (KSh {order.total:,}). We'll call you to confirm.",
        "confirmed": f"{store.name}: order {order.code} is confirmed and being prepared.",
        "dispatched": f"{store.name}: order {order.code} is on the way.",
        "completed": f"{store.name}: order {order.code} is complete. Asante!",
        "cancelled": f"{store.name}: order {order.code} was cancelled. Call {store.phone} for help.",
    }
    if order.status in messages and order.customer_phone:
        _send(order.customer_phone, messages[order.status])
