"""Shared column helpers."""
import secrets
from datetime import UTC, datetime

from sqlalchemy import DateTime
from sqlalchemy.types import TypeDecorator


def utcnow():
    return datetime.now(UTC)


def new_id(prefix):
    """Short, URL-safe string ids such as "prd_k3J9xQ2mA1bc"."""
    return f"{prefix}_{secrets.token_urlsafe(9)}"


class UtcDateTime(TypeDecorator):
    """Stores UTC, always returns timezone-aware datetimes (SQLite drops tzinfo)."""

    impl = DateTime(timezone=True)
    cache_ok = True

    def process_bind_param(self, value, dialect):
        if value is None:
            return None
        # Naive values are taken as UTC; aware values (e.g. Nairobi time) are converted.
        return value.replace(tzinfo=UTC) if value.tzinfo is None else value.astimezone(UTC)

    def process_result_value(self, value, dialect):
        if value is not None and value.tzinfo is None:
            value = value.replace(tzinfo=UTC)
        return value
