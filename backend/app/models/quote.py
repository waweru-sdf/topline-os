from datetime import datetime

from sqlalchemy import ForeignKey, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from ..extensions import db
from .base import UtcDateTime, new_id, utcnow


class Quote(db.Model):
    """A customer's "request a quote" from the storefront."""

    __tablename__ = "quotes"

    id: Mapped[str] = mapped_column(String(32), primary_key=True, default=lambda: new_id("q"))
    reference: Mapped[str] = mapped_column(String(16), index=True)
    store_id: Mapped[str] = mapped_column(ForeignKey("stores.id", ondelete="CASCADE"), index=True)
    name: Mapped[str] = mapped_column(String(120))
    phone: Mapped[str] = mapped_column(String(15))
    items: Mapped[str] = mapped_column(Text)
    site: Mapped[str] = mapped_column(String(200), default="")
    status: Mapped[str] = mapped_column(String(20), default="new")  # new, replied, won, lost
    created_at: Mapped[datetime] = mapped_column(UtcDateTime, default=utcnow)
