"""Pick the payment provider from PAYMENTS_MODE ("simulation" or "paystack")."""
from flask import current_app

from ...errors import ApiError
from .paystack import PaystackGateway
from .simulation import SimulatedGateway

_GATEWAYS = {"simulation": SimulatedGateway, "paystack": PaystackGateway}


def is_simulation():
    return current_app.config["PAYMENTS_MODE"] == "simulation"


def get_gateway():
    mode = current_app.config["PAYMENTS_MODE"]
    if mode == "paystack" and not current_app.config["PAYSTACK_SECRET_KEY"]:
        raise ApiError("Payments aren't set up yet. Contact Topline support.", 503)
    try:
        return _GATEWAYS[mode]()
    except KeyError:
        raise ApiError(f"Unknown PAYMENTS_MODE '{mode}'.", 500) from None
