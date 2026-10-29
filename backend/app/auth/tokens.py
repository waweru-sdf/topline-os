"""Signed bearer tokens (JWT, HS256)."""
from datetime import timedelta

import jwt
from flask import current_app

from ..models.base import utcnow


def issue_token(user):
    now = utcnow()
    payload = {
        "sub": user.id,
        "role": user.role,
        "iat": now,
        "exp": now + timedelta(hours=current_app.config["JWT_EXPIRES_HOURS"]),
    }
    return jwt.encode(payload, current_app.config["JWT_SECRET"], algorithm="HS256")


def read_token(token):
    """Returns the user id, or None if the token is missing, forged or expired."""
    try:
        payload = jwt.decode(token, current_app.config["JWT_SECRET"], algorithms=["HS256"])
    except jwt.PyJWTError:
        return None
    return payload.get("sub")
