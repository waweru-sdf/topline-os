from ..catalog import CATEGORY_IDS
from ..errors import ApiError, Invalid, NotFound
from ..extensions import db
from ..models import Product
from ..models.product import VAT_CLASSES
from ..utils import request as req
from ..utils.text import slugify
from . import stock


def get_product(store, product_id):
    product = db.session.get(Product, product_id)
    if product is None or product.store_id != store.id:
        raise NotFound("That product was deleted or never existed.")
    return product


def save_product(store, data, user, product=None):
    """Create (product=None) or update a product from the editor form."""
    name = req.text(data, "name", required=True, max_length=160, message="Give the product a name.")
    sell_price = req.integer(data, "sellPrice", required=True, minimum=1, message="Enter a selling price above 0.")
    category_id = data.get("categoryId") or "tools"
    if category_id not in CATEGORY_IDS:
        raise Invalid("Pick a category.", "categoryId")
    vat_class = data.get("vatClass") or "standard"
    if vat_class not in VAT_CLASSES:
        raise Invalid("Pick a VAT class.", "vatClass")

    if product is None:
        _check_plan_limit(store)
        product = Product(store_id=store.id, slug=_unique_slug(store, name))
        db.session.add(product)
        old_stock = 0
    else:
        old_stock = product.stock

    product.name = name
    product.category_id = category_id
    product.unit = req.text(data, "unit", max_length=40, default="piece")
    product.sell_price = sell_price
    product.buy_price = req.integer(data, "buyPrice", minimum=0)
    product.reorder_at = req.integer(data, "reorderAt", minimum=0, default=10)
    product.description = req.text(data, "description", max_length=2000)
    product.image = req.text(data, "image", max_length=300)  # upload URL, sample-photo key, or empty
    product.vat_class = vat_class
    product.published = bool(data.get("published", True))
    if isinstance(data.get("altUnits"), list):
        product.alt_units = data["altUnits"][:10]

    # Stock typed into the form is recorded as an adjustment, so history stays complete.
    new_stock = req.integer(data, "stock", default=old_stock)
    db.session.flush()
    stock.adjust(product, new_stock - old_stock, "adjust", "product form", user.id)
    db.session.commit()
    return product


def delete_product(store, product_id):
    product = get_product(store, product_id)
    db.session.delete(product)  # past sales keep their copied name/price
    db.session.commit()


def _unique_slug(store, name):
    base = slugify(name) or "product"
    slug, n = base, 2
    while db.session.scalar(db.select(Product.id).filter_by(store_id=store.id, slug=slug)):
        slug, n = f"{base}-{n}", n + 1
    return slug


def _check_plan_limit(store):
    limit = (store.plan.limits or {}).get("products") if store.plan else None
    if limit is None:
        return
    count = db.session.scalar(db.select(db.func.count(Product.id)).filter_by(store_id=store.id))
    if count >= limit:
        raise ApiError(f"Your {store.plan.name} plan allows {limit} products. Upgrade to add more.", 402)
