"""Request guards.

    @login_required            any logged-in user
    @platform_admin_required   Topline staff only
    load_store_for_request()   for /vendor/stores/<id>/...: the store's own staff, or a Topline admin
    require(permission)        inside a vendor route, e.g. require(MANAGE_PRODUCTS)
"""
from functools import wraps

from flask import g, request

from ..errors import Forbidden, NotFound, Unauthorized
from ..extensions import db
from ..models import Store, StoreMember, User
from .permissions import permissions_for
from .tokens import read_token


def current_user(optional=False):
    """The user behind the Authorization header (cached on `g`)."""
    if "user" not in g:
        g.user = None
        header = request.headers.get("Authorization", "")
        if header.startswith("Bearer "):
            user_id = read_token(header.removeprefix("Bearer ").strip())
            user = db.session.get(User, user_id) if user_id else None
            if user and user.is_active:
                g.user = user
    if g.user is None and not optional:
        raise Unauthorized("Your session has ended. Log in again.")
    return g.user


def login_required(view):
    @wraps(view)
    def wrapper(*args, **kwargs):
        current_user()
        return view(*args, **kwargs)

    return wrapper


def platform_admin_required(view):
    @wraps(view)
    def wrapper(*args, **kwargs):
        if not current_user().is_platform_admin:
            raise Forbidden("Only Topline admins can do this.")
        return view(*args, **kwargs)

    return wrapper


def load_store_for_request(store_id):
    """Sets g.store, g.store_role and g.permissions, or refuses.

    Shop owners and staff get their own store only. Topline admins get any
    store, so they can open a shop's admin to help the owner.
    """
    user = current_user()
    store = db.session.get(Store, store_id)
    if store is None:
        raise NotFound("That store doesn't exist.")

    membership = None
    if not user.is_platform_admin:
        membership = db.session.scalar(
            db.select(StoreMember).filter_by(store_id=store.id, user_id=user.id)
        )
        if membership is None:
            # Same message as "not found" so store ids can't be probed.
            raise NotFound("That store doesn't exist.")

    g.store = store
    g.store_role = membership.role if membership else None
    g.permissions = permissions_for(g.store_role, user.is_platform_admin)


def can(permission):
    return permission in g.get("permissions", set())


def require(permission):
    if not can(permission):
        raise Forbidden("Your role in this store doesn't allow this. Ask the shop owner.")
