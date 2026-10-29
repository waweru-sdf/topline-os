"""Open endpoints: plans, link availability, health check, HTTPS certificate check."""
from flask import Blueprint, current_app, request

from ..extensions import db
from ..models import Plan, Store
from ..serializers import plan as plan_json
from ..services.stores import check_slug

bp = Blueprint("public", __name__)


@bp.get("/health")
def health():
    db.session.execute(db.text("SELECT 1"))
    return {"ok": True, "payments": current_app.config["PAYMENTS_MODE"], "sms": current_app.config["SMS_MODE"]}


@bp.get("/ping")
def ping():
    """Keep-awake check: answers without touching the database, so a sleeping database stays asleep."""
    return {"ok": True}


@bp.get("/plans")
def list_plans():
    plans = db.session.scalars(db.select(Plan).filter_by(active=True).order_by(Plan.sort_order))
    return [plan_json(p) for p in plans]


@bp.get("/stores/check-slug")
def slug_available():
    return check_slug(request.args.get("slug", ""))


@bp.get("/tls/check")
def tls_check():
    """Asked by Caddy before it gets an HTTPS certificate for a host (on-demand TLS).

    Yes for the main domain, www, and the subdomain of any store that exists; no for anything
    else, so nobody can make the server request certificates for made-up names.
    """
    host = request.args.get("domain", "").lower().strip().rstrip(".")
    root = current_app.config["ROOT_DOMAIN"]
    if host in (root, f"www.{root}"):
        return "", 200
    slug = host.removesuffix(f".{root}") if host.endswith(f".{root}") else None
    if slug and "." not in slug and db.session.scalar(db.select(Store.id).filter_by(slug=slug)):
        return "", 200
    return "", 404
