"""Shop admin API: /api/vendor/stores/<store_id>/...

Every route here runs with g.store set and access already checked:
the store's own owner/staff, or a Topline platform admin ("overall" access).
"""
from flask import Blueprint, g, request

from ...auth.guards import current_user, load_store_for_request
from ...extensions import db
from ...models import Store, StoreMember
from ...serializers import store_private

bp = Blueprint("vendor", __name__, url_prefix="/vendor/stores/<store_id>")
index_bp = Blueprint("vendor_index", __name__, url_prefix="/vendor")


@bp.url_value_preprocessor
def _load_store(endpoint, values):
    store_id = values.pop("store_id")
    if request.method == "OPTIONS":  # CORS preflight carries no token; Flask-CORS answers it
        return
    load_store_for_request(store_id)


@bp.url_defaults
def _keep_store_id(endpoint, values):
    if "store" in g and "store_id" not in values:
        values["store_id"] = g.store.id


@index_bp.get("/stores")
def my_stores():
    """Stores the caller can open: their own, or every store for Topline admins."""
    user = current_user()
    if user.is_platform_admin:
        stores = db.session.scalars(db.select(Store).order_by(Store.name)).all()
        return [{**store_private(s), "myRole": "platform_admin"} for s in stores]
    rows = db.session.execute(
        db.select(Store, StoreMember.role).join(StoreMember).where(StoreMember.user_id == user.id)
    ).all()
    return [{**store_private(s), "myRole": role} for s, role in rows]


# Route modules attach themselves to `bp`.
from . import customers, money, products, quotes, sales, store  # noqa: E402,F401
