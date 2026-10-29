from ..models.store import PAYBILL, POCHI, SEND_MONEY
from ..utils.phone import display_phone
from ..utils.text import iso


def payments_public(store):
    """How customers can pay this shop directly (shown at checkout and on receipts)."""
    number = store.payout_number
    if store.payout_method in (POCHI, SEND_MONEY) and number:
        number = display_phone(number)
    return {
        "method": store.payout_method,
        "number": number,
        "account": store.payout_account if store.payout_method == PAYBILL else "",
        "name": store.payout_name or store.name,
        "acceptsDirect": bool(store.accepts_direct_payment and store.can_receive_payouts),
        # kept for older screens: the till number when the shop uses a till
        "mpesaTill": store.payout_number if store.payout_method == "till" else "",
    }


def store_public(store):
    """What customers see on the storefront."""
    return {
        "id": store.id,
        "slug": store.slug,
        "name": store.name,
        "tagline": store.tagline,
        "logo": store.logo,
        "county": store.county,
        "town": store.town,
        "address": store.address,
        "phone": store.phone,
        "whatsapp": store.whatsapp,
        "hours": store.hours,
        "accent": store.accent,
        "cover": store.cover,
        "delivery": {
            "enabled": store.delivery_enabled,
            "mode": store.delivery_mode,
            "zones": store.delivery_zones or [],
            "freeOver": store.delivery_free_over,
            "note": store.delivery_note,
        },
        "payments": payments_public(store),
        "status": store.status,
        "owner": _owner(store, with_phone=not store.is_open),  # closed stores show a contact number
    }


def store_private(store):
    """What the store's own staff and Topline admins see."""
    return {
        **store_public(store),
        "payments": {**payments_public(store), "number": store.payout_number, "canReceive": store.can_receive_payouts},
        "planId": store.plan_id,
        "billing": {"period": store.billing_period},
        "customDomain": store.custom_domain,
        "createdAt": iso(store.created_at),
        "nextBillingAt": iso(store.next_billing_at),
        "owner": _owner(store, with_phone=True),
    }


def _owner(store, with_phone):
    owner = store.owner
    if not owner:
        return None
    data = {"name": owner.name}
    if with_phone:
        data["phone"] = display_phone(owner.phone)
    return data
