"""Phone + one-time-code login."""
from flask import Blueprint

from ..auth.guards import current_user, login_required
from ..auth.tokens import issue_token
from ..serializers import user as user_json
from ..services import otp
from ..utils.request import body

bp = Blueprint("auth", __name__, url_prefix="/auth")


@bp.post("/otp")
def send_code():
    return otp.request_code(body().get("phone"))


@bp.post("/otp/verify")
def verify_code():
    data = body()
    user = otp.verify_code(data.get("phone"), data.get("code"))
    return {"user": user_json(user, user.primary_membership()), "token": issue_token(user)}


@bp.get("/me")
@login_required
def me():
    user = current_user()
    return {"user": user_json(user, user.primary_membership())}
