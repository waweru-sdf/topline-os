"""Paystack (Kenya): M-PESA prompts for plans and orders, transfers to shops, webhooks.

Ready to use. To switch on, in backend/.env:

    PAYMENTS_MODE=paystack
    PAYSTACK_SECRET_KEY=sk_test_...   (sk_live_... when going live)
    PAYSTACK_PUBLIC_KEY=pk_test_...

and in the Paystack dashboard set the webhook URL to
    https://<your-api-host>/api/webhooks/paystack

Before taking real money, run one small test payment and one test transfer with
your test keys. Paystack's request details are all in this file (see PAYSTACK_*
constants below), so if Paystack changes anything, this is the only file to edit.
Docs: https://paystack.com/docs/payments/payment-channels/  and
      https://paystack.com/docs/transfers/creating-transfer-recipients/
"""
import hashlib
import hmac
import logging

import requests
from flask import current_app

from .base import FAILED, PAID, PENDING, ChargeRequest, ChargeResult, PaymentGateway, TransferRequest

log = logging.getLogger(__name__)

BASE_URL = "https://api.paystack.co"
TIMEOUT = 20  # seconds

# How Paystack identifies M-PESA destinations for transfer recipients in Kenya.
# (type, bank_code) per payout method. Confirm with Paystack support for your account.
PAYSTACK_RECIPIENT = {
    "send_money": ("mobile_money", "MPESA"),  # M-PESA wallet (phone number)
    "pochi": ("mobile_money", "MPESA"),  # Pochi la Biashara is paid like a wallet number
    "till": ("mobile_money_business", "MPTILL"),  # Buy Goods till
    "paybill": ("mobile_money_business", "MPPAYBILL"),  # Paybill + account number
}

# Charge statuses that mean "the customer has the prompt, keep waiting".
_WAITING = {"pending", "pay_offline", "send_otp", "processing", "ongoing"}


class PaystackError(Exception):
    pass


class PaystackGateway(PaymentGateway):
    name = "paystack"

    # ---- HTTP ----

    def _request(self, method, path, **kwargs):
        key = current_app.config["PAYSTACK_SECRET_KEY"]
        try:
            res = requests.request(
                method,
                f"{BASE_URL}{path}",
                headers={"Authorization": f"Bearer {key}", "Content-Type": "application/json"},
                timeout=TIMEOUT,
                **kwargs,
            )
            payload = res.json()
        except (requests.RequestException, ValueError) as err:
            raise PaystackError(f"Couldn't reach Paystack: {err}") from err
        if not payload.get("status"):
            raise PaystackError(payload.get("message") or f"Paystack error {res.status_code}")
        return payload.get("data") or {}

    # ---- Incoming money: M-PESA prompt ----

    def charge_mpesa(self, request: ChargeRequest) -> ChargeResult:
        data = self._request(
            "POST",
            "/charge",
            json={
                "email": request.email,
                "amount": request.amount * 100,  # Paystack uses the smallest unit (cents)
                "currency": "KES",
                "reference": request.reference,
                "mobile_money": {"phone": f"+{request.phone}", "provider": "mpesa"},
                "metadata": request.metadata,
            },
        )
        return self._charge_result(data)

    def verify(self, payment) -> ChargeResult:
        try:
            data = self._request("GET", f"/transaction/verify/{payment.reference}")
        except PaystackError as err:
            # A charge that's still waiting for the PIN may not be a full transaction yet.
            log.info("verify %s: %s", payment.reference, err)
            return ChargeResult(PENDING)
        return self._charge_result(data)

    @staticmethod
    def _charge_result(data):
        status = (data.get("status") or "").lower()
        if status == "success":
            receipt = data.get("receipt_number") or (data.get("authorization") or {}).get("receiver_bank_account_number")
            return ChargeResult(PAID, provider_ref=receipt or str(data.get("id") or ""))
        if status in _WAITING or not status:
            return ChargeResult(PENDING)
        return ChargeResult(FAILED, message=data.get("gateway_response") or "The M-PESA payment didn't go through.")

    # ---- Outgoing money: pay the shop ----

    def transfer(self, request: TransferRequest) -> ChargeResult:
        recipient_type, bank_code = PAYSTACK_RECIPIENT[request.method]
        account_number = request.number if request.method in ("till", "paybill") else f"0{request.number[3:]}"
        recipient = self._request(
            "POST",
            "/transferrecipient",
            json={
                "type": recipient_type,
                "name": request.name or "Topline shop",
                "account_number": account_number,
                "bank_code": bank_code,
                "currency": "KES",
                **({"account_reference": request.account} if request.method == "paybill" else {}),
            },
        )
        data = self._request(
            "POST",
            "/transfer",
            json={
                "source": "balance",
                "amount": request.amount * 100,
                "recipient": recipient.get("recipient_code"),
                "reference": request.reference,
                "reason": request.reason[:100],
                "currency": "KES",
            },
        )
        status = (data.get("status") or "").lower()
        if status == "success":
            return ChargeResult(PAID, provider_ref=data.get("transfer_code"))
        if status in ("failed", "reversed", "rejected"):
            return ChargeResult(FAILED, message=data.get("reason") or "Transfer failed.")
        return ChargeResult(PENDING, provider_ref=data.get("transfer_code"))  # final result arrives by webhook

    # ---- Webhooks ----

    def verify_webhook_signature(self, raw_body: bytes, signature: str) -> bool:
        """Paystack signs webhooks with HMAC-SHA512 of the raw body using the secret key."""
        key = current_app.config["PAYSTACK_SECRET_KEY"]
        if not key or not signature:
            return False
        expected = hmac.new(key.encode(), raw_body, hashlib.sha512).hexdigest()
        return hmac.compare_digest(expected, signature)

    def parse_webhook(self, payload: dict):
        event = payload.get("event", "")
        data = payload.get("data") or {}
        reference = data.get("reference")
        if event == "charge.success":
            return "charge", reference, self._charge_result({**data, "status": "success"})
        if event == "charge.failed":
            return "charge", reference, ChargeResult(FAILED, message=data.get("gateway_response"))
        if event == "transfer.success":
            return "transfer", reference, ChargeResult(PAID, provider_ref=data.get("transfer_code"))
        if event in ("transfer.failed", "transfer.reversed"):
            return "transfer", reference, ChargeResult(FAILED, message=data.get("reason") or event)
        return None
