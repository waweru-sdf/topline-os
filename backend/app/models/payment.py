from datetime import datetime

from sqlalchemy import Boolean, ForeignKey, Integer, String
from sqlalchemy.orm import Mapped, mapped_column

from ..extensions import db
from .base import UtcDateTime, utcnow

SUBSCRIPTION, ORDER = "subscription", "order"
PENDING, PAID, FAILED = "pending", "paid", "failed"


class Payment(db.Model):
    """Money coming IN: one M-PESA prompt (simulated or Paystack).

    `reference` is ours (sent to Paystack); `provider_ref` is the M-PESA receipt.
    """

    __tablename__ = "payments"

    reference: Mapped[str] = mapped_column(String(40), primary_key=True)
    kind: Mapped[str] = mapped_column(String(15))  # subscription | order
    status: Mapped[str] = mapped_column(String(10), default=PENDING, index=True)
    provider: Mapped[str] = mapped_column(String(15))  # simulation | paystack
    amount: Mapped[int] = mapped_column(Integer)
    phone: Mapped[str] = mapped_column(String(15))
    payee: Mapped[str] = mapped_column(String(120), default="TOPLINE")  # name on the M-PESA prompt

    # Subscription payments
    plan_id: Mapped[str | None] = mapped_column(ForeignKey("plans.id"), nullable=True)
    period: Mapped[str | None] = mapped_column(String(10), nullable=True)
    # Sign-up pays before the store exists; the payment is claimed when the store is created.
    claimed: Mapped[bool] = mapped_column(Boolean, default=False)

    store_id: Mapped[str | None] = mapped_column(ForeignKey("stores.id", ondelete="SET NULL"), nullable=True, index=True)
    sale_number: Mapped[int | None] = mapped_column(ForeignKey("sales.number", ondelete="SET NULL"), nullable=True)

    # Simulation only: what the person pressed on the on-screen phone (approve | decline)
    simulated_answer: Mapped[str | None] = mapped_column(String(10), nullable=True)

    provider_ref: Mapped[str | None] = mapped_column(String(40), nullable=True)
    failure_reason: Mapped[str | None] = mapped_column(String(200), nullable=True)
    created_at: Mapped[datetime] = mapped_column(UtcDateTime, default=utcnow, index=True)
    paid_at: Mapped[datetime | None] = mapped_column(UtcDateTime, nullable=True)


class Payout(db.Model):
    """Money going OUT: an online order's takings sent to the shop's till / paybill / Pochi / phone."""

    __tablename__ = "payouts"

    id: Mapped[int] = mapped_column(primary_key=True)
    reference: Mapped[str] = mapped_column(String(40), unique=True)
    store_id: Mapped[str] = mapped_column(ForeignKey("stores.id", ondelete="CASCADE"), index=True)
    sale_number: Mapped[int | None] = mapped_column(ForeignKey("sales.number", ondelete="SET NULL"), nullable=True)
    gross: Mapped[int] = mapped_column(Integer)  # what the customer paid
    commission: Mapped[int] = mapped_column(Integer, default=0)  # Topline's cut
    amount: Mapped[int] = mapped_column(Integer)  # what the shop receives
    method: Mapped[str] = mapped_column(String(12))
    destination: Mapped[str] = mapped_column(String(80))  # e.g. "Till 5123456" / "Paybill 247247 acc 0712..."
    status: Mapped[str] = mapped_column(String(10), default=PENDING, index=True)  # pending | paid | failed
    provider_ref: Mapped[str | None] = mapped_column(String(60), nullable=True)
    failure_reason: Mapped[str | None] = mapped_column(String(200), nullable=True)
    created_at: Mapped[datetime] = mapped_column(UtcDateTime, default=utcnow, index=True)
    paid_at: Mapped[datetime | None] = mapped_column(UtcDateTime, nullable=True)
