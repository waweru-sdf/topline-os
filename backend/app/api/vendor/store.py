from flask import g

from ...auth.guards import can, require
from ...auth.permissions import MANAGE_SETTINGS, VIEW_COSTS, VIEW_DASHBOARD
from ...serializers import store_private
from ...services import dashboard, stores
from ...utils.request import body
from . import bp


@bp.get("")
def get_store():
    return {**store_private(g.store), "myRole": g.store_role or "platform_admin", "permissions": sorted(g.permissions)}


@bp.patch("")
def update_store():
    require(MANAGE_SETTINGS)
    return store_private(stores.update_profile(g.store, body()))


@bp.get("/dashboard")
def get_dashboard():
    require(VIEW_DASHBOARD)
    return dashboard.build(g.store, show_cost=can(VIEW_COSTS))
