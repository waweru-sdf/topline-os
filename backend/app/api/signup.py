"""Opening a new store (after the plan is paid)."""
from flask import Blueprint

from ..auth.tokens import issue_token
from ..serializers import store_private
from ..serializers import user as user_json
from ..services.stores import create_store
from ..utils.request import body

bp = Blueprint("signup", __name__)


@bp.post("/stores")
def open_store():
    store, user, membership = create_store(body())
    return {"store": store_private(store), "user": user_json(user, membership), "token": issue_token(user)}, 201
