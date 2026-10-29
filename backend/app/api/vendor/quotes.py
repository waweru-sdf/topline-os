"""Quote requests customers sent from the storefront."""
from flask import g

from ...auth.guards import require
from ...auth.permissions import MANAGE_ORDERS
from ...errors import Invalid, NotFound
from ...extensions import db
from ...models import Quote
from ...serializers import quote as quote_json
from ...utils.request import body
from . import bp

QUOTE_STATUSES = ("new", "replied", "won", "lost")


@bp.get("/quotes")
def list_quotes():
    require(MANAGE_ORDERS)
    rows = db.session.scalars(db.select(Quote).filter_by(store_id=g.store.id).order_by(Quote.created_at.desc()))
    return [quote_json(q) for q in rows]


@bp.patch("/quotes/<quote_id>")
def update_quote(quote_id):
    require(MANAGE_ORDERS)
    quote = db.session.get(Quote, quote_id)
    if quote is None or quote.store_id != g.store.id:
        raise NotFound("Quote not found.")
    status = body().get("status")
    if status not in QUOTE_STATUSES:
        raise Invalid("Pick a status.", "status")
    quote.status = status
    db.session.commit()
    return quote_json(quote)
