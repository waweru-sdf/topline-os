"""Money sent to the shop for its paid online orders."""
from flask import g

from ...auth.guards import require
from ...auth.permissions import MANAGE_SETTINGS
from ...extensions import db
from ...models import Payout
from ...serializers import payout as payout_json
from . import bp


@bp.get("/payouts")
def list_payouts():
    require(MANAGE_SETTINGS)  # owners (and Topline admins) only
    rows = db.session.scalars(db.select(Payout).filter_by(store_id=g.store.id).order_by(Payout.created_at.desc()).limit(200))
    return [payout_json(p) for p in rows]
