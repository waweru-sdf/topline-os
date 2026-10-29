from datetime import datetime

from sqlalchemy import ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from ..extensions import db
from .base import UtcDateTime, utcnow

# Counter sales (channel "pos") and online orders (channel "online") share a table.
POS, ONLINE = "pos", "online"

# Online order states.
AWAITING_PAYMENT = "awaiting_payment"  # M-PESA prompt sent, stock reserved, shop can't see it yet
AWAITING_CONFIRMATION = "awaiting_confirmation"  # customer paid the shop directly; shop must check
NEW, CONFIRMED, DISPATCHED, COMPLETED, CANCELLED = "new", "confirmed", "dispatched", "completed", "cancelled"
UNPAID_STATES = (AWAITING_PAYMENT,)
PAYMENT_METHODS = ("mpesa", "cash", "bank", "credit")

# How an online order is paid
PAY_PROMPT, PAY_DIRECT = "prompt", "direct"

ORDER_TRANSITIONS = {
    AWAITING_CONFIRMATION: {NEW, CANCELLED},  # shop confirms it received the money, or rejects
    NEW: {CONFIRMED, CANCELLED},
    CONFIRMED: {DISPATCHED, COMPLETED, CANCELLED},
    DISPATCHED: {COMPLETED},
}


class Sale(db.Model):
    __tablename__ = "sales"

    # Integer number shown to people as "S-1234".
    number: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    store_id: Mapped[str] = mapped_column(ForeignKey("stores.id", ondelete="CASCADE"), index=True)
    channel: Mapped[str] = mapped_column(String(10), default=POS, index=True)
    status: Mapped[str] = mapped_column(String(24), default=COMPLETED, index=True)
    method: Mapped[str] = mapped_column(String(10), default="cash")
    payment_mode: Mapped[str | None] = mapped_column(String(10), nullable=True)  # online: prompt | direct

    subtotal: Mapped[int] = mapped_column(Integer, default=0)
    delivery_fee: Mapped[int] = mapped_column(Integer, default=0)
    total: Mapped[int] = mapped_column(Integer, default=0)

    staff_name: Mapped[str | None] = mapped_column(String(120), nullable=True)
    staff_id: Mapped[str | None] = mapped_column(String(32), nullable=True)
    customer_id: Mapped[str | None] = mapped_column(ForeignKey("customers.id", ondelete="SET NULL"), nullable=True)
    customer_name: Mapped[str | None] = mapped_column(String(120), nullable=True)
    customer_phone: Mapped[str | None] = mapped_column(String(15), nullable=True, index=True)

    delivery_type: Mapped[str | None] = mapped_column(String(10), nullable=True)  # pickup | delivery
    delivery_zone: Mapped[str | None] = mapped_column(String(80), nullable=True)
    delivery_address: Mapped[str | None] = mapped_column(String(200), nullable=True)
    delivery_notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    payment_reference: Mapped[str | None] = mapped_column(String(40), nullable=True, index=True)
    mpesa_ref: Mapped[str | None] = mapped_column(String(20), nullable=True)
    created_at: Mapped[datetime] = mapped_column(UtcDateTime, default=utcnow, index=True)
    paid_at: Mapped[datetime | None] = mapped_column(UtcDateTime, nullable=True)

    items: Mapped[list["SaleItem"]] = relationship(back_populates="sale", cascade="all, delete-orphan", lazy="selectin")

    @property
    def code(self):
        return f"S-{self.number}"

    @staticmethod
    def number_from_code(code):
        try:
            return int(str(code).removeprefix("S-"))
        except ValueError:
            return None


class SaleItem(db.Model):
    """A line on a sale. Name, unit, price and cost are copied at the time of sale."""

    __tablename__ = "sale_items"

    id: Mapped[int] = mapped_column(primary_key=True)
    sale_number: Mapped[int] = mapped_column(ForeignKey("sales.number", ondelete="CASCADE"), index=True)
    product_id: Mapped[str | None] = mapped_column(ForeignKey("products.id", ondelete="SET NULL"), nullable=True)
    name: Mapped[str] = mapped_column(String(160))
    unit: Mapped[str] = mapped_column(String(40))
    qty: Mapped[int] = mapped_column(Integer)
    price: Mapped[int] = mapped_column(Integer)
    cost: Mapped[int] = mapped_column(Integer, default=0)

    sale: Mapped[Sale] = relationship(back_populates="items")
