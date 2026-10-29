import re

from ..errors import Invalid
from ..extensions import db
from ..models import Store, StoreMember, User
from ..models.user import OWNER, VENDOR
from ..utils import request as req
from ..models.store import DELIVERY_ZONES, PAYBILL, PAYOUT_METHODS, POCHI, SEND_MONEY, TILL
from ..utils.phone import display_phone, normalize_phone
from ..utils.text import RESERVED_SLUGS, slugify
from . import billing
from .delivery import DELIVERY_MODES, clean_zones

HEX_COLOUR = re.compile(r"^#[0-9A-Fa-f]{6}$")


def check_slug(raw):
    slug = slugify(raw)
    if len(slug) < 3:
        return {"slug": slug, "available": False, "reason": "Use at least 3 letters or numbers."}
    if slug in RESERVED_SLUGS:
        return {"slug": slug, "available": False, "reason": "That link is reserved. Try another."}
    taken = db.session.scalar(db.select(Store.id).filter_by(slug=slug)) is not None
    return {"slug": slug, "available": not taken, "reason": "That link is already taken." if taken else None}


def create_store(data):
    """Sign-up: owner + store, activated by the subscription payment made just before."""
    owner = data.get("owner") or {}
    business = data.get("business") or {}
    billing_info = data.get("billing") or {}

    owner_name = req.text(owner, "name", required=True, max_length=120, message="Enter your name.")
    owner_phone = normalize_phone(owner.get("phone"))
    if not owner_phone:
        raise Invalid("Enter a mobile number like 0712 345 678.", "ownerPhone")
    if db.session.scalar(db.select(User.id).filter_by(phone=owner_phone)):
        raise Invalid("This number already has a Topline account. Log in instead.", "ownerPhone")

    name = req.text(business, "name", required=True, max_length=120, message="Enter your shop name.")
    slug_check = check_slug(data.get("slug") or name)
    if not slug_check["available"]:
        raise Invalid(slug_check["reason"], "slug")

    plan = billing.get_plan(data.get("planId"))
    payment = billing.claim_signup_payment(billing_info.get("paymentReference"), plan.id, mpesa_receipt=billing_info.get("lastRef"))
    period = payment.period  # what was actually paid for, not what the client says

    whatsapp = normalize_phone(business.get("whatsapp")) or owner_phone
    store = Store(
        slug=slug_check["slug"],
        name=name,
        tagline=req.text(business, "tagline"),
        county=req.text(business, "county", max_length=40),
        town=req.text(business, "town", max_length=80),
        address=req.text(business, "address"),
        phone=display_phone(owner_phone),
        whatsapp=whatsapp,
        payout_method=TILL,
        payout_number=req.text(business, "till", max_length=20),
        payout_name=name,
        plan_id=plan.id,
    )
    user = User(name=owner_name, phone=owner_phone, role=VENDOR)
    db.session.add_all([store, user])
    db.session.flush()
    membership = StoreMember(store_id=store.id, user_id=user.id, role=OWNER)
    db.session.add(membership)
    if isinstance(business.get("payments"), dict):
        _update_payments(store, business["payments"])  # how the shop receives money
    billing.activate(store, plan.id, period)
    db.session.commit()
    return store, user, membership


# Fields an owner may change from Store settings. Plan, status and billing dates
# are deliberately absent: those only change through payments or Topline admins.
def update_profile(store, data):
    text_fields = {
        "name": 120, "tagline": 200, "county": 40, "town": 80, "address": 200,
        "phone": 20, "hours": 120, "cover": 300, "logo": 300,
    }
    for key, max_length in text_fields.items():
        if key in data:
            value = req.text(data, key, max_length=max_length)
            if key == "name" and not value:
                raise Invalid("Your shop needs a name.", "name")
            setattr(store, key, value)

    if "whatsapp" in data:
        raw = req.text(data, "whatsapp", max_length=20)
        number = normalize_phone(raw) if raw else ""
        if raw and not number:
            raise Invalid("Enter a WhatsApp number like 0712 345 678.", "whatsapp")
        store.whatsapp = number
    if "accent" in data:
        if not HEX_COLOUR.match(str(data["accent"])):
            raise Invalid("Pick a colour.", "accent")
        store.accent = data["accent"].upper()
    if isinstance(data.get("delivery"), dict):
        _update_delivery(store, data["delivery"])
    if isinstance(data.get("payments"), dict):
        _update_payments(store, data["payments"])

    db.session.commit()
    return store


def _update_delivery(store, d):
    if "enabled" in d:
        store.delivery_enabled = bool(d["enabled"])
    if "note" in d:
        store.delivery_note = str(d.get("note") or "")[:1000]
    if "mode" in d:
        if d["mode"] not in DELIVERY_MODES:
            raise Invalid("Choose how delivery is charged.", "deliveryMode")
        store.delivery_mode = d["mode"]
    if "zones" in d:
        store.delivery_zones = clean_zones(d["zones"])
    if "freeOver" in d:
        value = d.get("freeOver")
        store.delivery_free_over = int(value) if value not in (None, "", 0, "0") else None
    if store.delivery_enabled and store.delivery_mode == DELIVERY_ZONES and not store.delivery_zones:
        raise Invalid("Add at least one delivery area with its fee, or choose 'agree the cost with each customer'.", "deliveryZones")


def _update_payments(store, p):
    """How the shop receives money: till, paybill (+ account), Pochi la Biashara or Send Money."""
    method = p.get("method", store.payout_method)
    if method not in PAYOUT_METHODS:
        raise Invalid("Choose how you receive money.", "payoutMethod")
    number = str(p.get("number", store.payout_number) or "").strip()
    account = str(p.get("account", store.payout_account) or "").strip()[:40]
    if method in (TILL, PAYBILL):
        number = re.sub(r"\D", "", number)
        if number and not 5 <= len(number) <= 8:
            raise Invalid("Till and paybill numbers have 5 to 8 digits.", "payoutNumber")
        if method == PAYBILL and number and not account:
            raise Invalid("Enter the paybill account number.", "payoutAccount")
    elif method in (POCHI, SEND_MONEY) and number:
        normalized = normalize_phone(number)
        if not normalized:
            raise Invalid("Enter the M-PESA phone number like 0712 345 678.", "payoutNumber")
        number = normalized
    store.payout_method = method
    store.payout_number = number
    store.payout_account = account if method == PAYBILL else ""
    if "name" in p:
        store.payout_name = str(p.get("name") or "")[:120]
    if "acceptsDirect" in p:
        store.accepts_direct_payment = bool(p["acceptsDirect"])
