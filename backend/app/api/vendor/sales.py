"""Counter sales and online orders (both are "sales")."""
from flask import g, request

from ...auth.guards import can, current_user, require
from ...auth.permissions import MANAGE_ORDERS, SELL, VIEW_COSTS
from ...extensions import db
from ...models import Sale
from ...models.sale import AWAITING_PAYMENT
from ...serializers import sale as sale_json
from ...services import orders, sales
from ...utils.request import body
from . import bp

MAX_LIMIT = 500


@bp.get("/sales")
def list_sales():
    require(MANAGE_ORDERS)
    query = db.select(Sale).where(Sale.store_id == g.store.id, Sale.status != AWAITING_PAYMENT)
    if request.args.get("channel") in ("pos", "online"):
        query = query.where(Sale.channel == request.args["channel"])
    if request.args.get("status"):
        query = query.where(Sale.status == request.args["status"])
    limit = min(int(request.args.get("limit", 200)), MAX_LIMIT)
    rows = db.session.scalars(query.order_by(Sale.created_at.desc()).limit(limit)).all()
    return [sale_json(s, show_cost=can(VIEW_COSTS)) for s in rows]


@bp.post("/sales")
def create_sale():
    require(SELL)
    data = body()
    sale = sales.create_counter_sale(
        g.store, current_user(), items=data.get("items"), method=data.get("method"), customer_id=data.get("customerId")
    )
    return sale_json(sale, show_cost=can(VIEW_COSTS)), 201


@bp.patch("/sales/<sale_id>")
def update_sale_status(sale_id):
    require(MANAGE_ORDERS)
    order = orders.update_status(g.store, sale_id, body().get("status"), current_user())
    return sale_json(order, show_cost=can(VIEW_COSTS))
