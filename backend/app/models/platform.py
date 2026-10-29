"""Tables for running the platform: site content, uploads, traffic and incidents."""
from datetime import date, datetime

from sqlalchemy import JSON, Boolean, Date, Integer, LargeBinary, String, Text, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column

from ..extensions import db
from .base import UtcDateTime, utcnow


class SiteSetting(db.Model):
    """Editable main-site content (hero picture, headline, contacts...). One row per key."""

    __tablename__ = "site_settings"

    key: Mapped[str] = mapped_column(String(60), primary_key=True)
    value: Mapped[dict | list | str | int | None] = mapped_column(JSON, nullable=True)
    updated_at: Mapped[datetime] = mapped_column(UtcDateTime, default=utcnow, onupdate=utcnow)


class Upload(db.Model):
    """An uploaded picture (product photo, logo, site image)."""

    __tablename__ = "uploads"

    id: Mapped[str] = mapped_column(String(40), primary_key=True)  # also the file name on disk
    store_id: Mapped[str | None] = mapped_column(String(40), nullable=True, index=True)  # None = Topline site
    user_id: Mapped[str] = mapped_column(String(32))
    content_type: Mapped[str] = mapped_column(String(40))
    size: Mapped[int] = mapped_column(Integer)
    # The picture itself when UPLOAD_STORAGE=database (None when it's a file on disk).
    data: Mapped[bytes | None] = mapped_column(LargeBinary, nullable=True, deferred=True)
    created_at: Mapped[datetime] = mapped_column(UtcDateTime, default=utcnow)


class PageViews(db.Model):
    """Daily visit counts: main site (store_id NULL) or one store's pages."""

    __tablename__ = "page_views"
    __table_args__ = (UniqueConstraint("day", "store_id", "page"),)

    id: Mapped[int] = mapped_column(primary_key=True)
    day: Mapped[date] = mapped_column(Date, index=True)
    store_id: Mapped[str | None] = mapped_column(String(40), nullable=True, index=True)
    page: Mapped[str] = mapped_column(String(20))  # landing | store | product | checkout
    views: Mapped[int] = mapped_column(Integer, default=0)


class RequestStats(db.Model):
    """API traffic per hour: how many requests, how many failed, how slow."""

    __tablename__ = "request_stats"

    hour: Mapped[datetime] = mapped_column(UtcDateTime, primary_key=True)
    requests: Mapped[int] = mapped_column(Integer, default=0)
    client_errors: Mapped[int] = mapped_column(Integer, default=0)  # 4xx
    server_errors: Mapped[int] = mapped_column(Integer, default=0)  # 5xx
    total_ms: Mapped[int] = mapped_column(Integer, default=0)
    slowest_ms: Mapped[int] = mapped_column(Integer, default=0)


class Incident(db.Model):
    """Something that broke. Repeats of the same problem bump `count` instead of new rows."""

    __tablename__ = "incidents"

    id: Mapped[int] = mapped_column(primary_key=True)
    fingerprint: Mapped[str] = mapped_column(String(64), unique=True)
    kind: Mapped[str] = mapped_column(String(20))  # server_error | payment | payout | browser
    title: Mapped[str] = mapped_column(String(200))
    detail: Mapped[str] = mapped_column(Text, default="")
    path: Mapped[str] = mapped_column(String(200), default="")
    count: Mapped[int] = mapped_column(Integer, default=1)
    first_seen: Mapped[datetime] = mapped_column(UtcDateTime, default=utcnow)
    last_seen: Mapped[datetime] = mapped_column(UtcDateTime, default=utcnow, index=True)
    resolved: Mapped[bool] = mapped_column(Boolean, default=False, index=True)
    notified_count: Mapped[int] = mapped_column(Integer, default=0)  # alerts sent at 1, 10, 100... occurrences
