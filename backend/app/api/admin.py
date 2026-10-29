"""Topline platform admin: overview, stores, payments, payouts, site content, system health.

Admins also have full access to any store's shop admin through
/api/vendor/stores/<id>/... (see app/auth/guards.py).
"""
from datetime import timedelta

from flask import Blueprint, request

from ..auth.guards import current_user
from ..errors import Forbidden, Invalid, NotFound
from ..extensions import db
from ..models import Incident, Payment, Payout, Plan, Product, Sale, Store
from ..models.base import utcnow
from ..models.sale import AWAITING_CONFIRMATION, AWAITING_PAYMENT, CANCELLED
from ..models.store import STORE_STATUSES
from ..serializers import payout as payout_json
from ..serializers import plan as plan_json
from ..serializers import store_private
from ..services import analytics, billing, payouts, site
from ..utils.request import body
from ..utils.text import iso

bp = Blueprint("admin", __name__, url_prefix="/admin")


@bp.before_request
def only_platform_admins():
    if request.method == "OPTIONS":  # CORS preflight
        return
    if not current_user().is_platform_admin:
        raise Forbidden("Only Topline admins can open this.")


def _limit(default=100):
    return min(int(request.args.get("limit", default)), 500)


# ---- Overview ----

@bp.get("/overview")
def overview():
    return analytics.overview()


# ---- Stores ----

@bp.get("/stores")
def list_stores():
    since = utcnow() - timedelta(days=30)
    product_counts = dict(db.session.execute(db.select(Product.store_id, db.func.count(Product.id)).group_by(Product.store_id)).all())
    gmv = dict(
        db.session.execute(
            db.select(Sale.store_id, db.func.sum(Sale.total))
            .where(Sale.created_at >= since, Sale.status.notin_([AWAITING_PAYMENT, AWAITING_CONFIRMATION, CANCELLED]))
            .group_by(Sale.store_id)
        ).all()
    )
    stores = db.session.scalars(db.select(Store).order_by(Store.created_at.desc())).all()
    return [
        {**store_private(s), "products": product_counts.get(s.id, 0), "gmv30": int(gmv.get(s.id) or 0),
         "plan": plan_json(s.plan) if s.plan else None}
        for s in stores
    ]


@bp.patch("/stores/<store_id>")
def update_store(store_id):
    """Suspend/reactivate, change plan without payment (e.g. a trial), or extend the paid period."""
    store = db.session.get(Store, store_id)
    if store is None:
        raise NotFound("Store not found.")
    data = body()
    if "status" in data:
        if data["status"] not in STORE_STATUSES:
            raise Invalid("Unknown status.", "status")
        store.status = data["status"]
    if "planId" in data:
        billing.get_plan(data["planId"])
        store.plan_id = data["planId"]
    if "extendDays" in data:
        start = max(store.next_billing_at or utcnow(), utcnow())
        store.next_billing_at = start + timedelta(days=int(data["extendDays"]))
    db.session.commit()
    return store_private(store)


# ---- Money ----

@bp.get("/payments")
def recent_payments():
    rows = db.session.scalars(db.select(Payment).order_by(Payment.created_at.desc()).limit(_limit()))
    names = dict(db.session.execute(db.select(Store.id, Store.name)).all())
    return [
        {"reference": p.reference, "kind": p.kind, "status": p.status, "provider": p.provider, "amount": p.amount,
         "phone": p.phone, "payee": p.payee, "storeId": p.store_id, "storeName": names.get(p.store_id),
         "planId": p.plan_id, "mpesaRef": p.provider_ref, "createdAt": iso(p.created_at), "paidAt": iso(p.paid_at),
         "failureReason": p.failure_reason}
        for p in rows
    ]


@bp.get("/payouts")
def list_payouts():
    query = db.select(Payout).order_by(Payout.created_at.desc()).limit(_limit())
    if request.args.get("status"):
        query = query.where(Payout.status == request.args["status"])
    names = dict(db.session.execute(db.select(Store.id, Store.name)).all())
    return [{**payout_json(p), "storeName": names.get(p.store_id)} for p in db.session.scalars(query)]


@bp.post("/payouts/<int:payout_id>/retry")
def retry_payout(payout_id):
    payout = db.session.get(Payout, payout_id)
    if payout is None:
        raise NotFound("Payout not found.")
    if payout.status == "paid":
        raise Invalid("This payout was already sent.")
    return payout_json(payouts.retry(payout, db.session.get(Store, payout.store_id)))


@bp.get("/plans")
def list_plans():
    return [plan_json(p) for p in db.session.scalars(db.select(Plan).order_by(Plan.sort_order))]


# ---- Site content ----

@bp.put("/site")
def update_site():
    return site.update(body())


# ---- System health ----

@bp.get("/incidents")
def list_incidents():
    show = request.args.get("show", "open")
    query = db.select(Incident).order_by(Incident.last_seen.desc()).limit(_limit())
    if show == "open":
        query = query.where(Incident.resolved.is_(False))
    return [
        {"id": i.id, "kind": i.kind, "title": i.title, "detail": i.detail, "path": i.path, "count": i.count,
         "firstSeen": iso(i.first_seen), "lastSeen": iso(i.last_seen), "resolved": i.resolved}
        for i in db.session.scalars(query)
    ]


@bp.patch("/incidents/<int:incident_id>")
def resolve_incident(incident_id):
    incident = db.session.get(Incident, incident_id)
    if incident is None:
        raise NotFound("Incident not found.")
    incident.resolved = bool(body().get("resolved", True))
    db.session.commit()
    return {"id": incident.id, "resolved": incident.resolved}
