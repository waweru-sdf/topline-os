from datetime import datetime

from sqlalchemy import JSON, Boolean, ForeignKey, Integer, String, Text, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column

from ..extensions import db
from .base import UtcDateTime, new_id, utcnow

VAT_CLASSES = ("standard", "zero_rated", "exempt")


class Product(db.Model):
    """Something a store sells, priced per unit (bag, 12 m length, metre...)."""

    __tablename__ = "products"
    __table_args__ = (UniqueConstraint("store_id", "slug"),)

    id: Mapped[str] = mapped_column(String(32), primary_key=True, default=lambda: new_id("prd"))
    store_id: Mapped[str] = mapped_column(ForeignKey("stores.id", ondelete="CASCADE"), index=True)
    slug: Mapped[str] = mapped_column(String(60))
    name: Mapped[str] = mapped_column(String(160))
    category_id: Mapped[str] = mapped_column(String(32), index=True)
    unit: Mapped[str] = mapped_column(String(40), default="piece")

    sell_price: Mapped[int] = mapped_column(Integer)  # KES per unit
    buy_price: Mapped[int] = mapped_column(Integer, default=0)  # never shown to customers or cashiers
    stock: Mapped[int] = mapped_column(Integer, default=0)  # may go negative if sold offline
    reorder_at: Mapped[int] = mapped_column(Integer, default=10)

    image: Mapped[str] = mapped_column(String(300), default="")
    description: Mapped[str] = mapped_column(Text, default="")
    alt_units: Mapped[list] = mapped_column(JSON, default=list)  # [{"name": "roll (100 m)", "factor": 100, "price": 11500}]
    vat_class: Mapped[str] = mapped_column(String(12), default="standard")
    published: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(UtcDateTime, default=utcnow)


class StockMovement(db.Model):
    """Every change to stock, so counts can be audited and rebuilt."""

    __tablename__ = "stock_movements"

    id: Mapped[int] = mapped_column(primary_key=True)
    store_id: Mapped[str] = mapped_column(ForeignKey("stores.id", ondelete="CASCADE"), index=True)
    product_id: Mapped[str] = mapped_column(ForeignKey("products.id", ondelete="CASCADE"), index=True)
    delta: Mapped[int] = mapped_column(Integer)
    reason: Mapped[str] = mapped_column(String(20))  # sale, online_order, adjust, receive, return
    reference: Mapped[str] = mapped_column(String(40), default="")
    user_id: Mapped[str | None] = mapped_column(String(32), nullable=True)
    created_at: Mapped[datetime] = mapped_column(UtcDateTime, default=utcnow)
