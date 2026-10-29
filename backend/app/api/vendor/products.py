from flask import g

from ...auth.guards import can, current_user, require
from ...auth.permissions import MANAGE_PRODUCTS, VIEW_COSTS, VIEW_PRODUCTS
from ...catalog import CATEGORIES
from ...extensions import db
from ...models import Product
from ...serializers import product as product_json
from ...services import products as product_service
from ...services import stock
from ...utils import request as req
from ...utils.request import body
from . import bp


@bp.get("/products")
def list_products():
    require(VIEW_PRODUCTS)
    rows = db.session.scalars(db.select(Product).filter_by(store_id=g.store.id).order_by(Product.created_at.desc())).all()
    return {"products": [product_json(p, show_cost=can(VIEW_COSTS)) for p in rows], "categories": CATEGORIES}


@bp.post("/products")
def create_product():
    require(MANAGE_PRODUCTS)
    product = product_service.save_product(g.store, body(), current_user())
    return product_json(product, show_cost=True), 201


@bp.put("/products/<product_id>")
def update_product(product_id):
    require(MANAGE_PRODUCTS)
    product = product_service.get_product(g.store, product_id)
    product = product_service.save_product(g.store, body(), current_user(), product)
    return product_json(product, show_cost=True)


@bp.delete("/products/<product_id>")
def delete_product(product_id):
    require(MANAGE_PRODUCTS)
    product_service.delete_product(g.store, product_id)
    return "", 204


@bp.post("/products/<product_id>/stock")
def adjust_stock(product_id):
    require(MANAGE_PRODUCTS)
    product = product_service.get_product(g.store, product_id)
    delta = req.integer(body(), "delta", required=True, message="Enter how much to add or remove.")
    stock.adjust(product, delta, "adjust", "stock button", current_user().id)
    db.session.commit()
    return product_json(product, show_cost=can(VIEW_COSTS))
