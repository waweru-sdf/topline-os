from datetime import datetime

from sqlalchemy import JSON, Boolean, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from ..extensions import db
from .base import UtcDateTime, new_id, utcnow
from .user import OWNER

# Store lifecycle: draft -> active -> past_due (grace) -> suspended -> active on payment
DRAFT, ACTIVE, PAST_DUE, SUSPENDED = "draft", "active", "past_due", "suspended"
STORE_STATUSES = (DRAFT, ACTIVE, PAST_DUE, SUSPENDED)

# Where a shop receives its money
TILL, PAYBILL, POCHI, SEND_MONEY = "till", "paybill", "pochi", "send_money"
PAYOUT_METHODS = (TILL, PAYBILL, POCHI, SEND_MONEY)

# How delivery is charged: fixed zones paid with the order, or agreed with the shop afterwards
DELIVERY_ZONES, DELIVERY_QUOTE = "zones", "quote"


class Store(db.Model):
    __tablename__ = "stores"

    id: Mapped[str] = mapped_column(String(40), primary_key=True, default=lambda: new_id("st"))
    slug: Mapped[str] = mapped_column(String(40), unique=True, index=True)
    name: Mapped[str] = mapped_column(String(120))
    tagline: Mapped[str] = mapped_column(String(200), default="")
    logo: Mapped[str] = mapped_column(String(300), default="")

    county: Mapped[str] = mapped_column(String(40), default="")
    town: Mapped[str] = mapped_column(String(80), default="")
    address: Mapped[str] = mapped_column(String(200), default="")
    phone: Mapped[str] = mapped_column(String(20), default="")
    whatsapp: Mapped[str] = mapped_column(String(15), default="")
    hours: Mapped[str] = mapped_column(String(120), default="Mon–Sat 8:00–18:00")

    accent: Mapped[str] = mapped_column(String(7), default="#F5A623")
    cover: Mapped[str] = mapped_column(String(300), default="aisle")

    # Receiving money: M-PESA till (Buy Goods), paybill + account, Pochi la Biashara, or Send Money
    payout_method: Mapped[str] = mapped_column(String(12), default=TILL)
    payout_number: Mapped[str] = mapped_column(String(20), default="")
    payout_account: Mapped[str] = mapped_column(String(40), default="")  # paybill account number
    payout_name: Mapped[str] = mapped_column(String(120), default="")  # name M-PESA shows, for customers to check
    # Let customers pay the shop's number themselves and send the M-PESA code (shop confirms)
    accepts_direct_payment: Mapped[bool] = mapped_column(Boolean, default=True)

    # Delivery
    delivery_enabled: Mapped[bool] = mapped_column(Boolean, default=False)
    delivery_mode: Mapped[str] = mapped_column(String(10), default=DELIVERY_ZONES)
    delivery_zones: Mapped[list] = mapped_column(JSON, default=list)  # [{"id": "z1", "name": "Kitengela town", "fee": 300}]
    delivery_free_over: Mapped[int | None] = mapped_column(Integer, nullable=True)  # free delivery above this subtotal
    delivery_note: Mapped[str] = mapped_column(Text, default="")

    custom_domain: Mapped[str | None] = mapped_column(String(120), unique=True, nullable=True)

    plan_id: Mapped[str] = mapped_column(ForeignKey("plans.id"))
    billing_period: Mapped[str] = mapped_column(String(10), default="monthly")
    status: Mapped[str] = mapped_column(String(20), default=DRAFT, index=True)
    next_billing_at: Mapped[datetime | None] = mapped_column(UtcDateTime, nullable=True)
    created_at: Mapped[datetime] = mapped_column(UtcDateTime, default=utcnow)

    plan: Mapped["Plan"] = relationship()  # noqa: F821
    members: Mapped[list["StoreMember"]] = relationship(back_populates="store", cascade="all, delete-orphan")  # noqa: F821

    @property
    def owner(self):
        member = next((m for m in self.members if m.role == OWNER), None)
        return member.user if member else None

    @property
    def is_open(self):
        """Customers can browse and order only from active or grace-period stores."""
        return self.status in (ACTIVE, PAST_DUE)

    @property
    def can_receive_payouts(self):
        if self.payout_method == PAYBILL:
            return bool(self.payout_number and self.payout_account)
        return bool(self.payout_number)
