from sqlalchemy import update

from ..errors import ApiError
from ..extensions import db
from ..models import Product, StockMovement


def adjust(product, delta, reason, reference="", user_id=None):
    """Change a product's stock and log why. Counter sales may take stock below zero
    (the goods are physically there even if the count wasn't updated)."""
    if delta == 0:
        return product
    product.stock += delta
    _log(product, delta, reason, reference, user_id)
    return product


def reserve(product, qty, reference):
    """Take stock for an online order, atomically: if two customers want the last items
    at the same moment, only one succeeds. Raises 409 with how many are left."""
    result = db.session.execute(
        update(Product).where(Product.id == product.id, Product.stock >= qty).values(stock=Product.stock - qty)
    )
    if result.rowcount == 0:
        db.session.refresh(product)
        left = max(product.stock, 0)
        message = (f"{product.name} just sold out." if left == 0
                   else f"Only {left} left of {product.name}. Lower the quantity and try again.")
        raise ApiError(message, 409, "qty")
    db.session.refresh(product)
    _log(product, -qty, "online_order", reference)


def release(product, qty, reference, reason="released"):
    """Put reserved stock back (unpaid, declined or cancelled order)."""
    db.session.execute(update(Product).where(Product.id == product.id).values(stock=Product.stock + qty))
    _log(product, qty, reason, reference)


def _log(product, delta, reason, reference, user_id=None):
    db.session.add(StockMovement(store_id=product.store_id, product_id=product.id, delta=delta, reason=reason, reference=reference, user_id=user_id))
