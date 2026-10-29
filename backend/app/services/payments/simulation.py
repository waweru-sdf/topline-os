"""Pretend M-PESA, for development and demos. No money moves.

SIMULATION_APPROVAL=prompt (default): the payment waits until someone answers
the on-screen phone (POST /api/simulator/payments/<ref> approve|decline),
exactly like a customer entering, or not entering, their PIN.

SIMULATION_APPROVAL=auto: approves after SIMULATED_PAYMENT_DELAY_SECONDS (tests).
In both modes, numbers ending in 999 always fail ("not enough balance").
"""
import logging
import secrets

from flask import current_app

from ...models.base import utcnow
from .base import FAILED, PAID, PENDING, ChargeRequest, ChargeResult, PaymentGateway, TransferRequest

log = logging.getLogger(__name__)


def _receipt():
    return "T" + secrets.token_hex(5).upper()[:9]


class SimulatedGateway(PaymentGateway):
    name = "simulation"

    def charge_mpesa(self, request: ChargeRequest) -> ChargeResult:
        log.info("[simulated M-PESA] prompt: pay KES %s, phone %s, ref %s", request.amount, request.phone, request.reference)
        return ChargeResult(PENDING)

    def verify(self, payment) -> ChargeResult:
        if payment.phone.endswith("999"):
            return ChargeResult(FAILED, message="The M-PESA payment failed: not enough balance.")
        if payment.simulated_answer == "decline":
            return ChargeResult(FAILED, message="The payment was cancelled on the phone.")
        if payment.simulated_answer == "approve":
            return ChargeResult(PAID, provider_ref=_receipt())
        if current_app.config["SIMULATION_APPROVAL"] == "auto":
            waited = (utcnow() - payment.created_at).total_seconds()
            if waited >= current_app.config["SIMULATED_PAYMENT_DELAY_SECONDS"]:
                return ChargeResult(PAID, provider_ref=_receipt())
        return ChargeResult(PENDING)

    def transfer(self, request: TransferRequest) -> ChargeResult:
        log.info("[simulated payout] KES %s to %s %s %s", request.amount, request.method, request.number, request.account)
        return ChargeResult(PAID, provider_ref="TRF_" + secrets.token_hex(4).upper())

    def verify_webhook_signature(self, raw_body, signature):
        return True  # nothing to verify in simulation

    def parse_webhook(self, payload):
        # Lets you fake a webhook: {"event": "charge.success", "data": {"reference": "..."}}
        data = payload.get("data") or {}
        event = payload.get("event")
        if event == "charge.success":
            return "charge", data.get("reference"), ChargeResult(PAID, provider_ref=data.get("receipt_number") or _receipt())
        if event == "transfer.success":
            return "transfer", data.get("reference"), ChargeResult(PAID, provider_ref=data.get("transfer_code"))
        return None
