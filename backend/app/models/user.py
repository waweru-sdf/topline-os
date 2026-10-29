from datetime import datetime

from sqlalchemy import Boolean, ForeignKey, String, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column, relationship

from ..extensions import db
from .base import UtcDateTime, new_id, utcnow

# Platform-wide roles
VENDOR = "vendor"
PLATFORM_ADMIN = "platform_admin"

# Roles inside one store
OWNER, MANAGER, CASHIER = "owner", "manager", "cashier"
STORE_ROLES = (OWNER, MANAGER, CASHIER)


class User(db.Model):
    __tablename__ = "users"

    id: Mapped[str] = mapped_column(String(32), primary_key=True, default=lambda: new_id("u"))
    name: Mapped[str] = mapped_column(String(120))
    phone: Mapped[str] = mapped_column(String(15), unique=True, index=True)  # 2547XXXXXXXX
    role: Mapped[str] = mapped_column(String(20), default=VENDOR)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(UtcDateTime, default=utcnow)

    memberships: Mapped[list["StoreMember"]] = relationship(back_populates="user", cascade="all, delete-orphan")

    @property
    def is_platform_admin(self):
        return self.role == PLATFORM_ADMIN

    def primary_membership(self):
        return self.memberships[0] if self.memberships else None


class StoreMember(db.Model):
    """Links a user to a store with a role. Owners can later add staff."""

    __tablename__ = "store_members"
    __table_args__ = (UniqueConstraint("store_id", "user_id"),)

    id: Mapped[int] = mapped_column(primary_key=True)
    store_id: Mapped[str] = mapped_column(ForeignKey("stores.id", ondelete="CASCADE"), index=True)
    user_id: Mapped[str] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), index=True)
    role: Mapped[str] = mapped_column(String(20), default=OWNER)
    created_at: Mapped[datetime] = mapped_column(UtcDateTime, default=utcnow)

    user: Mapped[User] = relationship(back_populates="memberships")
    store: Mapped["Store"] = relationship(back_populates="members")  # noqa: F821
