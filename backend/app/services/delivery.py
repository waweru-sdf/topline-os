"""Delivery fees.

Each shop chooses one of two ways:
- "zones": the shop lists areas with a fixed fee each (e.g. Kitengela town KSh 300,
  Athi River KSh 600). The customer picks one at checkout and the fee is added to
  the M-PESA prompt, so goods and delivery are paid together. Optional: free
  delivery above a set order value.
- "quote": for bulky or far orders (lorries of sand, steel). The customer pays for
  the goods; the shop calls to agree the delivery cost, paid on delivery.
Pickup from the shop is always free.
"""
import secrets

from ..errors import Invalid
from ..models.store import DELIVERY_QUOTE, DELIVERY_ZONES

MAX_ZONES = 20


def quote_delivery(store, delivery, subtotal):
    """Returns (type, zone_name, fee) for an order, validating the customer's choice."""
    delivery = delivery or {}
    if delivery.get("type") != "delivery":
        return "pickup", None, 0
    if not store.delivery_enabled:
        raise Invalid(f"{store.name} doesn't deliver. Choose pickup.", "deliveryType")
    if not str(delivery.get("address") or "").strip():
        raise Invalid("Enter where to deliver.", "address")
    if store.delivery_mode == DELIVERY_QUOTE:
        return "delivery", "Agreed with the shop", 0

    zone = next((z for z in store.delivery_zones or [] if z.get("id") == delivery.get("zoneId")), None)
    if zone is None:
        raise Invalid("Choose your delivery area.", "zoneId")
    fee = 0 if store.delivery_free_over and subtotal >= store.delivery_free_over else int(zone["fee"])
    return "delivery", zone["name"], fee


def clean_zones(raw):
    """Validate zones from the settings form: [{"name": "...", "fee": 300}, ...]."""
    if not isinstance(raw, list):
        raise Invalid("Delivery areas must be a list.", "deliveryZones")
    zones = []
    for z in raw[:MAX_ZONES]:
        name = str((z or {}).get("name") or "").strip()[:80]
        if not name:
            continue
        try:
            fee = max(0, int(z.get("fee") or 0))
        except (TypeError, ValueError):
            raise Invalid(f"Enter a fee in KSh for {name}.", "deliveryZones") from None
        zones.append({"id": z.get("id") or f"z{secrets.token_hex(3)}", "name": name, "fee": fee})
    return zones


DELIVERY_MODES = (DELIVERY_ZONES, DELIVERY_QUOTE)
