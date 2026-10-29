"""Traffic counters and incident tracking (with alerts) for the Topline admin.

Counting is best-effort: a monitoring hiccup must never break a customer's request.
"""
import hashlib
import logging
from datetime import date

from sqlalchemy.exc import SQLAlchemyError

from ..extensions import db
from ..models import Incident, PageViews, RequestStats
from ..models.base import utcnow
from . import alerts

log = logging.getLogger(__name__)
ALERT_AT = (1, 10, 100, 1000, 10000)  # alert on the 1st, 10th, 100th... occurrence


def count_view(page, store_id=None):
    """+1 visit to the main site or to a store page, for today."""
    try:
        today = date.today()
        row = db.session.scalar(db.select(PageViews).filter_by(day=today, store_id=store_id, page=page))
        if row is None:
            db.session.add(PageViews(day=today, store_id=store_id, page=page, views=1))
        else:
            row.views += 1
        db.session.commit()
    except SQLAlchemyError:
        db.session.rollback()


def count_request(status, ms):
    """Hourly API totals: requests, 4xx, 5xx, time spent."""
    try:
        hour = utcnow().replace(minute=0, second=0, microsecond=0)
        row = db.session.get(RequestStats, hour)
        if row is None:
            row = RequestStats(hour=hour, requests=0, client_errors=0, server_errors=0, total_ms=0, slowest_ms=0)
            db.session.add(row)
        row.requests += 1
        row.client_errors += 1 if 400 <= status < 500 else 0
        row.server_errors += 1 if status >= 500 else 0
        row.total_ms += ms
        row.slowest_ms = max(row.slowest_ms, ms)
        db.session.commit()
    except SQLAlchemyError:
        db.session.rollback()


def record_incident(kind, title, detail="", path="", rollback=False):
    """Log a problem. The same problem again only bumps its counter; admins are alerted at 1, 10, 100...

    rollback=True (used by the 500 handler) first abandons whatever the failing request was doing.
    """
    try:
        if rollback:
            db.session.rollback()
        fingerprint = hashlib.sha256(f"{kind}|{title}|{path}".encode()).hexdigest()
        incident = db.session.scalar(db.select(Incident).filter_by(fingerprint=fingerprint))
        now = utcnow()
        if incident is None:
            incident = Incident(fingerprint=fingerprint, kind=kind, title=title[:200], detail=detail[:4000], path=path[:200], count=0, notified_count=0, first_seen=now)
            db.session.add(incident)
        incident.count += 1
        incident.last_seen = now
        incident.detail = detail[:4000] or incident.detail
        reopened = incident.resolved
        incident.resolved = False
        should_alert = incident.count in ALERT_AT or reopened
        if should_alert:
            incident.notified_count += 1
        db.session.commit()
        if should_alert:
            alerts.send(
                f"[Topline] {kind}: {title}",
                f"{title}\nSeen {incident.count} time(s). Path: {path or '-'}\n{detail[:500]}",
            )
        return incident
    except SQLAlchemyError:
        db.session.rollback()
        log.exception("Could not record incident %s", title)
        return None
