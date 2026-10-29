"""The on-screen phone used while PAYMENTS_MODE=simulation.

It plays the customer's part of an M-PESA prompt: shows the exact amount and
who is being paid, and lets you "enter the PIN" (approve) or cancel (decline).
Switched off completely once real Paystack keys are in use.
"""
from flask import Blueprint

from ..errors import Invalid, NotFound
from ..extensions import db
from ..models import Payment
from ..models.payment import PENDING
from ..services import payment_records
from ..services.payments import is_simulation
from ..utils.request import body

bp = Blueprint("simulator", __name__, url_prefix="/simulator")


@bp.before_request
def only_in_simulation():
    if not is_simulation():
        raise NotFound("Not available.")


@bp.post("/payments/<reference>")
def answer_prompt(reference):
    payment = db.session.get(Payment, reference)
    if payment is None:
        raise NotFound("Payment not found.")
    data = body()
    action = data.get("action")
    if action not in ("approve", "decline"):
        raise Invalid("Choose approve or decline.", "action")
    if action == "approve" and not (str(data.get("pin") or "").isdigit() and len(str(data["pin"])) == 4):
        raise Invalid("Enter any 4-digit PIN (this is a simulation).", "pin")
    if payment.status == PENDING:
        payment.simulated_answer = action
        db.session.commit()
        payment_records.refresh(payment)
    return payment_records.describe(payment)
