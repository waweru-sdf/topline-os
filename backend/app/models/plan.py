from sqlalchemy import JSON, Boolean, Integer, String
from sqlalchemy.orm import Mapped, mapped_column

from ..extensions import db


class Plan(db.Model):
    """A subscription tier (Starter, Business, Pro). Prices in whole KES."""

    __tablename__ = "plans"

    id: Mapped[str] = mapped_column(String(32), primary_key=True)
    name: Mapped[str] = mapped_column(String(60))
    price_monthly: Mapped[int] = mapped_column(Integer)
    price_yearly: Mapped[int] = mapped_column(Integer)
    blurb: Mapped[str] = mapped_column(String(200), default="")
    popular: Mapped[bool] = mapped_column(Boolean, default=False)
    limits: Mapped[dict] = mapped_column(JSON, default=dict)  # {"products": 150, "staff": 2, "branches": 1}
    features: Mapped[list] = mapped_column(JSON, default=list)
    sort_order: Mapped[int] = mapped_column(Integer, default=0)
    active: Mapped[bool] = mapped_column(Boolean, default=True)

    def price_for(self, period):
        return self.price_yearly if period == "yearly" else self.price_monthly
