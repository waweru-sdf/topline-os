"""Online orders from a storefront.

1. place_order(): checks and RESERVES stock, adds the delivery fee, then either
   - "prompt": sends one M-PESA prompt for the exact total (goods + delivery), or
   - "direct": the customer pays the shop's own till/paybill/Pochi/number and gives
     the M-PESA code; the shop confirms it received the money.
2. Paid (prompt) or confirmed (direct) -> order is "new" in the shop's admin, the
   shop is notified, and for prompt payments the money is sent to the shop.
3. Declined / unanswered / cancelled -> reserved stock goes back on the shelf.
"""
import re

from ..errors import ApiError, Invalid, NotFound
from ..extensions import db
from ..models import Payment, Product, Sale, SaleItem, Store
from ..models.base import utcnow
from ..models.sale import (
    AWAITING_CONFIRMATION, AWAITING_PAYMENT, CANCELLED, NEW, ONLINE, ORDER_TRANSITIONS, PAY_DIRECT, PAY_PROMPT,
)
from ..utils.phone import normalize_phone
from . import notifications, payment_records, payouts, stock
from .delivery import quote_delivery

MAX_LINES = 50
MPESA_CODE = re.compile(r"^[A-Z0-9]{8,12}$")


def place_order(store, *, customer, items, delivery, payment_mode=PAY_PROMPT, mpesa_code=None):
    if not store.is_open:
        raise ApiError(f"{store.name} isn't taking online orders right now.", 409)
    name = str(customer.get("name") or "").strip()
    phone = normalize_phone(customer.get("phone"))
    if not name:
        raise Invalid("Enter your name.", "name")
    if not phone:
        raise Invalid("Enter an M-PESA number like 0712 345 678.", "phone")
    if payment_mode not in (PAY_PROMPT, PAY_DIRECT):
        raise Invalid("Choose how to pay.", "paymentMode")

    code = None
    if payment_mode == PAY_DIRECT:
        if not (store.accepts_direct_payment and store.can_receive_payouts):
            raise Invalid(f"{store.name} only takes payment by M-PESA prompt.", "paymentMode")
        code = str(mpesa_code or "").strip().upper()
        if not MPESA_CODE.match(code):
            raise Invalid("Enter the M-PESA code from your confirmation SMS, e.g. TJK4H2PQ9X.", "mpesaCode")

    lines, products = _build_lines(store, items)
    subtotal = sum(line.qty * line.price for line in lines)
    delivery_type, zone, fee = quote_delivery(store, delivery, subtotal)
    delivery = delivery or {}

    order = Sale(
        store_id=store.id,
        channel=ONLINE,
        status=AWAITING_PAYMENT if payment_mode == PAY_PROMPT else AWAITING_CONFIRMATION,
        method="mpesa",
        payment_mode=payment_mode,
        customer_name=name[:120],
        customer_phone=phone,
        delivery_type=delivery_type,
        delivery_zone=zone,
        delivery_address=(delivery.get("address") or "")[:200] or None,
        delivery_notes=(delivery.get("notes") or "")[:1000] or None,
        items=lines,
        subtotal=subtotal,
        delivery_fee=fee,
        total=subtotal + fee,
        mpesa_ref=code,
    )
    db.session.add(order)
    db.session.flush()
    try:
        for product, line in zip(products, lines, strict=True):
            stock.reserve(product, line.qty, order.code)
    except ApiError:
        db.session.rollback()
        raise

    if payment_mode == PAY_DIRECT:
        db.session.commit()
        notifications.new_order_for_shop(store, order)
        return order, None

    payment = payment_records.start_charge(
        kind="order", prefix="ORD", amount=order.total, phone=phone, payee=store.name.upper()[:40],
        store_id=store.id, sale_number=order.number,
    )
    order.payment_reference = payment.reference
    db.session.commit()
    return order, payment


def get_order(store, code):
    order = db.session.get(Sale, Sale.number_from_code(code) or -1)
    if order is None or order.store_id != store.id:
        raise NotFound("Order not found.")
    return order


def payment_status(store, code):
    """Polled by the checkout page until M-PESA confirms."""
    order = get_order(store, code)
    payment = db.session.get(Payment, order.payment_reference) if order.payment_reference else None
    if payment:
        payment_records.refresh(payment)
    return order, payment


def mark_order_paid(payment):
    """The order's M-PESA payment is confirmed: it reaches the shop and the shop gets its money."""
    order = db.session.get(Sale, payment.sale_number)
    if order is None or order.status != AWAITING_PAYMENT:
        return
    store = db.session.get(Store, order.store_id)
    order.status = NEW
    order.paid_at = utcnow()
    order.mpesa_ref = payment.provider_ref
    db.session.flush()
    payouts.pay_shop_for_order(store, order)
    notifications.new_order_for_shop(store, order)
    notifications.order_update_for_customer(store, order)


def release_unpaid_order(payment):
    """Prompt declined or unanswered: cancel the order and put its stock back."""
    order = db.session.get(Sale, payment.sale_number)
    if order is None or order.status != AWAITING_PAYMENT:
        return
    order.status = CANCELLED
    _release_stock(order, "payment_failed")


def update_status(store, code, new_status, user=None):
    order = get_order(store, code)
    allowed = ORDER_TRANSITIONS.get(order.status, set())
    if new_status not in allowed:
        raise Invalid(f"An order that is '{order.status}' can't be moved to '{new_status}'.", "status")
    if new_status == NEW and order.status == AWAITING_CONFIRMATION:
        order.paid_at = utcnow()  # shop confirmed the direct M-PESA payment arrived
    if new_status == CANCELLED:
        _release_stock(order, "cancelled")
    order.status = new_status
    db.session.commit()
    notifications.order_update_for_customer(store, order)
    return order


def _release_stock(order, reason):
    for line in order.items:
        product = db.session.get(Product, line.product_id) if line.product_id else None
        if product:
            stock.release(product, line.qty, order.code, reason)


def _build_lines(store, items):
    if not isinstance(items, list) or not items:
        raise Invalid("Your cart is empty.")
    if len(items) > MAX_LINES:
        raise Invalid(f"Orders can have up to {MAX_LINES} different items.")
    lines, products = [], []
    for item in items:
        product = db.session.get(Product, str(item.get("productId")))
        if product is None or product.store_id != store.id or not product.published:
            raise ApiError("An item in your cart is no longer available. Remove it and try again.", 409)
        qty = _qty(item.get("qty"))
        lines.append(SaleItem(product_id=product.id, name=product.name, unit=product.unit, qty=qty, price=product.sell_price, cost=product.buy_price))
        products.append(product)
    return lines, products


def _qty(value):
    try:
        qty = int(value)
    except (TypeError, ValueError):
        qty = 0
    if qty < 1 or qty > 100_000:
        raise Invalid("Quantities must be between 1 and 100,000.", "qty")
    return qty
