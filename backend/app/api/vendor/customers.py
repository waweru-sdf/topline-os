from flask import g

from ...auth.guards import current_user, require
from ...auth.permissions import MANAGE_CUSTOMERS, SELL, VIEW_CUSTOMERS
from ...errors import NotFound
from ...extensions import db
from ...models import Customer
from ...serializers import customer as customer_json
from ...services import customers
from ...utils.request import body
from . import bp


@bp.get("/customers")
def list_customers():
    require(VIEW_CUSTOMERS)
    rows = db.session.scalars(db.select(Customer).filter_by(store_id=g.store.id).order_by(Customer.balance.desc(), Customer.name))
    return [customer_json(c) for c in rows]


@bp.post("/customers")
def create_customer():
    require(MANAGE_CUSTOMERS)
    return customer_json(customers.create_customer(g.store, body())), 201


@bp.post("/customers/<customer_id>/payments")
def record_payment(customer_id):
    require(SELL)  # cashiers take credit payments at the counter
    return customer_json(customers.record_payment(g.store, customer_id, body(), current_user()))


@bp.get("/customers/<customer_id>")
def get_customer(customer_id):
    require(VIEW_CUSTOMERS)
    customer = db.session.get(Customer, customer_id)
    if customer is None or customer.store_id != g.store.id:
        raise NotFound("Customer not found.")
    return customer_json(customer)
