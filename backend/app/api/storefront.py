"""Public storefront for one store: catalogue, orders, quotes. No login needed."""
import secrets

from flask import Blueprint

from ..catalog import CATEGORIES
from ..errors import Invalid, NotFound
from ..extensions import db
from ..models import Product, Quote, Store
from ..serializers import product as product_json
from ..serializers import sale as sale_json
from ..serializers import store_public
from ..services import monitoring, orders, payment_records
from ..utils import request as req
from ..utils.phone import normalize_phone
from ..utils.request import body

bp = Blueprint("storefront", __name__, url_prefix="/storefront/<slug>")


def _store(slug):
    store = db.session.scalar(db.select(Store).filter_by(slug=slug))
    if store is None:
        raise NotFound(f'There\'s no store at "{slug}".')
    return store


@bp.get("")
def storefront(slug):
    store = _store(slug)
    monitoring.count_view("store", store.id)
    if not store.is_open:  # the frontend shows a "closed for now" page
        return {"store": store_public(store), "categories": [], "products": []}
    products = db.session.scalars(
        db.select(Product).filter_by(store_id=store.id, published=True).order_by(Product.category_id, Product.name)
    ).all()
    used = {p.category_id for p in products}
    return {
        "store": store_public(store),
        "categories": [c for c in CATEGORIES if c["id"] in used],
        "products": [product_json(p) for p in products],  # never includes buying prices
    }


@bp.get("/products/<product_slug>")
def product_page(slug, product_slug):
    store = _store(slug)
    product = db.session.scalar(db.select(Product).filter_by(store_id=store.id, slug=product_slug, published=True))
    if product is None or not store.is_open:
        raise NotFound("This product is no longer listed.")
    monitoring.count_view("product", store.id)
    category = next((c for c in CATEGORIES if c["id"] == product.category_id), None)
    return {"store": store_public(store), "product": product_json(product), "category": category}


@bp.post("/orders")
def place_order(slug):
    data = body()
    order, payment = orders.place_order(
        _store(slug),
        customer=data.get("customer") or {},
        items=data.get("items"),
        delivery=data.get("delivery"),
        payment_mode=data.get("paymentMode") or "prompt",
        mpesa_code=data.get("mpesaCode"),
    )
    return {**sale_json(order), "payment": payment_records.describe(payment) if payment else None}, 201


@bp.get("/orders/<order_id>/payment")
def order_payment(slug, order_id):
    order, payment = orders.payment_status(_store(slug), order_id)
    status = "paid" if order.status not in ("awaiting_payment", "cancelled") else ("failed" if order.status == "cancelled" else "pending")
    return {
        "status": status,
        "mpesaRef": order.mpesa_ref,
        "failureReason": payment.failure_reason if payment else None,
        "payment": payment_records.describe(payment) if payment else None,
        "order": sale_json(order),
    }


@bp.post("/quotes")
def request_quote(slug):
    store = _store(slug)
    data = body()
    phone = normalize_phone(data.get("phone"))
    if not phone:
        raise Invalid("Enter a phone number we can reach you on.", "phone")
    quote = Quote(
        store_id=store.id,
        reference=f"Q-{secrets.randbelow(90000) + 10000}",
        name=req.text(data, "name", required=True, max_length=120, message="Enter your name."),
        phone=phone,
        items=req.text(data, "items", required=True, max_length=4000, message="List what you need."),
        site=req.text(data, "site"),
    )
    db.session.add(quote)
    db.session.commit()
    return {"ok": True, "reference": quote.reference}, 201
