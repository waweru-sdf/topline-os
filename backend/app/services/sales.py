"""Counter sales recorded at the till."""
from datetime import timedelta

from ..errors import Invalid
from ..extensions import db
from ..models import Customer, Product, Sale, SaleItem
from ..models.base import utcnow
from ..models.sale import COMPLETED, PAYMENT_METHODS, POS
from . import stock

CREDIT_TERM_DAYS = 30


def create_counter_sale(store, user, *, items, method, customer_id=None):
    if method not in PAYMENT_METHODS:
        raise Invalid("Choose how the customer paid.", "method")
    if not isinstance(items, list) or not items:
        raise Invalid("Add at least one item.")

    lines = []
    products = []
    for item in items:
        product = db.session.get(Product, str(item.get("productId")))
        if product is None or product.store_id != store.id:
            raise Invalid("One of the items isn't in this store's products.")
        try:
            qty = int(item.get("qty"))
        except (TypeError, ValueError):
            qty = 0
        if qty < 1:
            raise Invalid(f"Enter a quantity for {product.name}.", "qty")
        lines.append(SaleItem(product_id=product.id, name=product.name, unit=product.unit, qty=qty, price=product.sell_price, cost=product.buy_price))
        products.append(product)
    total = sum(line.qty * line.price for line in lines)

    customer = None
    if customer_id:
        customer = db.session.get(Customer, customer_id)
        if customer is None or customer.store_id != store.id:
            raise Invalid("Pick a customer from this store.", "customer")
    if method == "credit":
        if customer is None:
            raise Invalid("Pick the customer this credit sale is for.", "customer")
        if customer.balance + total > customer.credit_limit:
            raise Invalid(f"{customer.name} would go over their credit limit.", "customer")
        customer.balance += total
        customer.due_at = customer.due_at or utcnow() + timedelta(days=CREDIT_TERM_DAYS)

    sale = Sale(
        store_id=store.id,
        channel=POS,
        status=COMPLETED,
        method=method,
        total=total,
        staff_id=user.id,
        staff_name=user.name,
        customer_id=customer.id if customer else None,
        customer_name=customer.name if customer else None,
        items=lines,
        paid_at=utcnow(),
    )
    db.session.add(sale)
    db.session.flush()
    for product, line in zip(products, lines, strict=True):
        stock.adjust(product, -line.qty, "sale", sale.code, user.id)
    db.session.commit()
    return sale
